<?php
// api/datacount_pagos.php
// ABM de pagos Datacount. Lee/escribe sobre la tabla `datacount_pagos`
// definida en db/schema.sql. Cada fila representa un documento digitalizado
// (factura recibida, VEP, comprobante de transferencia, etc.) con su
// empresa, proyecto, periodo, monto, moneda y estado de contabilizacion.
// Los adjuntos viven en `datacount_pagos_adjuntos` (metadata) + un binario
// separado servido por DCPAGO_MEDIA_URL.
//   GET    api/datacount_pagos.php          -> listado con filtros (query string)
//   GET    api/datacount_pagos.php?id=N     -> registro individual
//   POST   api/datacount_pagos.php          -> alta (JSON body)
//   PUT    api/datacount_pagos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datacount_pagos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/lib/datacount_comprobante.php';

// Columnas SELECT comunes. `medio___` esta deprecated (ver comentario en el
// schema: "desde 831 hacia atras era id de medio"), pero se mantiene disponible
// para no perder informacion historica cuando se consulta un registro viejo.
const DCP_COLS = "id, uuid, empresa, proyecto, periodo, tipo, emision, cancelacion,
                  razon, cuit, numero, moneda, monto, cotizacion, valor,
                  medio___ AS medio_legacy, billetera, descripcion,
                  comprobante, transaccion, contabilizado, registrador, registrado,
                  remuneracion, clasificado, estado";

// Prefijo S3 donde viven los binarios de los adjuntos (mismo layout que usaba
// el admin legacy `mcDatacountPago::$url`). La URL publica de cada adjunto se
// arma con `s3_public_url()`, que lee `AWS_S3_URL` del .env — nada de
// dominios hardcodeados aca.
const DCPAGO_S3_PREFIX = 'datacount/pagos/';

// Codigo de moneda dolar del catalogo `datacount_pago_moneda` ('P' = Pesos,
// 'D' = Dolares). Declarado aca arriba y no junto a las funciones que lo usan
// porque `const` a nivel de archivo NO es compile-time: se procesa al ejecutar
// la linea, y el dispatcher de mas abajo corre antes (incidente 2026-08-02,
// ver comentario equivalente en datacount_analiticas.php).
const DCPAGO_MONEDA_DOLAR = 'D';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datacount.pagos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneDcPago($pdo, $id);
    } elseif ($method === 'GET') {
        handleListDcPago($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateDcPago($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateDcPago($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteDcPago($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleListDcPago(PDO $pdo, array $q): void {
    $codigo   = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $empresa  = isset($q['empresa']) && $q['empresa'] !== '' ? (int)$q['empresa'] : null;
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $tipo     = trim((string)($q['tipo']    ?? ''));
    $moneda   = trim((string)($q['moneda']  ?? ''));
    $razon    = trim((string)($q['razon']   ?? ''));
    $cuit     = trim((string)($q['cuit']    ?? ''));
    $estado   = trim((string)($q['estado']  ?? ''));
    $search   = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'periodo', 'emision', 'tipo', 'razon', 'cuit',
                     'valor', 'monto', 'registrado', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 'id = :codigo';           $params[':codigo']   = $codigo; }
    if ($empresa  !== null) { $where[] = 'empresa = :empresa';     $params[':empresa']  = $empresa; }
    if ($proyecto !== null) { $where[] = 'proyecto = :proyecto';   $params[':proyecto'] = $proyecto; }
    if ($tipo     !== '')   { $where[] = 'tipo = :tipo';           $params[':tipo']     = $tipo; }
    if ($moneda   !== '')   { $where[] = 'moneda = :moneda';       $params[':moneda']   = $moneda; }
    if ($razon    !== '')   { $where[] = 'razon LIKE :razon';      $params[':razon']    = "%{$razon}%"; }
    if ($cuit     !== '')   { $where[] = 'cuit LIKE :cuit';        $params[':cuit']     = "%{$cuit}%"; }
    if ($estado   !== '')   { $where[] = 'estado = :estado';       $params[':estado']   = $estado; }

    if ($search !== '') {
        $where[] = '(razon LIKE :s1 OR cuit LIKE :s2 OR numero LIKE :s3 OR descripcion LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                AS total,
            COALESCE(SUM(valor), 0) AS importe_total
        FROM datacount_pagos
    ")->fetch();

    $sql = "
        SELECT " . DCP_COLS . "
        FROM datacount_pagos
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'         => (int)($stats['total']         ?? 0),
            'importe_total' => (float)($stats['importe_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOneDcPago(PDO $pdo, int $id): void {
    // JOIN a `datacount_empresas` y `proyectos` para traer los nombres y que
    // el modal de Consulta pueda mostrar texto en lugar de ID (mismo patron
    // que datacount_comprobantes.php::handleGetOneDcComp).
    $stmt = $pdo->prepare("
        SELECT p.id, p.uuid, p.empresa, p.proyecto, p.periodo, p.tipo, p.emision, p.cancelacion,
               p.razon, p.cuit, p.numero, p.moneda, p.monto, p.cotizacion, p.valor,
               p.medio___ AS medio_legacy, p.billetera, p.descripcion,
               p.comprobante, p.transaccion, p.contabilizado, p.registrador, p.registrado,
               p.remuneracion, p.clasificado, p.estado,
               e.nombre  AS empresa_nombre,
               pr.nombre AS proyecto_nombre
        FROM datacount_pagos p
        LEFT JOIN datacount_empresas e  ON e.id  = p.empresa
        LEFT JOIN proyectos           pr ON pr.id = p.proyecto
        WHERE p.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Pago no encontrado', 404);

    // Adjuntos asociados al pago (datacount_pagos_adjuntos). Se listan como
    // metadata (nombre / cargado / tipo / formato) — el binario en si vive
    // aparte y no lo servimos por esta API todavia.
    $stmtA = $pdo->prepare("
        SELECT id, uuid, nombre, cargado, tipo, archivo, formato
        FROM datacount_pagos_adjuntos
        WHERE pago = :id
        ORDER BY cargado ASC, id ASC
    ");
    $stmtA->execute([':id' => $id]);
    $adjuntos = $stmtA->fetchAll();
    foreach ($adjuntos as &$a) {
        // URL publica del binario. El front la usa para embeber el PDF o la
        // imagen directamente sin pasar por el back.
        $a['url'] = !empty($a['archivo'])
            ? s3_public_url(DCPAGO_S3_PREFIX . $a['archivo'])
            : null;
    }
    unset($a);
    $row['adjuntos'] = $adjuntos;

    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function dcpNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

// dcpNormalizarNumero() y dcpClaveComprobante() viven en
// lib/datacount_comprobante.php, compartidas con el endpoint de extraccion con
// IA para que el numero que se muestra, el que se guarda y el que se compara
// sean siempre el mismo.

function dcpNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function dcpNullableDec(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    $s = str_replace(',', '.', trim((string)$v));
    if (!is_numeric($s)) return null;
    return $s;
}

function dcpNullableDate(mixed $v): ?string {
    $s = dcpNullableStr($v);
    if ($s === null) return null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
    return null;
}

function dcpNullableDateTime(mixed $v): ?string {
    $s = dcpNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function dcpSanitizePayload(array $in): array {
    return [
        'empresa'       => dcpNullableInt($in['empresa']      ?? null),
        'proyecto'      => dcpNullableInt($in['proyecto']     ?? null),
        'periodo'       => dcpNullableDate($in['periodo']     ?? null),
        'tipo'          => dcpNullableStr($in['tipo']         ?? null, 3),
        'emision'       => dcpNullableDate($in['emision']     ?? null),
        'cancelacion'   => dcpNullableDate($in['cancelacion'] ?? null),
        'razon'         => dcpNullableStr($in['razon']        ?? null, 255),
        'cuit'          => dcpNullableStr($in['cuit']         ?? null, 20),
        'numero'        => dcpNormalizarNumero(dcpNullableStr($in['numero'] ?? null, 50)),
        'moneda'        => dcpNullableStr($in['moneda']       ?? null, 1),
        'monto'         => dcpNullableDec($in['monto']        ?? null),
        'cotizacion'    => dcpNullableDec($in['cotizacion']   ?? null),
        'valor'         => dcpNullableDec($in['valor']        ?? null),
        'billetera'     => dcpNullableInt($in['billetera']    ?? null),
        'descripcion'   => dcpNullableStr($in['descripcion']  ?? null, 255),
        'comprobante'   => dcpNullableInt($in['comprobante']  ?? null),
        'transaccion'   => dcpNullableInt($in['transaccion']  ?? null),
        'contabilizado' => dcpNullableDateTime($in['contabilizado'] ?? null),
        'remuneracion'  => dcpNullableInt($in['remuneracion'] ?? null),
        'clasificado'   => dcpNullableStr($in['clasificado']  ?? null, 1),
        'estado'        => dcpNullableStr($in['estado']       ?? null, 1),
    ];
}

// ----------------------------------------------------------------------------
// Control de comprobantes duplicados
// ----------------------------------------------------------------------------
//
// Un comprobante fiscal queda identificado por el par (CUIT del EMISOR, numero
// de comprobante): el numero es unico por emisor y punto de venta. Dos ordenes
// de pago con el mismo par son la misma factura cargada dos veces — pasa al
// re-subir el adjunto de un pago que ya estaba registrado, y despues aparece
// como gasto duplicado en las analiticas.
//
// El CUIT se compara por sus digitos: en la tabla conviven "30-70308853-4" y
// "30678814357" para el mismo emisor.
//
// El NUMERO se compara por su forma canonica COMPLETA, con los ceros incluidos
// (dcpClaveComprobante). El relleno no se normaliza a proposito: es parte del
// numero impreso, y el mismo comprobante leido dos veces del mismo PDF trae
// siempre los mismos ceros, asi que la igualdad exacta alcanza para detectar la
// recarga. Recortar ceros, en cambio, fusionaria comprobantes distintos de
// emisores que numeran con rellenos diferentes.
//
// Si falta cualquiera de los dos datos NO se controla nada: hay cientos de
// pagos sin CUIT o sin numero (VEPs, transacciones bancarias, comprobantes del
// exterior) y ahi el par no identifica al comprobante.
//
// La validacion vive en el back a proposito: es el unico punto por el que pasan
// todos los caminos de alta y modificacion.
//
// $ignorarId es el propio pago cuando se esta editando, para que no choque
// contra si mismo.
function dcpVerificarDuplicado(PDO $pdo, ?string $cuit, ?string $numero, int $ignorarId = 0): void {
    $soloDigitos = static fn (?string $v): string => preg_replace('/\D+/', '', (string)$v);
    $c = $soloDigitos($cuit);
    if ($c === '' || $soloDigitos($numero) === '') return;

    // Traemos los pagos del mismo emisor (siempre un puñado) y comparamos el
    // numero en PHP, con las mismas funciones que usa el guardado. Hacerlo en
    // SQL obligaria a duplicar la normalizacion en dialecto MySQL/MariaDB y a
    // mantener las dos versiones sincronizadas.
    $sql = "
        SELECT id, razon, numero
          FROM datacount_pagos
         WHERE REGEXP_REPLACE(COALESCE(cuit, ''), '[^0-9]', '') = :cuit
           AND numero IS NOT NULL AND TRIM(numero) <> ''
    ";
    $params = [':cuit' => $c];
    if ($ignorarId > 0) {
        $sql .= ' AND id <> :ignorar';
        $params[':ignorar'] = $ignorarId;
    }
    $sql .= ' ORDER BY id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Dos criterios, y alcanza con que coincida UNO:
    //   - clave canonica:      "0280 01678510" vs "0280/01678510" vs
    //                          "0280-01678510" (mismo numero, otro separador);
    //   - digitos pegados:     "0005-00001234" vs "000500001234" (el mismo
    //     comprobante cargado con y sin separador — hay 281 numeros viejos
    //     guardados sin guion, y la clave canonica no los empareja porque no
    //     hay forma de saber donde terminaba el punto de venta).
    // Ninguno de los dos cubre al otro, asi que se aplican los dos. Ojo que
    // ninguno toca los ceros: los dos comparan el numero completo.
    $claveNueva  = dcpClaveComprobante($numero);
    $digitoNueva = $soloDigitos($numero);

    $dup = null;
    foreach ($stmt->fetchAll() as $fila) {
        if (dcpClaveComprobante($fila['numero']) === $claveNueva
            || $soloDigitos($fila['numero']) === $digitoNueva) {
            $dup = $fila;
            break;
        }
    }
    if (!$dup) return;

    $razon = trim((string)($dup['razon'] ?? ''));
    jsonError(
        'Comprobante duplicado: la orden de pago #' . (int)$dup['id']
        . ' ya registra el comprobante ' . trim((string)$dup['numero'])
        . ' del CUIT ' . $c
        . ($razon !== '' ? ' (' . $razon . ')' : '') . '.',
        409,
        // Los mismos datos desglosados, para que el front pueda armar el modal
        // de error sin tener que parsear la frase de arriba.
        ['duplicado' => [
            'id'     => (int)$dup['id'],
            'numero' => trim((string)$dup['numero']),
            'cuit'   => $c,
            'razon'  => $razon,
        ]]
    );
}

// ----------------------------------------------------------------------------
// Valorizacion (`cotizacion` + `valor`)
// ----------------------------------------------------------------------------
//
// Invariante del sistema — la misma que sostienen las migraciones
// 20260802_1400_..._backfill_cotizacion_dolar y
// 20260802_1500_..._recalcular_valor_dolar, y de la que depende
// datacount_analiticas.php cuando suma la columna `valor`:
//
//     valor = ROUND(monto * cotizacion, 2)      con cotizacion = 1 en pesos
//
// Se aplica en el alta y en TODA modificacion, sin importar por donde entre el
// pago (modal de carga manual, alta desde adjunto + extraccion IA, o cualquier
// via futura). Vive en el back a proposito: es el unico punto por el que pasan
// todos los caminos, asi que no puede quedar un flujo sin calcular.

// Cotizacion venta del dolar para la emision del pago. Criterio identico al de
// la migracion de backfill: la ultima cotizacion con `fecha <= emision`. Si la
// emision es anterior al inicio de la serie se cae a la mas antigua disponible,
// y si no hay emision se usa la ultima conocida. Devuelve null solo cuando
// `dolarhoy_cotizaciones` no tiene ninguna fila util — en ese caso preferimos
// dejar el pago sin valorizar antes que inventar un numero.
function dcpCotizacionDolar(PDO $pdo, ?string $emision): ?string {
    if ($emision !== null) {
        $stmt = $pdo->prepare("
            SELECT venta FROM dolarhoy_cotizaciones
             WHERE fecha <= :emision AND venta > 0
             ORDER BY fecha DESC LIMIT 1
        ");
        $stmt->execute([':emision' => $emision]);
        $venta = $stmt->fetchColumn();
        if ($venta !== false && $venta !== null) return (string)$venta;

        $stmt = $pdo->prepare("
            SELECT venta FROM dolarhoy_cotizaciones
             WHERE fecha > :emision AND venta > 0
             ORDER BY fecha ASC LIMIT 1
        ");
        $stmt->execute([':emision' => $emision]);
        $venta = $stmt->fetchColumn();
        return ($venta === false || $venta === null) ? null : (string)$venta;
    }

    $venta = $pdo->query("
        SELECT venta FROM dolarhoy_cotizaciones
         WHERE venta > 0 ORDER BY fecha DESC LIMIT 1
    ")->fetchColumn();
    return ($venta === false || $venta === null) ? null : (string)$venta;
}

// Completa `cotizacion` y recalcula `valor` sobre el payload ya sanitizado.
// - Pesos (o moneda sin cargar): cotizacion = 1, valor = monto.
// - Dolares: si el payload no trajo una cotizacion usable se resuelve contra
//   `dolarhoy_cotizaciones`. Una cotizacion cargada a mano — o corregida por la
//   migracion de backfill — se respeta y no se pisa.
// `valor` se recalcula SIEMPRE: es un campo derivado, nunca lo manda el front.
//
// "Usable" excluye el 1 exacto: es el default de los pagos en pesos y queda
// pegado en el form cuando la moneda pasa de pesos a dolares (caso tipico del
// alta desde adjunto, donde el registro nace en pesos y la IA despues detecta
// que el comprobante estaba en dolares). El dolar nunca vale 1 peso, asi que
// tratarlo como "sin cargar" es el mismo criterio de la migracion de backfill.
function dcpValorizar(PDO $pdo, array $p): array {
    if (($p['moneda'] ?? null) !== DCPAGO_MONEDA_DOLAR) {
        $p['cotizacion'] = '1';
    } elseif ($p['cotizacion'] === null || (float)$p['cotizacion'] <= 1) {
        $p['cotizacion'] = dcpCotizacionDolar($pdo, $p['emision']);
    }

    // Sin monto (o sin cotizacion resoluble) no hay valor posible: NULL, no 0.
    if ($p['monto'] === null || $p['cotizacion'] === null) {
        $p['valor'] = null;
    } else {
        $p['valor'] = number_format(
            round((float)$p['monto'] * (float)$p['cotizacion'], 2), 2, '.', ''
        );
    }
    return $p;
}

function handleCreateDcPago(PDO $pdo, array $in): void {
    $p = dcpValorizar($pdo, dcpSanitizePayload($in));
    dcpVerificarDuplicado($pdo, $p['cuit'], $p['numero']);
    $p['uuid']       = substr(bin2hex(random_bytes(8)), 0, 10);
    $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');
    $auth = currentAuth();
    $p['registrador'] = (int)($auth['sub'] ?? 0) ?: null;

    $sql = "
        INSERT INTO datacount_pagos
            (uuid, empresa, proyecto, periodo, tipo, emision, cancelacion,
             razon, cuit, numero, moneda, monto, cotizacion, valor,
             billetera, descripcion, comprobante, transaccion, contabilizado,
             registrador, registrado, remuneracion, clasificado, estado)
        VALUES
            (:uuid, :empresa, :proyecto, :periodo, :tipo, :emision, :cancelacion,
             :razon, :cuit, :numero, :moneda, :monto, :cotizacion, :valor,
             :billetera, :descripcion, :comprobante, :transaccion, :contabilizado,
             :registrador, :registrado, :remuneracion, :clasificado, :estado)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'          => $p['uuid'],
        ':empresa'       => $p['empresa'],
        ':proyecto'      => $p['proyecto'],
        ':periodo'       => $p['periodo'],
        ':tipo'          => $p['tipo'],
        ':emision'       => $p['emision'],
        ':cancelacion'   => $p['cancelacion'],
        ':razon'         => $p['razon'],
        ':cuit'          => $p['cuit'],
        ':numero'        => $p['numero'],
        ':moneda'        => $p['moneda'],
        ':monto'         => $p['monto'],
        ':cotizacion'    => $p['cotizacion'],
        ':valor'         => $p['valor'],
        ':billetera'     => $p['billetera'],
        ':descripcion'   => $p['descripcion'],
        ':comprobante'   => $p['comprobante'],
        ':transaccion'   => $p['transaccion'],
        ':contabilizado' => $p['contabilizado'],
        ':registrador'   => $p['registrador'],
        ':registrado'    => $p['registrado'],
        ':remuneracion'  => $p['remuneracion'],
        ':clasificado'   => $p['clasificado'],
        ':estado'        => $p['estado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdateDcPago(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datacount_pagos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Pago no encontrado', 404);

    // `valor` se recalcula en cada guardado, no solo al alta: si cambia el
    // monto, la moneda o la emision, la conversion a pesos tiene que seguirlos.
    $p = dcpValorizar($pdo, dcpSanitizePayload($in));
    // Tambien al editar: cambiar el CUIT o el numero puede convertir este pago
    // en el duplicado de otro que ya existe.
    dcpVerificarDuplicado($pdo, $p['cuit'], $p['numero'], $id);

    $sql = "
        UPDATE datacount_pagos SET
            empresa       = :empresa,
            proyecto      = :proyecto,
            periodo       = :periodo,
            tipo          = :tipo,
            emision       = :emision,
            cancelacion   = :cancelacion,
            razon         = :razon,
            cuit          = :cuit,
            numero        = :numero,
            moneda        = :moneda,
            monto         = :monto,
            cotizacion    = :cotizacion,
            valor         = :valor,
            billetera     = :billetera,
            descripcion   = :descripcion,
            comprobante   = :comprobante,
            transaccion   = :transaccion,
            contabilizado = :contabilizado,
            remuneracion  = :remuneracion,
            clasificado   = :clasificado,
            estado        = :estado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa'       => $p['empresa'],
        ':proyecto'      => $p['proyecto'],
        ':periodo'       => $p['periodo'],
        ':tipo'          => $p['tipo'],
        ':emision'       => $p['emision'],
        ':cancelacion'   => $p['cancelacion'],
        ':razon'         => $p['razon'],
        ':cuit'          => $p['cuit'],
        ':numero'        => $p['numero'],
        ':moneda'        => $p['moneda'],
        ':monto'         => $p['monto'],
        ':cotizacion'    => $p['cotizacion'],
        ':valor'         => $p['valor'],
        ':billetera'     => $p['billetera'],
        ':descripcion'   => $p['descripcion'],
        ':comprobante'   => $p['comprobante'],
        ':transaccion'   => $p['transaccion'],
        ':contabilizado' => $p['contabilizado'],
        ':remuneracion'  => $p['remuneracion'],
        ':clasificado'   => $p['clasificado'],
        ':estado'        => $p['estado'],
        ':id'            => $id,
    ]);
    jsonOk(['id' => $id]);
}

// Baja de una orden de pago. Antes de tocar la fila se limpian sus adjuntos:
// primero los binarios en S3 y despues las filas de `datacount_pagos_adjuntos`.
//
// El orden importa. Si se borrara el pago primero y algo fallara despues, los
// adjuntos quedarian huerfanos: filas apuntando a un `pago` inexistente y, peor,
// objetos en S3 que ya nadie referencia y que nadie va a encontrar para
// borrar. La tabla no tiene FK con ON DELETE CASCADE, asi que la limpieza es
// responsabilidad de este endpoint.
//
// Un fallo de S3 no aborta la baja (mismo criterio que
// datacount_pagos_adjuntos.php:handleDeleteDcPagoAdjunto): preferimos un objeto
// hueco en el bucket antes que dejar el pago a medio borrar. Los que fallan se
// devuelven en la respuesta y quedan en el error_log para poder rastrearlos.
function handleDeleteDcPago(PDO $pdo, int $id): void {
    $existe = $pdo->prepare('SELECT id FROM datacount_pagos WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetch()) jsonError('Pago no encontrado', 404);

    $stmt = $pdo->prepare(
        'SELECT id, archivo FROM datacount_pagos_adjuntos WHERE pago = :pago'
    );
    $stmt->execute([':pago' => $id]);
    $adjuntos = $stmt->fetchAll();

    $borrados = 0;
    $fallidos = [];
    foreach ($adjuntos as $a) {
        if (empty($a['archivo'])) { $borrados++; continue; }
        try {
            s3_delete_object(DCPAGO_S3_PREFIX . $a['archivo']);
            $borrados++;
        } catch (Throwable $e) {
            $fallidos[] = (string)$a['archivo'];
            error_log('[datacount_pagos] S3 delete fallo para ' . $a['archivo']
                    . ' (pago #' . $id . '): ' . $e->getMessage());
        }
    }

    // Las filas de adjuntos se borran siempre, hayan fallado o no sus binarios:
    // el pago se va, y dejarlas apuntando a un pago inexistente es peor.
    $delAdj = $pdo->prepare('DELETE FROM datacount_pagos_adjuntos WHERE pago = :pago');
    $delAdj->execute([':pago' => $id]);

    $del = $pdo->prepare('DELETE FROM datacount_pagos WHERE id = :id');
    $del->execute([':id' => $id]);
    if ($del->rowCount() === 0) jsonError('Pago no encontrado', 404);

    jsonOk([
        'id'                 => $id,
        'adjuntos'           => count($adjuntos),
        'adjuntos_borrados'  => $borrados,
        'adjuntos_fallidos'  => $fallidos,
    ]);
}
