<?php
// api/roles.php
// ABM de roles. Lee/escribe sobre la tabla `roles` definida en db/schema.sql.
//   GET    api/roles.php                  -> listado con filtros (query string)
//   GET    api/roles.php?id=N             -> registro individual
//   GET    api/roles.php?listar=permisos  -> listado del catalogo de permisos
//   POST   api/roles.php                  -> alta (JSON body)
//   PUT    api/roles.php?id=N             -> modificacion (JSON body)
//   DELETE api/roles.php?id=N             -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('seguridad.roles');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $listar = trim((string)($_GET['listar'] ?? ''));

    if ($method === 'GET' && $listar === 'permisos') {
        handleListarPermisos($pdo);
    } elseif ($method === 'GET' && $id > 0) {
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

    // Solo el set "cloud" (con slug). Los roles legacy tienen slug NULL desde
    // 20260711_1200_limpiar_slug_y_descripcion_legacy.sql y no se exponen en
    // el ABM del panel — conviven en la tabla para uso de la UI legacy.
    $where  = ["slug IS NOT NULL AND slug <> ''"];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';                  $params[':codigo']      = $codigo; }
    if ($slug        !== '') { $where[] = 'slug        LIKE :slug';         $params[':slug']        = "%{$slug}%"; }
    if ($nombre      !== '') { $where[] = 'nombre      LIKE :nombre';       $params[':nombre']      = "%{$nombre}%"; }
    if ($descripcion !== '') { $where[] = 'descripcion LIKE :descripcion';  $params[':descripcion'] = "%{$descripcion}%"; }

    if ($search !== '') {
        $where[] = '(slug LIKE :s OR nombre LIKE :s OR descripcion LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN permisos IS NULL OR permisos = '' THEN 1 ELSE 0 END) AS sin_permisos
        FROM roles
        WHERE slug IS NOT NULL AND slug <> ''
    ")->fetch();

    $sql = "
        SELECT id, slug, nombre, descripcion, permisos
        FROM roles
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Conteo de permisos por rol (para mostrar como columna sin enviar el blob entero por fila)
    foreach ($rows as &$r) {
        $r['permisos_count'] = contarPermisos($r['permisos']);
    }

    jsonOk([
        'stats' => [
            'total'        => (int)($stats['total']        ?? 0),
            'sin_permisos' => (int)($stats['sin_permisos'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT id, slug, nombre, descripcion, permisos FROM roles WHERE id = :id AND slug IS NOT NULL AND slug <> ''");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Rol no encontrado', 404);
    $row['permisos_count'] = contarPermisos($row['permisos']);
    jsonOk($row);
}

function handleListarPermisos(PDO $pdo): void {
    // Solo permisos "cloud" (con slug). Los legacy quedan fuera del catalogo
    // que el editor de roles ofrece — al asignar permisos a un rol cloud, el
    // usuario solo ve los permisos del set nuevo.
    $rows = $pdo->query("SELECT id, nombre, descripcion FROM permisos WHERE slug IS NOT NULL AND slug <> '' ORDER BY nombre ASC")->fetchAll();
    jsonOk(['items' => $rows]);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function sanitizePayload(array $in): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);

    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($slug === '') jsonError('El slug es obligatorio', 400);
    if (strlen($slug) > 100) jsonError('El slug no puede superar los 100 caracteres', 400);
    // Se permite '.' para admitir slugs jerarquicos generados automaticamente
    // desde el nombre (ej: "Administrador General" -> "administrador.general"),
    // en linea con el estilo dot-separated usado por permisos.
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
        jsonError('El slug solo admite minusculas, numeros, punto, guion y guion bajo, y debe empezar con letra o numero', 400);
    }

    return [
        'slug'        => $slug,
        'nombre'      => $nombre,
        'descripcion' => trim((string)($in['descripcion'] ?? '')) ?: null,
        'permisos'    => trim((string)($in['permisos']    ?? '')) ?: null,
    ];
}

function assertSlugDisponible(PDO $pdo, string $slug, ?int $exceptoId = null): void {
    $sql    = 'SELECT id FROM roles WHERE slug = :slug';
    $params = [':slug' => $slug];
    if ($exceptoId !== null) {
        $sql             .= ' AND id <> :id';
        $params[':id']    = $exceptoId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) jsonError('Ya existe otro rol con ese slug', 400);
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    assertSlugDisponible($pdo, $p['slug']);
    $stmt = $pdo->prepare('
        INSERT INTO roles (slug, nombre, descripcion, permisos)
        VALUES (:slug, :nombre, :descripcion, :permisos)
    ');
    $stmt->execute([
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
        ':permisos'    => $p['permisos'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare("SELECT id FROM roles WHERE id = :id AND slug IS NOT NULL AND slug <> ''");
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Rol no encontrado', 404);

    $p = sanitizePayload($in);
    assertSlugDisponible($pdo, $p['slug'], $id);
    $stmt = $pdo->prepare('
        UPDATE roles
           SET slug = :slug, nombre = :nombre, descripcion = :descripcion, permisos = :permisos
         WHERE id = :id
    ');
    $stmt->execute([
        ':slug'        => $p['slug'],
        ':nombre'      => $p['nombre'],
        ':descripcion' => $p['descripcion'],
        ':permisos'    => $p['permisos'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("DELETE FROM roles WHERE id = :id AND slug IS NOT NULL AND slug <> ''");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Rol no encontrado', 404);
    jsonOk(['id' => $id]);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

// El campo `roles.permisos` es texto libre que en las apps del grupo se usa como
// CSV de IDs de permisos. Contamos tokens no vacios para mostrar la cantidad en la grilla
// sin imponer un formato estricto desde el backend.
function contarPermisos(?string $raw): int {
    if ($raw === null) return 0;
    $raw = trim($raw);
    if ($raw === '') return 0;
    $parts = preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}
