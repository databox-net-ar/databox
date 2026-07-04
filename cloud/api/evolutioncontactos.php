<?php
// api/evolutioncontactos.php
// ABM de contactos Evolution API. Lee/escribe sobre la tabla `evolutioncontactos`
// definida en db/schema.sql.
//   GET    api/evolutioncontactos.php          -> listado con filtros (query string)
//   GET    api/evolutioncontactos.php?id=N     -> registro individual
//   POST   api/evolutioncontactos.php          -> alta (JSON body)
//   PUT    api/evolutioncontactos.php?id=N     -> modificacion (JSON body)
//   DELETE api/evolutioncontactos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const EVO_CT_COLS = "id, fecha, destino, error, estado";

header('Content-Type: application/json; charset=utf-8');

try {
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
    $estado = trim((string)($q['estado'] ?? ''));
    $desde  = trim((string)($q['desde']  ?? ''));
    $hasta  = trim((string)($q['hasta']  ?? ''));
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'destino', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';     $params[':codigo'] = $codigo; }
    if ($estado !== '')   { $where[] = 'estado = :estado'; $params[':estado'] = $estado; }
    if ($desde  !== '')   { $where[] = 'fecha >= :desde';  $params[':desde']  = $desde . ' 00:00:00'; }
    if ($hasta  !== '')   { $where[] = 'fecha <= :hasta';  $params[':hasta']  = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(destino LIKE :s OR error LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                          AS total,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM evolutioncontactos
    ")->fetch();

    $sql = "
        SELECT " . EVO_CT_COLS . "
        FROM evolutioncontactos
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'     => (int)($stats['total']     ?? 0),
            'con_error' => (int)($stats['con_error'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . EVO_CT_COLS . " FROM evolutioncontactos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Contacto no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function nullableDateTime(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function sanitizePayload(array $in): array {
    return [
        'fecha'   => nullableDateTime($in['fecha']  ?? null),
        'destino' => nullableStr($in['destino']     ?? null, 50),
        'error'   => nullableStr($in['error']       ?? null),
        'estado'  => nullableStr($in['estado']      ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO evolutioncontactos (fecha, destino, error, estado)
        VALUES (:fecha, :destino, :error, :estado)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'   => $p['fecha'],
        ':destino' => $p['destino'],
        ':error'   => $p['error'],
        ':estado'  => $p['estado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM evolutioncontactos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Contacto no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE evolutioncontactos SET
            fecha   = :fecha,
            destino = :destino,
            error   = :error,
            estado  = :estado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'   => $p['fecha'],
        ':destino' => $p['destino'],
        ':error'   => $p['error'],
        ':estado'  => $p['estado'],
        ':id'      => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM evolutioncontactos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Contacto no encontrado', 404);
    jsonOk(['id' => $id]);
}
