<?php
// api/datarocketlistas.php
// ABM de listas Datarocket. Lee/escribe sobre la tabla `datarocket_listas`
// definida en db/schema.sql (creada por la migracion
// 20260811_1200_crear_datarocket_listas.sql).
//   GET    api/datarocketlistas.php          -> listado con filtros (query string)
//   GET    api/datarocketlistas.php?id=N     -> registro individual
//   POST   api/datarocketlistas.php          -> alta (JSON body)
//   PUT    api/datarocketlistas.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketlistas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_LI_COLS = "id, proyecto_id, nombre, descripcion, suscriptos";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.listas');
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
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $proyectoId = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $search     = trim((string)($q['q'] ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'proyecto_id', 'suscriptos'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']      = $codigo; }
    if ($proyectoId !== null) { $where[] = 'proyecto_id = :proyecto_id'; $params[':proyecto_id'] = $proyectoId; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR descripcion LIKE :s2)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                         AS total,
            SUM(CASE WHEN proyecto_id IS NOT NULL THEN 1 ELSE 0 END)         AS con_proyecto,
            COALESCE(SUM(CASE WHEN suscriptos IS NULL THEN 0 ELSE suscriptos END), 0) AS suscriptos_total
        FROM datarocket_listas
    ")->fetch();

    $sql = "
        SELECT " . DR_LI_COLS . "
        FROM datarocket_listas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'           => (int)($stats['total']           ?? 0),
            'con_proyecto'    => (int)($stats['con_proyecto']    ?? 0),
            'suscriptos_total' => (int)($stats['suscriptos_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_LI_COLS . " FROM datarocket_listas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Lista no encontrada', 404);
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

function nullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function sanitizePayload(array $in): array {
    return [
        'proyecto_id' => nullableInt($in['proyecto_id'] ?? null),
        'nombre'      => nullableStr($in['nombre']      ?? null, 255),
        'descripcion' => nullableStr($in['descripcion'] ?? null, 500),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);

    // `suscriptos` no se toca desde el ABM (lo recalcula el motor); queda
    // con el DEFAULT NULL de la columna al crear una lista nueva.
    $sql = "
        INSERT INTO datarocket_listas
            (proyecto_id, nombre, descripcion)
        VALUES
            (:proyecto_id, :nombre, :descripcion)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_listas WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Lista no encontrada', 404);

    $p = sanitizePayload($in);
    if ($p['nombre'] === null) jsonError('El nombre es obligatorio', 400);

    // `suscriptos` es un contador denormalizado que recalcula el motor
    // desde afuera; el ABM no lo edita, asi que no se toca en el UPDATE
    // (si lo incluyeramos con :suscriptos = NULL, pisariamos el valor real
    // que dejo el motor).
    $sql = "
        UPDATE datarocket_listas SET
            proyecto_id = :proyecto_id,
            nombre      = :nombre,
            descripcion = :descripcion
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto_id' => $p['proyecto_id'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_listas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Lista no encontrada', 404);
    jsonOk(['id' => $id]);
}
