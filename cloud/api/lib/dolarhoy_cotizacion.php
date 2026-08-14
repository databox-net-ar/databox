<?php
// api/lib/dolarhoy_cotizacion.php
// Scraping de la cotizacion del dolar OFICIAL desde dolarhoy.com y registro
// de la fila del dia en `dolarhoy_cotizaciones`.
//
// Lo consumen dos caminos distintos y por eso vive aca y no en un endpoint:
//   - cloud/api/dolarhoy_realtime.php  (HTTP, cachea 60s, solo lectura)
//   - cloud/jobs/dolarhoy_cotizacion_actualizar.php (cron L-V 07:00, escribe)
//
// Expone:
//   dhScrapearOficial(): array{compra: ?float, venta: ?float, fuente, fecha}
//   dhRegistrarCotizacionDelDia(PDO, ?string $fecha, ?callable $log): array

require_once __DIR__ . '/../db.php';

const DH_URL     = 'https://dolarhoy.com/i/cotizaciones/dolar-oficial';
const DH_TZ      = 'America/Argentina/Buenos_Aires';
const DH_TIMEOUT = 8;

// ----------------------------------------------------------------------------
// Scraping
// ----------------------------------------------------------------------------

// Trae el HTML del widget de dolar oficial y extrae compra y venta.
// Lanza RuntimeException si el sitio no responde o si cambio el markup — el
// caller decide si eso es un 502 (endpoint) o un job en error (cron).
function dhScrapearOficial(): array {
    $ch = curl_init(DH_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, DH_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Databox Panel)');
    $html = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === '') {
        throw new RuntimeException(
            'No se pudo obtener el HTML de dolarhoy.com' . ($err !== '' ? " ({$err})" : '')
        );
    }

    $compra = dhExtraerValor($html, 'Compra');
    $venta  = dhExtraerValor($html, 'Venta');

    if ($compra === null && $venta === null) {
        throw new RuntimeException('No se encontraron valores en la respuesta de dolarhoy.com');
    }

    return [
        'compra' => $compra,
        'venta'  => $venta,
        'fuente' => DH_URL,
        'fecha'  => (new DateTime('now', new DateTimeZone(DH_TZ)))->format('Y-m-d H:i:s'),
    ];
}

function dhExtraerValor(string $html, string $etiqueta): ?float {
    $pattern = '/<p>\$?([\d.,]+)<span>' . preg_quote($etiqueta, '/') . '<\/span><\/p>/i';
    if (!preg_match($pattern, $html, $m)) return null;
    $raw = str_replace(['$', ' '], '', $m[1]);
    // Formato AR: miles con "." y decimales con ",". Sacamos los "." y cambiamos "," por ".".
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? (float)$raw : null;
}

// ----------------------------------------------------------------------------
// Registro en `dolarhoy_cotizaciones`
// ----------------------------------------------------------------------------

// Scrapea y deja la cotizacion de `$fecha` (por defecto hoy en hora Argentina)
// en `dolarhoy_cotizaciones`. Si ya existe una fila para esa fecha la
// actualiza en lugar de insertar una nueva: el ABM y el microservicio v4
// asumen una cotizacion por dia, y una segunda corrida manual del job no debe
// duplicar la serie.
//
// Devuelve ['accion' => 'insert'|'update', 'id' => int, 'fecha' => string,
//           'compra' => ?float, 'venta' => ?float].
function dhRegistrarCotizacionDelDia(PDO $pdo, ?string $fecha = null, ?callable $log = null): array {
    $anotar = $log ?? static fn (string $_m) => null;

    if ($fecha === null || $fecha === '') {
        $fecha = (new DateTime('now', new DateTimeZone(DH_TZ)))->format('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new InvalidArgumentException("Fecha invalida: {$fecha} (esperado YYYY-MM-DD)");
    }

    $anotar('Consultando ' . DH_URL . ' ...');
    $cot = dhScrapearOficial();

    $compra = $cot['compra'];
    $venta  = $cot['venta'];
    $anotar(sprintf(
        'Scrapeado OK: compra=%s venta=%s',
        $compra !== null ? number_format($compra, 2, '.', '') : 'null',
        $venta  !== null ? number_format($venta,  2, '.', '') : 'null'
    ));

    // `venta` es el valor que consume la valorizacion de pagos en dolares
    // (dcpCotizacionDolar en cloud/api/datacount_pagos.php filtra por
    // `venta > 0`). Sin venta la fila no sirve para nada, asi que cortamos
    // antes de escribir un registro inutil.
    if ($venta === null || $venta <= 0) {
        throw new RuntimeException('dolarhoy.com no devolvio un precio de venta usable');
    }

    $compraSql = $compra !== null ? number_format($compra, 2, '.', '') : null;
    $ventaSql  = number_format($venta, 2, '.', '');

    $st = $pdo->prepare('
        SELECT id FROM dolarhoy_cotizaciones
         WHERE fecha = :fecha
         ORDER BY id DESC LIMIT 1
    ');
    $st->execute([':fecha' => $fecha]);
    $existente = $st->fetchColumn();

    if ($existente !== false && $existente !== null) {
        $up = $pdo->prepare('
            UPDATE dolarhoy_cotizaciones
               SET compra = :compra, venta = :venta
             WHERE id = :id
        ');
        $up->execute([':compra' => $compraSql, ':venta' => $ventaSql, ':id' => (int)$existente]);
        $anotar("Ya existia la cotizacion del {$fecha} (#{$existente}) - actualizada.");
        return [
            'accion' => 'update',
            'id'     => (int)$existente,
            'fecha'  => $fecha,
            'compra' => $compra,
            'venta'  => $venta,
        ];
    }

    $ins = $pdo->prepare('
        INSERT INTO dolarhoy_cotizaciones (fecha, compra, venta)
        VALUES (:fecha, :compra, :venta)
    ');
    $ins->execute([':fecha' => $fecha, ':compra' => $compraSql, ':venta' => $ventaSql]);
    $id = (int)$pdo->lastInsertId();
    $anotar("Cotizacion del {$fecha} registrada (#{$id}).");

    return [
        'accion' => 'insert',
        'id'     => $id,
        'fecha'  => $fecha,
        'compra' => $compra,
        'venta'  => $venta,
    ];
}
