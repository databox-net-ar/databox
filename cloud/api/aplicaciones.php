<?php
// api/aplicaciones.php
// ABM del catalogo de aplicaciones externas que consumen datos de Databox
// via API key. Lee/escribe sobre la tabla `aplicaciones` definida en
// db/schema.sql (id / nombre / apikey / usos / habilitada).
//   GET    api/aplicaciones.php               -> listado con filtros
//   GET    api/aplicaciones.php?id=N          -> registro individual
//   POST   api/aplicaciones.php               -> alta (JSON body)
//   PUT    api/aplicaciones.php?id=N          -> modificacion (JSON body)
//   DELETE api/aplicaciones.php?id=N          -> baja
//   POST   api/aplicaciones.php?id=N&regenerar=1 -> genera nueva API key
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Verbo especial: regenerar la API key de una fila existente. Va por POST
    // porque muta estado, con un permiso propio distinto de `editar` (no todos
    // los usuarios que pueden retocar el nombre deberian poder invalidar la key
    // en produccion).
    if ($method === 'POST' && !empty($_GET['regenerar'])) {
        if ($id <= 0) jsonError('Falta id', 400);
        requirePermission('seguridad.aplicaciones.regenerar');
        handleRegenerar($pdo, $id);
        exit;
    }

    requirePermCrud('seguridad.aplicaciones');

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
    $nombre = trim((string)($q['nombre'] ?? ''));
    $estado = trim((string)($q['estado'] ?? '')); // '', '1', '0'
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'usos', 'habilitada'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null)  { $where[] = 'id = :codigo';         $params[':codigo'] = $codigo; }
    if ($nombre    !== '') { $where[] = 'nombre LIKE :nombre';  $params[':nombre'] = "%{$nombre}%"; }
    if ($estado    === '1' || $estado === '0') {
        $where[] = 'habilitada = :estado';
        $params[':estado'] = $estado;
    }

    if ($search !== '') {
        // PDO con EMULATE_PREPARES=false no permite reusar placeholders — uno por columna.
        $where[] = '(nombre LIKE :s_nombre OR apikey LIKE :s_apikey)';
        $like = "%{$search}%";
        $params[':s_nombre'] = $like;
        $params[':s_apikey'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN habilitada = '1' THEN 1 ELSE 0 END) AS habilitadas,
            SUM(CASE WHEN habilitada <> '1' OR habilitada IS NULL THEN 1 ELSE 0 END) AS deshabilitadas
        FROM aplicaciones
    ")->fetch();

    $sql = "
        SELECT id, nombre, apikey, usos, habilitada
        FROM aplicaciones
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'          => (int)($stats['total']          ?? 0),
            'habilitadas'    => (int)($stats['habilitadas']    ?? 0),
            'deshabilitadas' => (int)($stats['deshabilitadas'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, nombre, apikey, usos, habilitada FROM aplicaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Aplicacion no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja / Regeneracion de API key
// ----------------------------------------------------------------------------

// Genera una API key aleatoria de 43 caracteres (base64url de 32 bytes).
// random_bytes() usa la fuente CSPRNG del sistema; el `strtr` reemplaza los
// caracteres no url-safe para que la key sea segura de pegar en un header
// `X-Api-Key` o en un query string sin urlencode.
function nuevaApiKey(): string {
    $raw = random_bytes(32);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function assertApiKeyDisponible(PDO $pdo, string $apikey, ?int $exceptoId = null): void {
    // La columna `apikey` no tiene UNIQUE en el schema (tabla compartida con
    // apps legacy), pero el ABM se encarga de no repetirla — dos aplicaciones
    // con la misma key rompen la autenticacion por key.
    $sql    = 'SELECT id FROM aplicaciones WHERE apikey = :apikey';
    $params = [':apikey' => $apikey];
    if ($exceptoId !== null) {
        $sql          .= ' AND id <> :id';
        $params[':id'] = $exceptoId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) jsonError('Ya existe otra aplicacion con esa API key', 400);
}

function sanitizePayload(array $in): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);
    if (strlen($nombre) > 100) jsonError('El nombre no puede superar los 100 caracteres', 400);

    // `habilitada` es CHAR(1) en el schema — normalizamos a '1'/'0' aceptando
    // tanto strings como booleanos del payload JSON.
    $rawHab = $in['habilitada'] ?? '1';
    if (is_bool($rawHab)) $rawHab = $rawHab ? '1' : '0';
    $habilitada = ((string)$rawHab === '1') ? '1' : '0';

    return [
        'nombre'     => $nombre,
        'habilitada' => $habilitada,
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    // Reintenta hasta 5 veces por si toca colision (extremadamente improbable
    // con 32 bytes, pero el codigo queda robusto).
    $apikey = '';
    for ($i = 0; $i < 5; $i++) {
        $candidate = nuevaApiKey();
        $stmt = $pdo->prepare('SELECT id FROM aplicaciones WHERE apikey = :apikey');
        $stmt->execute([':apikey' => $candidate]);
        if (!$stmt->fetch()) { $apikey = $candidate; break; }
    }
    if ($apikey === '') jsonError('No se pudo generar una API key unica', 500);

    $stmt = $pdo->prepare('
        INSERT INTO aplicaciones (nombre, apikey, usos, habilitada)
        VALUES (:nombre, :apikey, 0, :habilitada)
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':apikey'     => $apikey,
        ':habilitada' => $p['habilitada'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId(), 'apikey' => $apikey], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM aplicaciones WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Aplicacion no encontrada', 404);

    $p = sanitizePayload($in);

    // La API key NO se toca desde este endpoint — para rotarla hay que usar el
    // verbo `regenerar` explicitamente (permiso independiente).
    $stmt = $pdo->prepare('
        UPDATE aplicaciones
           SET nombre = :nombre, habilitada = :habilitada
         WHERE id = :id
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':habilitada' => $p['habilitada'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aplicaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Aplicacion no encontrada', 404);
    jsonOk(['id' => $id]);
}

function handleRegenerar(PDO $pdo, int $id): void {
    $exists = $pdo->prepare('SELECT id FROM aplicaciones WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Aplicacion no encontrada', 404);

    $apikey = '';
    for ($i = 0; $i < 5; $i++) {
        $candidate = nuevaApiKey();
        $stmt = $pdo->prepare('SELECT id FROM aplicaciones WHERE apikey = :apikey AND id <> :id');
        $stmt->execute([':apikey' => $candidate, ':id' => $id]);
        if (!$stmt->fetch()) { $apikey = $candidate; break; }
    }
    if ($apikey === '') jsonError('No se pudo generar una API key unica', 500);

    $stmt = $pdo->prepare('UPDATE aplicaciones SET apikey = :apikey WHERE id = :id');
    $stmt->execute([':apikey' => $apikey, ':id' => $id]);
    jsonOk(['id' => $id, 'apikey' => $apikey]);
}
