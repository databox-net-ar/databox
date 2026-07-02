<?php
// api/permisos.php
// ABM de permisos. Lee/escribe sobre la tabla `permisos` definida en db/schema.sql.
//   GET    api/permisos.php        -> listado con filtros (query string)
//   GET    api/permisos.php?id=N   -> registro individual
//   POST   api/permisos.php        -> alta (JSON body)
//   PUT    api/permisos.php?id=N   -> modificacion (JSON body)
//   DELETE api/permisos.php?id=N   -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

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
    $codigo      = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $slug        = trim((string)($q['slug']        ?? ''));
    $nombre      = trim((string)($q['nombre']      ?? ''));
    $descripcion = trim((string)($q['descripcion'] ?? ''));
    $search      = trim((string)($q['q']           ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'slug', 'nombre', 'descripcion'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null)    { $where[] = 'id = :codigo';                  $params[':codigo']      = $codigo; }
    if ($slug        !== '') { $where[] = 'slug        LIKE :slug';        $params[':slug']        = "%{$slug}%"; }
    if ($nombre      !== '') { $where[] = 'nombre      LIKE :nombre';      $params[':nombre']      = "%{$nombre}%"; }
    if ($descripcion !== '') { $where[] = 'descripcion LIKE :descripcion'; $params[':descripcion'] = "%{$descripcion}%"; }

    if ($search !== '') {
        $where[] = '(slug LIKE :s OR nombre LIKE :s OR descripcion LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN descripcion IS NULL OR descripcion = '' THEN 1 ELSE 0 END) AS sin_descripcion
        FROM permisos
    ")->fetch();

    $sql = "
        SELECT id, slug, nombre, descripcion
        FROM permisos
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
            'sin_descripcion' => (int)($stats['sin_descripcion'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, slug, nombre, descripcion FROM permisos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Permiso no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function sanitizePayload(array $in): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);

    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '') jsonError('El slug es obligatorio', 400);
    if (strlen($slug) > 50) jsonError('El slug no puede superar los 50 caracteres', 400);
    // Se permite '.' ademas de '-' y '_' para admitir slugs jerarquicos tipo 'usuarios.editar'.
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
        jsonError('El slug solo admite minusculas, numeros, punto, guion y guion bajo, y debe empezar con letra o numero', 400);
    }

    return [
        'slug'        => $slug,
        'nombre'      => $nombre,
        'descripcion' => trim((string)($in['descripcion'] ?? '')) ?: null,
    ];
}

function assertSlugDisponible(PDO $pdo, string $slug, ?int $exceptoId = null): void {
    $sql    = 'SELECT id FROM permisos WHERE slug = :slug';
    $params = [':slug' => $slug];
    if ($exceptoId !== null) {
        $sql             .= ' AND id <> :id';
        $params[':id']    = $exceptoId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) jsonError('Ya existe otro permiso con ese slug', 400);
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    assertSlugDisponible($pdo, $p['slug']);
    $stmt = $pdo->prepare('
        INSERT INTO permisos (slug, nombre, descripcion)
        VALUES (:slug, :nombre, :descripcion)
    ');
    $stmt->execute([
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM permisos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Permiso no encontrado', 404);

    $p = sanitizePayload($in);
    assertSlugDisponible($pdo, $p['slug'], $id);
    $stmt = $pdo->prepare('
        UPDATE permisos
           SET slug = :slug, nombre = :nombre, descripcion = :descripcion
         WHERE id = :id
    ');
    $stmt->execute([
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM permisos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Permiso no encontrado', 404);
    jsonOk(['id' => $id]);
}
