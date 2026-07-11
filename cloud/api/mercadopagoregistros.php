<?php
// api/mercadopagoregistros.php
// ABM del log de eventos de Mercadopago. Lee/escribe sobre la tabla
// `mercadopagoregistros` definida en db/schema.sql.
//   GET    api/mercadopagoregistros.php          -> listado con filtros (query string)
//   GET    api/mercadopagoregistros.php?id=N     -> registro individual
//   POST   api/mercadopagoregistros.php          -> alta (JSON body)
//   PUT    api/mercadopagoregistros.php?id=N     -> modificacion (JSON body)
//   DELETE api/mercadopagoregistros.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const MP_REG_COLS = "id, fecha, tipo, cuerpo";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.mercadopago.registros');
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
    $tipo   = trim((string)($q['tipo']  ?? ''));
    $desde  = trim((string)($q['desde'] ?? ''));
    $hasta  = trim((string)($q['hasta'] ?? ''));
    $search = trim((string)($q['q']     ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 200;
    if ($limite < 1)    $limite = 1;
    if ($limite > 2000) $limite = 2000;

    $allowedOrder = ['id', 'fecha', 'tipo'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';       $params[':codigo'] = $codigo; }
    if ($tipo   !== '')   { $where[] = 'tipo = :tipo';       $params[':tipo']   = $tipo; }
    if ($desde  !== '')   { $where[] = 'fecha >= :desde';    $params[':desde']  = $desde . ' 00:00:00'; }
    if ($hasta  !== '')   { $where[] = 'fecha <= :hasta';    $params[':hasta']  = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(tipo LIKE :s OR cuerpo LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                              AS total,
            COUNT(DISTINCT tipo)                  AS tipos_distintos
        FROM mercadopagoregistros
    ")->fetch();

    $sql = "
        SELECT " . MP_REG_COLS . "
        FROM mercadopagoregistros
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Catálogo de tipos existentes en la tabla (para poblar el select de
    // filtros). Se computa una vez por request — la tabla suele tener pocos
    // tipos distintos aunque acumule miles de registros.
    $tipos = $pdo->query("
        SELECT DISTINCT tipo FROM mercadopagoregistros
        WHERE tipo IS NOT NULL AND tipo <> ''
        ORDER BY tipo ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    jsonOk([
        'stats' => [
            'total'           => (int)($stats['total']           ?? 0),
            'tipos_distintos' => (int)($stats['tipos_distintos'] ?? 0),
        ],
        'tipos' => $tipos,
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MP_REG_COLS . " FROM mercadopagoregistros WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Registro no encontrado', 404);
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
        'fecha'  => nullableDateTime($in['fecha']  ?? null),
        'tipo'   => nullableStr($in['tipo']   ?? null, 50),
        'cuerpo' => nullableStr($in['cuerpo'] ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO mercadopagoregistros (fecha, tipo, cuerpo)
        VALUES (:fecha, :tipo, :cuerpo)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'  => $p['fecha'],
        ':tipo'   => $p['tipo'],
        ':cuerpo' => $p['cuerpo'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM mercadopagoregistros WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Registro no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE mercadopagoregistros SET
            fecha  = :fecha,
            tipo   = :tipo,
            cuerpo = :cuerpo
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'  => $p['fecha'],
        ':tipo'   => $p['tipo'],
        ':cuerpo' => $p['cuerpo'],
        ':id'     => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mercadopagoregistros WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Registro no encontrado', 404);
    jsonOk(['id' => $id]);
}
