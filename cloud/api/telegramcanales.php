<?php
// api/telegramcanales.php
// ABM de canales (cuentas USUARIO / MTProto) de Telegram. Lee/escribe sobre
// la tabla `telegram_canales` definida en db/schema.sql.
//
// A diferencia de `telegram_bots` (cuentas bot Bot API, con token de
// @BotFather), aca cada fila representa una cuenta USUARIO real cuya sesion
// MTProto vive en disco -- en api/v4/telegram/session_<telefono>/. Ese
// directorio se genera UNA UNICA VEZ en desarrollo via CLI interactivo
// (login.php --canal=<slug>) y se transporta a prod via deploy. Este ABM
// no toca la sesion: solo declara el catalogo (nombre humano, telefono
// asociado y flag de habilitacion).
//
//   GET    api/telegramcanales.php          -> listado con filtros (query string)
//   GET    api/telegramcanales.php?id=N     -> registro individual
//   POST   api/telegramcanales.php          -> alta (JSON body)
//   PUT    api/telegramcanales.php?id=N     -> modificacion (JSON body)
//   DELETE api/telegramcanales.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const TG_CANALES_COLS = "id, slug, proyecto, nombre, telefono, online, latido,
                         habilitado, actualizado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.telegram.canales');
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
    $online     = trim((string)($q['online']     ?? ''));
    $search     = trim((string)($q['q']          ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'telefono', 'proyecto', 'online', 'latido',
                     'habilitado', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';             $params[':codigo']     = $codigo; }
    if ($proyecto   !== null) { $where[] = 'proyecto = :proyecto';     $params[':proyecto']   = $proyecto; }
    if ($habilitado !== '')   { $where[] = 'habilitado = :habilitado'; $params[':habilitado'] = $habilitado; }
    if ($online     !== '')   { $where[] = 'online = :online';         $params[':online']     = $online; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR telefono LIKE :s2 OR slug LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                          AS total,
            SUM(CASE WHEN habilitado = '1' THEN 1 ELSE 0 END) AS habilitados,
            SUM(CASE WHEN online     = '1' THEN 1 ELSE 0 END) AS online
        FROM telegram_canales
    ")->fetch();

    $sql = "
        SELECT " . TG_CANALES_COLS . "
        FROM telegram_canales
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
            'online'      => (int)($stats['online']      ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . TG_CANALES_COLS . " FROM telegram_canales WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Canal no encontrado', 404);
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

// Telefono en formato E.164 SIN '+' (ej. '541163219578'). Determina el
// nombre del subdirectorio de sesion en disco (session_<telefono>/). Se
// acepta con o sin '+' a la entrada y se persiste sin '+' (solo digitos).
function normalizeTelefono(mixed $v): ?string {
    $s = nullableStr($v, 20);
    if ($s === null) return null;
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') return null;
    return substr($digits, 0, 20);
}

function sanitizePayload(array $in): array {
    return [
        'proyecto'   => nullableInt($in['proyecto']   ?? null),
        'nombre'     => nullableStr($in['nombre']     ?? null, 255),
        'telefono'   => normalizeTelefono($in['telefono'] ?? null),
        'habilitado' => nullableStr($in['habilitado'] ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $slug = nullableStr($in['slug'] ?? null, 50);
    if ($slug === null) $slug = bin2hex(random_bytes(16));

    $sql = "
        INSERT INTO telegram_canales
            (slug, proyecto, nombre, telefono, habilitado, actualizado)
        VALUES
            (:slug, :proyecto, :nombre, :telefono, :habilitado, NOW())
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':slug'       => $slug,
        ':proyecto'   => $p['proyecto'],
        ':nombre'     => $p['nombre'],
        ':telefono'   => $p['telefono'],
        ':habilitado' => $p['habilitado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM telegram_canales WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Canal no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE telegram_canales SET
            proyecto    = :proyecto,
            nombre      = :nombre,
            telefono    = :telefono,
            habilitado  = :habilitado,
            actualizado = NOW()
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto'   => $p['proyecto'],
        ':nombre'     => $p['nombre'],
        ':telefono'   => $p['telefono'],
        ':habilitado' => $p['habilitado'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM telegram_canales WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Canal no encontrado', 404);
    jsonOk(['id' => $id]);
}
