<?php
// api/dolarhoycotizaciones.php
// ABM de cotizaciones del dolar (Dolarhoy). Lee/escribe sobre la tabla
// `dolarhoycotizaciones` definida en db/schema.sql.
//   GET    api/dolarhoycotizaciones.php          -> listado con filtros (query string)
//   GET    api/dolarhoycotizaciones.php?id=N     -> registro individual
//   POST   api/dolarhoycotizaciones.php          -> alta (JSON body)
//   PUT    api/dolarhoycotizaciones.php?id=N     -> modificacion (JSON body)
//   DELETE api/dolarhoycotizaciones.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DH_COT_COLS = "id, fecha, compra, venta";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.dolarhoy.cotizaciones');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDelete($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $desde  = trim((string)($q['desde'] ?? ''));
    $hasta  = trim((string)($q['hasta'] ?? ''));
    $search = trim((string)($q['q']     ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 2000) $limite = 2000;

    $allowedOrder = ['id', 'fecha', 'compra', 'venta'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';    $params[':codigo'] = $codigo; }
    if ($desde  !== '')   { $where[] = 'fecha >= :desde'; $params[':desde']  = $desde; }
    if ($hasta  !== '')   { $where[] = 'fecha <= :hasta'; $params[':hasta']  = $hasta; }

    if ($search !== '') {
        $where[] = '(CAST(fecha AS CHAR) LIKE :s OR CAST(compra AS CHAR) LIKE :s OR CAST(venta AS CHAR) LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)      AS total,
            MIN(fecha)    AS fecha_min,
            MAX(fecha)    AS fecha_max,
            AVG(compra)   AS compra_prom,
            AVG(venta)    AS venta_prom
        FROM dolarhoycotizaciones
    ")->fetch();

    $sql = "
        SELECT " . DH_COT_COLS . "
        FROM dolarhoycotizaciones
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'fecha_min'   => $stats['fecha_min']         ?? null,
            'fecha_max'   => $stats['fecha_max']         ?? null,
            'compra_prom' => $stats['compra_prom'] !== null ? (float)$stats['compra_prom'] : null,
            'venta_prom'  => $stats['venta_prom']  !== null ? (float)$stats['venta_prom']  : null,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DH_COT_COLS . " FROM dolarhoycotizaciones WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Cotizacion no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableDate(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function nullableDecimal(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return number_format((float)$s, 2, '.', '');
}

function sanitizePayload(array $in): array {
    return [
        'fecha'  => nullableDate($in['fecha']  ?? null),
        'compra' => nullableDecimal($in['compra'] ?? null),
        'venta'  => nullableDecimal($in['venta']  ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d');
    }

    $sql = "
        INSERT INTO dolarhoycotizaciones (fecha, compra, venta)
        VALUES (:fecha, :compra, :venta)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'  => $p['fecha'],
        ':compra' => $p['compra'],
        ':venta'  => $p['venta'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM dolarhoycotizaciones WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Cotizacion no encontrada', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE dolarhoycotizaciones SET
            fecha  = :fecha,
            compra = :compra,
            venta  = :venta
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'  => $p['fecha'],
        ':compra' => $p['compra'],
        ':venta'  => $p['venta'],
        ':id'     => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM dolarhoycotizaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Cotizacion no encontrada', 404);
    jsonOk(['id' => $id]);
}
