<?php
// api/datacountpagos.php
// ABM de pagos Datacount. Lee/escribe sobre la tabla `datacountpagos`
// definida en db/schema.sql. Cada fila representa un documento digitalizado
// (factura recibida, VEP, comprobante de transferencia, etc.) con su empresa,
// proyecto, periodo, monto, moneda y estado de contabilizacion.
//   GET    api/datacountpagos.php          -> listado con filtros (query string)
//   GET    api/datacountpagos.php?id=N     -> registro individual
//   POST   api/datacountpagos.php          -> alta (JSON body)
//   PUT    api/datacountpagos.php?id=N     -> modificacion (JSON body)
//   DELETE api/datacountpagos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// Columnas SELECT comunes. `medio___` esta deprecated (ver comentario en el
// schema: "desde 831 hacia atras era id de medio"), pero se mantiene disponible
// para no perder informacion historica cuando se consulta un registro viejo.
const DCP_COLS = "id, uuid, empresa, proyecto, periodo, tipo, emision, cancelacion,
                  razon, cuit, numero, moneda, monto, cotizacion, valor,
                  medio___ AS medio_legacy, billetera, descripcion,
                  comprobante, transaccion, contabilizado, registrador, registrado,
                  remuneracion, clasificado, estado";

// URL publica donde vive el binario de cada adjunto. Es el mismo prefijo que
// usaba el admin legacy (mcDatacountPago::$url). Se sirve por CDN detras de
// https://media.databox.net.ar → S3.
const DCPAGO_MEDIA_URL = 'https://media.databox.net.ar/datacount/pagos';

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
        FROM datacountpagos
    ")->fetch();

    $sql = "
        SELECT " . DCP_COLS . "
        FROM datacountpagos
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
    $stmt = $pdo->prepare("SELECT " . DCP_COLS . " FROM datacountpagos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Pago no encontrado', 404);

    // Adjuntos asociados al pago (datacountpagosadjuntos). Se listan como
    // metadata (nombre / cargado / tipo / formato) — el binario en si vive
    // aparte y no lo servimos por esta API todavia.
    $stmtA = $pdo->prepare("
        SELECT id, uuid, nombre, cargado, tipo, archivo, formato
        FROM datacountpagosadjuntos
        WHERE pago = :id
        ORDER BY cargado ASC, id ASC
    ");
    $stmtA->execute([':id' => $id]);
    $adjuntos = $stmtA->fetchAll();
    foreach ($adjuntos as &$a) {
        // URL publica del binario. El front la usa para embeber el PDF o la
        // imagen directamente sin pasar por el back.
        $a['url'] = !empty($a['archivo']) ? DCPAGO_MEDIA_URL . '/' . $a['archivo'] : null;
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
        'numero'        => dcpNullableStr($in['numero']       ?? null, 50),
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

function handleCreateDcPago(PDO $pdo, array $in): void {
    $p = dcpSanitizePayload($in);
    $p['uuid']       = substr(bin2hex(random_bytes(8)), 0, 10);
    $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                        ->format('Y-m-d H:i:s');
    $auth = currentAuth();
    $p['registrador'] = (int)($auth['sub'] ?? 0) ?: null;

    $sql = "
        INSERT INTO datacountpagos
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
    $exists = $pdo->prepare('SELECT id FROM datacountpagos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Pago no encontrado', 404);

    $p = dcpSanitizePayload($in);

    $sql = "
        UPDATE datacountpagos SET
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

function handleDeleteDcPago(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datacountpagos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Pago no encontrado', 404);
    jsonOk(['id' => $id]);
}
