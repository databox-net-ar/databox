<?php
// api/mercadopagocuentas.php
// ABM de cuentas Mercadopago. Lee/escribe sobre la tabla `mercadopagocuentas`
// definida en db/schema.sql.
//   GET    api/mercadopagocuentas.php          -> listado con filtros (query string)
//   GET    api/mercadopagocuentas.php?id=N     -> registro individual
//   POST   api/mercadopagocuentas.php          -> alta (JSON body)
//   PUT    api/mercadopagocuentas.php?id=N     -> modificacion (JSON body)
//   DELETE api/mercadopagocuentas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const MP_CTA_COLS = "id, uuid, nombre, logo, cvuAlias, cvuNumero,
                     publicKey, accessToken, publicKeyTesting, accessTokenTesting,
                     webhookEndpoint, webhookKey, webhookEndpointTesting, webhookKeyTesting,
                     imputacion, modo, estado";

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
    $modo   = trim((string)($q['modo']   ?? ''));
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'cvuAlias', 'cvuNumero', 'imputacion', 'modo', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';     $params[':codigo'] = $codigo; }
    if ($estado !== '')   { $where[] = 'estado = :estado'; $params[':estado'] = $estado; }
    if ($modo   !== '')   { $where[] = 'modo = :modo';     $params[':modo']   = $modo; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR cvuAlias LIKE :s OR cvuNumero LIKE :s
                     OR imputacion LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                         AS total,
            SUM(CASE WHEN estado = '1' THEN 1 ELSE 0 END)    AS habilitadas,
            SUM(CASE WHEN modo   = 'P' THEN 1 ELSE 0 END)    AS produccion
        FROM mercadopagocuentas
    ")->fetch();

    $sql = "
        SELECT " . MP_CTA_COLS . "
        FROM mercadopagocuentas
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
            'habilitadas' => (int)($stats['habilitadas'] ?? 0),
            'produccion'  => (int)($stats['produccion']  ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MP_CTA_COLS . " FROM mercadopagocuentas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Cuenta no encontrada', 404);
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

function sanitizePayload(array $in): array {
    return [
        'uuid'                   => nullableStr($in['uuid']                   ?? null, 50),
        'nombre'                 => nullableStr($in['nombre']                 ?? null, 255),
        'logo'                   => nullableStr($in['logo']                   ?? null, 255),
        'cvuAlias'               => nullableStr($in['cvuAlias']               ?? null, 255),
        'cvuNumero'              => nullableStr($in['cvuNumero']              ?? null, 255),
        'publicKey'              => nullableStr($in['publicKey']              ?? null, 255),
        'accessToken'            => nullableStr($in['accessToken']            ?? null, 255),
        'publicKeyTesting'       => nullableStr($in['publicKeyTesting']       ?? null, 255),
        'accessTokenTesting'     => nullableStr($in['accessTokenTesting']     ?? null, 255),
        'webhookEndpoint'        => nullableStr($in['webhookEndpoint']        ?? null, 255),
        'webhookKey'             => nullableStr($in['webhookKey']             ?? null, 255),
        'webhookEndpointTesting' => nullableStr($in['webhookEndpointTesting'] ?? null, 255),
        'webhookKeyTesting'      => nullableStr($in['webhookKeyTesting']      ?? null, 255),
        'imputacion'             => nullableStr($in['imputacion']             ?? null, 255),
        'modo'                   => nullableStr($in['modo']                   ?? null, 1),
        'estado'                 => nullableStr($in['estado']                 ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['uuid'] === null) {
        $p['uuid'] = substr(bin2hex(random_bytes(16)), 0, 32);
    }

    $sql = "
        INSERT INTO mercadopagocuentas
            (uuid, nombre, logo, cvuAlias, cvuNumero,
             publicKey, accessToken, publicKeyTesting, accessTokenTesting,
             webhookEndpoint, webhookKey, webhookEndpointTesting, webhookKeyTesting,
             imputacion, modo, estado)
        VALUES
            (:uuid, :nombre, :logo, :cvuAlias, :cvuNumero,
             :publicKey, :accessToken, :publicKeyTesting, :accessTokenTesting,
             :webhookEndpoint, :webhookKey, :webhookEndpointTesting, :webhookKeyTesting,
             :imputacion, :modo, :estado)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'                   => $p['uuid'],
        ':nombre'                 => $p['nombre'],
        ':logo'                   => $p['logo'],
        ':cvuAlias'               => $p['cvuAlias'],
        ':cvuNumero'              => $p['cvuNumero'],
        ':publicKey'              => $p['publicKey'],
        ':accessToken'            => $p['accessToken'],
        ':publicKeyTesting'       => $p['publicKeyTesting'],
        ':accessTokenTesting'     => $p['accessTokenTesting'],
        ':webhookEndpoint'        => $p['webhookEndpoint'],
        ':webhookKey'             => $p['webhookKey'],
        ':webhookEndpointTesting' => $p['webhookEndpointTesting'],
        ':webhookKeyTesting'      => $p['webhookKeyTesting'],
        ':imputacion'             => $p['imputacion'],
        ':modo'                   => $p['modo'],
        ':estado'                 => $p['estado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM mercadopagocuentas WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Cuenta no encontrada', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE mercadopagocuentas SET
            uuid                   = :uuid,
            nombre                 = :nombre,
            logo                   = :logo,
            cvuAlias               = :cvuAlias,
            cvuNumero              = :cvuNumero,
            publicKey              = :publicKey,
            accessToken            = :accessToken,
            publicKeyTesting       = :publicKeyTesting,
            accessTokenTesting     = :accessTokenTesting,
            webhookEndpoint        = :webhookEndpoint,
            webhookKey             = :webhookKey,
            webhookEndpointTesting = :webhookEndpointTesting,
            webhookKeyTesting      = :webhookKeyTesting,
            imputacion             = :imputacion,
            modo                   = :modo,
            estado                 = :estado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'                   => $p['uuid'],
        ':nombre'                 => $p['nombre'],
        ':logo'                   => $p['logo'],
        ':cvuAlias'               => $p['cvuAlias'],
        ':cvuNumero'              => $p['cvuNumero'],
        ':publicKey'              => $p['publicKey'],
        ':accessToken'            => $p['accessToken'],
        ':publicKeyTesting'       => $p['publicKeyTesting'],
        ':accessTokenTesting'     => $p['accessTokenTesting'],
        ':webhookEndpoint'        => $p['webhookEndpoint'],
        ':webhookKey'             => $p['webhookKey'],
        ':webhookEndpointTesting' => $p['webhookEndpointTesting'],
        ':webhookKeyTesting'      => $p['webhookKeyTesting'],
        ':imputacion'             => $p['imputacion'],
        ':modo'                   => $p['modo'],
        ':estado'                 => $p['estado'],
        ':id'                     => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mercadopagocuentas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Cuenta no encontrada', 404);
    jsonOk(['id' => $id]);
}
