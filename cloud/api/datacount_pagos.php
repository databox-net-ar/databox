<?php
// api/datacount_pagos.php
// ABM de pagos Datacount. Lee/escribe sobre la tabla `datacount_pagos`
// definida en db/schema.sql. Cada fila representa un documento digitalizado
// (factura recibida, VEP, comprobante de transferencia, etc.) con su
// empresa, proyecto, periodo, monto, moneda y estado de contabilizacion.
// Los adjuntos viven en `datacount_pagos_adjuntos` (metadata) + un binario
// separado servido por DCPAGO_MEDIA_URL.
//   GET    api/datacount_pagos.php                    -> listado con filtros (query string)
//   GET    api/datacount_pagos.php?id=N               -> registro individual
//   POST   api/datacount_pagos.php                    -> alta (JSON body)
//   PUT    api/datacount_pagos.php?id=N               -> modificacion (JSON body)
//   PUT    api/datacount_pagos.php?id=N&action=estado -> solo contabilizacion (JSON body)
//   PUT    api/datacount_pagos.php?id=N&action=periodo -> solo periodo (JSON body)
//   DELETE api/datacount_pagos.php?id=N               -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/lib/datacount_comprobante.php';

// Columnas SELECT comunes. `medio___` esta deprecated (ver comentario en el
// schema: "desde 831 hacia atras era id de medio"), pero se mantiene disponible
// para no perder informacion historica cuando se consulta un registro viejo.
// Van calificadas con `p.` porque el listado JOINea `datacount_empresas`, que
// comparte los nombres `id`, `razon` y `cuit` con `datacount_pagos`.
const DCP_COLS = "p.id, p.uuid, p.empresa, p.proyecto, p.periodo, p.tipo, p.emision, p.cancelacion,
                  p.razon, p.cuit, p.numero, p.moneda, p.monto, p.cotizacion, p.valor,
                  p.medio___ AS medio_legacy, p.billetera, p.descripcion,
                  p.comprobante, p.transaccion, p.contabilizado, p.registrador, p.registrado,
                  p.remuneracion, p.clasificado, p.estado";

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

// Estados de contabilizacion segun el catalogo `estados` (campo =
// 'datacount_pago_estado'): '1' Pendiente, '2' Contabilizado. Son los dos
// unicos valores que acepta `PUT ?action=estado` — el resto del catalogo
// (ej. '0' Descartado) sigue cargandose por el modal de Edicion.
const DCPAGO_ESTADO_PENDIENTE     = '1';
const DCPAGO_ESTADO_CONTABILIZADO = '2';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datacount.pagos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = trim((string)($_GET['action'] ?? ''));

    if ($method === 'GET' && $id > 0) {
        handleGetOneDcPago($pdo, $id);
    } elseif ($method === 'GET') {
        handleListDcPago($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateDcPago($pdo, readJsonBody());
    } elseif ($method === 'PUT' && $action === 'estado') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleCambiarEstadoDcPago($pdo, $id, readJsonBody());
    } elseif ($method === 'PUT' && $action === 'periodo') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleCambiarPeriodoDcPago($pdo, $id, readJsonBody());
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

    // `periodo` llega como mes calendario (YYYY-MM, el formato que emite el
    // <input type="month"> del modal de filtros) y en la tabla es un DATE, asi
    // que se resuelve como rango [primer dia del mes, primer dia del siguiente).
    // Fechas invalidas se ignoran en lugar de romper el listado.
    $perRango = dcpRangoMes($q['periodo'] ?? null);
    // Rango de fecha de emision. Cada extremo es opcional: se puede filtrar
    // solo desde, solo hasta, o los dos.
    $emiDesde = dcpNullableDate($q['emi_desde'] ?? null);
    $emiHasta = dcpNullableDate($q['emi_hasta'] ?? null);

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

    if ($codigo   !== null) { $where[] = 'p.id = :codigo';         $params[':codigo']   = $codigo; }
    if ($empresa  !== null) { $where[] = 'p.empresa = :empresa';   $params[':empresa']  = $empresa; }
    if ($proyecto !== null) { $where[] = 'p.proyecto = :proyecto'; $params[':proyecto'] = $proyecto; }
    if ($tipo     !== '')   { $where[] = 'p.tipo = :tipo';         $params[':tipo']     = $tipo; }
    if ($moneda   !== '')   { $where[] = 'p.moneda = :moneda';     $params[':moneda']   = $moneda; }
    if ($razon    !== '')   { $where[] = 'p.razon LIKE :razon';    $params[':razon']    = "%{$razon}%"; }
    if ($cuit     !== '')   { $where[] = 'p.cuit LIKE :cuit';      $params[':cuit']     = "%{$cuit}%"; }
    if ($estado   !== '')   { $where[] = 'p.estado = :estado';     $params[':estado']   = $estado; }

    // Mes cerrado por rango en vez de DATE_FORMAT(periodo,'%Y-%m') = ... para
    // que la comparacion siga siendo sobre la columna desnuda (sargable).
    if ($perRango !== null) {
        $where[] = 'p.periodo >= :per_ini AND p.periodo < :per_fin';
        $params[':per_ini'] = $perRango[0];
        $params[':per_fin'] = $perRango[1];
    }
    if ($emiDesde !== null) { $where[] = 'p.emision >= :emi_desde'; $params[':emi_desde'] = $emiDesde; }
    if ($emiHasta !== null) { $where[] = 'p.emision <= :emi_hasta'; $params[':emi_hasta'] = $emiHasta; }

    if ($search !== '') {
        $where[] = '(p.razon LIKE :s1 OR p.cuit LIKE :s2 OR p.numero LIKE :s3 OR p.descripcion LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Las tarjetas de arriba del listado (cantidad de resultados y suma de la
    // columna Valor) las calcula el front sobre `items`, para que reflejen
    // exactamente lo que se ve en pantalla —filtros y LIMIT incluidos—. Por eso
    // este endpoint ya no devuelve un bloque `stats` global.

    // `empresa_presentado_iva` es el ultimo periodo de IVA presentado ante ARCA
    // por la empresa del pago (`datacount_empresas.presentado_iva`, un periodo
    // MES/ANIO guardado como date con dia 01; NULL = nunca presentado). Viaja
    // por fila y no una sola vez por empresa porque el listado no garantiza una
    // unica empresa: el filtro del toolbar puede venir vacio. Con el LEFT JOIN
    // un pago sin empresa —o con una empresa borrada— sigue apareciendo, con el
    // campo en NULL.
    $sql = "
        SELECT " . DCP_COLS . ",
               e.presentado_iva AS empresa_presentado_iva
        FROM datacount_pagos p
        LEFT JOIN datacount_empresas e ON e.id = p.empresa
        {$sqlWhere}
        ORDER BY p.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
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

// Traduce un mes calendario a los dos extremos de su rango de fechas:
// devuelve [primer dia del mes, primer dia del mes siguiente], pensado para un
// `>= inicio AND < fin` (fin EXCLUSIVO, por eso no es LAST_DAY). Acepta tanto
// "YYYY-MM" (lo que manda el <input type="month"> del filtro) como un
// "YYYY-MM-DD" completo, del que solo mira el mes. Devuelve null si no hay
// valor o si el mes no existe — el filtro simplemente no se aplica.
function dcpRangoMes(mixed $v): ?array {
    $s = dcpNullableStr($v);
    if ($s === null || !preg_match('/^(\d{4})-(\d{2})/', $s, $m)) return null;
    $anio = (int)$m[1];
    $mes  = (int)$m[2];
    if ($mes < 1 || $mes > 12) return null;
    $ini = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = $mes === 12
        ? sprintf('%04d-01-01', $anio + 1)
        : sprintf('%04d-%02d-01', $anio, $mes + 1);
    return [$ini, $fin];
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
    // Toda orden de pago nace Pendiente. El default vive aca y no en el modal
    // porque el alta entra por dos caminos (carga manual y alta desde adjunto +
    // extraccion con IA) y ninguno de los dos manda `estado`: el form solo tiene
    // un hidden que arrastra el valor existente al editar. Sin esto la fila
    // quedaba con estado NULL, fuera de los filtros y sin badge en el listado.
    if ($p['estado'] === null) $p['estado'] = DCPAGO_ESTADO_PENDIENTE;
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

// Cambio de estado "rapido" entre Pendiente y Contabilizado. Lo usa el boton
// del modal de Consulta del listado, que alterna entre los dos valores sin
// abrir el formulario de Edicion — de ahi que este handler NO toque ningun otro
// campo (ni siquiera revalorice: monto, moneda y emision no cambian).
//
// `contabilizado` es el sello de CUANDO se contabilizo el pago, asi que viaja
// pegado al estado: se estampa al pasar a Contabilizado y se limpia al volver a
// Pendiente. Dejar la fecha vieja en un pago que volvio a Pendiente seria un
// dato mintiendo, y el legacy la usa como marca de "esto ya esta asentado".
function handleCambiarEstadoDcPago(PDO $pdo, int $id, array $in): void {
    $nuevo = isset($in['estado']) ? trim((string)$in['estado']) : '';
    if (!in_array($nuevo, [DCPAGO_ESTADO_PENDIENTE, DCPAGO_ESTADO_CONTABILIZADO], true)) {
        jsonError("Estado destino invalido: se espera '"
            . DCPAGO_ESTADO_PENDIENTE . "' (pendiente) o '"
            . DCPAGO_ESTADO_CONTABILIZADO . "' (contabilizado)", 400);
    }

    $exists = $pdo->prepare('SELECT id FROM datacount_pagos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Pago no encontrado', 404);

    $contabilizado = $nuevo === DCPAGO_ESTADO_CONTABILIZADO
        ? (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('Y-m-d H:i:s')
        : null;

    $stmt = $pdo->prepare("
        UPDATE datacount_pagos
           SET estado = :estado, contabilizado = :contabilizado
         WHERE id = :id
    ");
    $stmt->execute([
        ':estado'        => $nuevo,
        ':contabilizado' => $contabilizado,
        ':id'            => $id,
    ]);

    jsonOk([
        'id'            => $id,
        'estado'        => $nuevo,
        'contabilizado' => $contabilizado,
    ]);
}

// Cambio "rapido" del periodo de imputacion. Lo usa el icono de alerta de la
// columna Periodo del listado, que abre un modal con el selector de mes en
// lugar de mandar al operador al formulario de Edicion completo — de ahi que
// este handler NO toque ningun otro campo.
//
// Es un PUT parcial a proposito: el PUT completo reescribe TODAS las columnas
// con lo que venga en el body, asi que mandarle solo `periodo` blanquearia el
// resto del pago.
//
// `cotizacion` y `valor` NO se recalculan, y no es un olvido: la valorizacion
// depende de monto, moneda y emision (ver dcpValorizar), y ninguno de los tres
// cambia por mover el periodo. El periodo es imputacion contable, no un
// insumo del importe.
//
// Solo se permite sobre pagos PENDIENTES, la misma condicion con la que el
// listado enciende el icono. Sobre un pago ya Contabilizado el asiento se hizo
// con el periodo viejo, asi que moverlo por la via rapida desalinearia la
// contabilidad sin dejar rastro; para ese caso queda el formulario de Edicion,
// que es un cambio deliberado y completo.
function handleCambiarPeriodoDcPago(PDO $pdo, int $id, array $in): void {
    // Se acepta tanto "YYYY-MM" (lo que emite el <input type="month"> del
    // modal) como una fecha completa. dcpRangoMes() ya resuelve las dos formas
    // y su primer extremo ES el dia 01 del mes, que es justo como el schema
    // guarda los periodos.
    $rango = dcpRangoMes($in['periodo'] ?? null);
    if ($rango === null) {
        jsonError('Periodo invalido: se espera un mes con formato YYYY-MM', 400);
    }
    $periodo = $rango[0];

    $stmt = $pdo->prepare('SELECT estado FROM datacount_pagos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Pago no encontrado', 404);

    if ((string)($row['estado'] ?? '') !== DCPAGO_ESTADO_PENDIENTE) {
        jsonError(
            'Solo se puede corregir el periodo de una orden de pago pendiente. '
            . 'Para modificar un pago ya contabilizado use el formulario de Edicion.',
            409
        );
    }

    $upd = $pdo->prepare('UPDATE datacount_pagos SET periodo = :periodo WHERE id = :id');
    $upd->execute([':periodo' => $periodo, ':id' => $id]);

    jsonOk(['id' => $id, 'periodo' => $periodo]);
}

// Baja de una orden de pago, en tres pasos y en este orden:
//   1. se borran del bucket TODOS los binarios de sus adjuntos;
//   2. recien ahi se borran las filas de `datacount_pagos_adjuntos`;
//   3. y por ultimo la fila de `datacount_pagos`.
//
// El paso 1 es una PRECONDICION, no un "mejor esfuerzo": si aunque sea un objeto
// no logra salir del bucket se aborta la baja entera y la base queda intacta.
// La fila de adjuntos es el unico registro de que ese objeto existe — su columna
// `archivo` es la que arma la key S3 — asi que borrarla con el binario todavia
// arriba deja basura que ya nadie puede encontrar ni nombrar para limpiar. Con
// el abort, en cambio, el pago sigue completo y el operador puede reintentar.
//
// La tabla no tiene FK con ON DELETE CASCADE, asi que toda esta limpieza es
// responsabilidad del endpoint.
function handleDeleteDcPago(PDO $pdo, int $id): void {
    $existe = $pdo->prepare('SELECT id FROM datacount_pagos WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetch()) jsonError('Pago no encontrado', 404);

    $stmt = $pdo->prepare(
        'SELECT id, archivo FROM datacount_pagos_adjuntos WHERE pago = :pago'
    );
    $stmt->execute([':pago' => $id]);
    $adjuntos = $stmt->fetchAll();

    // ---- Paso 1: vaciar el bucket ----
    $borrados = 0;
    $fallidos = [];
    foreach ($adjuntos as $a) {
        // Fila sin binario (alta a medio camino): no hay nada que borrar en S3.
        if (empty($a['archivo'])) continue;
        try {
            $res    = s3_delete_object(DCPAGO_S3_PREFIX . $a['archivo']);
            $status = (int)($res['status'] ?? 0);
            // s3_request() solo tira excepcion si falla el transporte: un 403
            // AccessDenied o un 5xx vuelven como respuesta normal. Sin este
            // chequeo un error de permisos contaba como borrado y el objeto se
            // quedaba en el bucket para siempre.
            // El DELETE de S3 es idempotente: 204 tanto si borro el objeto como
            // si la key no existia. Los dos casos son "ya no esta en el bucket",
            // que es la condicion que nos importa.
            if ($status !== 204 && $status !== 200) {
                throw new RuntimeException('S3 respondio HTTP ' . $status);
            }
            $borrados++;
        } catch (Throwable $e) {
            $fallidos[] = (string)$a['archivo'];
            error_log('[datacount_pagos] S3 delete fallo para ' . $a['archivo']
                    . ' (pago #' . $id . '): ' . $e->getMessage());
        }
    }

    if ($fallidos) {
        jsonError(
            'No se pudieron borrar del bucket ' . count($fallidos) . ' de '
            . count($adjuntos) . ' adjunto(s), asi que la orden de pago #' . $id
            . ' no se elimino. Reintenta en unos minutos.',
            502,
            ['adjuntos_fallidos' => $fallidos]
        );
    }

    // ---- Pasos 2 y 3: ya no hay binarios, se puede borrar la metadata ----
    $delAdj = $pdo->prepare('DELETE FROM datacount_pagos_adjuntos WHERE pago = :pago');
    $delAdj->execute([':pago' => $id]);

    $del = $pdo->prepare('DELETE FROM datacount_pagos WHERE id = :id');
    $del->execute([':id' => $id]);
    if ($del->rowCount() === 0) jsonError('Pago no encontrado', 404);

    jsonOk([
        'id'                => $id,
        'adjuntos'          => count($adjuntos),
        'adjuntos_borrados' => $borrados,
    ]);
}
