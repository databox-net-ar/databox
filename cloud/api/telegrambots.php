<?php
// api/telegrambots.php
// ABM de bots de Telegram. Lee/escribe sobre la tabla `telegram_bots`
// definida en db/schema.sql.
//   GET    api/telegrambots.php          -> listado con filtros (query string)
//   GET    api/telegrambots.php?id=N     -> registro individual
//   POST   api/telegrambots.php          -> alta (JSON body)
//   PUT    api/telegrambots.php?id=N     -> modificacion (JSON body)
//   DELETE api/telegrambots.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const TG_BOTS_COLS = "id, slug, proyecto, nombre, username, token, chat_id,
                      habilitado, actualizado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.telegram.bots');
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
    $codigo     = isset($q['codigo'])   && $q['codigo']   !== '' ? (int)$q['codigo']   : null;
    $proyecto   = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $habilitado = trim((string)($q['habilitado'] ?? ''));
    $search     = trim((string)($q['q']          ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'username', 'proyecto', 'habilitado', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';             $params[':codigo']     = $codigo; }
    if ($proyecto   !== null) { $where[] = 'proyecto = :proyecto';     $params[':proyecto']   = $proyecto; }
    if ($habilitado !== '')   { $where[] = 'habilitado = :habilitado'; $params[':habilitado'] = $habilitado; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR username LIKE :s2 OR token LIKE :s3
                     OR chat_id LIKE :s4 OR slug LIKE :s5)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
        $params[':s5'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                          AS total,
            SUM(CASE WHEN habilitado = '1' THEN 1 ELSE 0 END) AS habilitados
        FROM telegram_bots
    ")->fetch();

    $sql = "
        SELECT " . TG_BOTS_COLS . "
        FROM telegram_bots
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
            'habilitados' => (int)($stats['habilitados'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . TG_BOTS_COLS . " FROM telegram_bots WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Bot no encontrado', 404);
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

// Normaliza el `username` de Telegram: acepta con o sin `@` a la entrada,
// y persiste siempre sin `@`.
function normalizeUsername(mixed $v): ?string {
    $s = nullableStr($v, 100);
    if ($s === null) return null;
    return ltrim($s, '@');
}

function sanitizePayload(array $in): array {
    return [
        'proyecto'   => nullableInt($in['proyecto']   ?? null),
        'nombre'     => nullableStr($in['nombre']     ?? null, 255),
        'username'   => normalizeUsername($in['username'] ?? null),
        'token'      => nullableStr($in['token']      ?? null, 255),
        'chat_id'    => nullableStr($in['chat_id']    ?? null, 50),
        'habilitado' => nullableStr($in['habilitado'] ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $slug = nullableStr($in['slug'] ?? null, 50);
    if ($slug === null) $slug = bin2hex(random_bytes(16));

    $sql = "
        INSERT INTO telegram_bots
            (slug, proyecto, nombre, username, token, chat_id, habilitado, actualizado)
        VALUES
            (:slug, :proyecto, :nombre, :username, :token, :chat_id, :habilitado, NOW())
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':slug'       => $slug,
        ':proyecto'   => $p['proyecto'],
        ':nombre'     => $p['nombre'],
        ':username'   => $p['username'],
        ':token'      => $p['token'],
        ':chat_id'    => $p['chat_id'],
        ':habilitado' => $p['habilitado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM telegram_bots WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Bot no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE telegram_bots SET
            proyecto    = :proyecto,
            nombre      = :nombre,
            username    = :username,
            token       = :token,
            chat_id     = :chat_id,
            habilitado  = :habilitado,
            actualizado = NOW()
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto'   => $p['proyecto'],
        ':nombre'     => $p['nombre'],
        ':username'   => $p['username'],
        ':token'      => $p['token'],
        ':chat_id'    => $p['chat_id'],
        ':habilitado' => $p['habilitado'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM telegram_bots WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Bot no encontrado', 404);
    jsonOk(['id' => $id]);
}
