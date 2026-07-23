<?php
// api/awscanales.php
// ABM de canales AWS. Lee/escribe sobre la tabla `aws_canales`
// definida en db/schema.sql.
//   GET    api/awscanales.php          -> listado con filtros (query string)
//   GET    api/awscanales.php?id=N     -> registro individual
//   POST   api/awscanales.php          -> alta (JSON body)
//   PUT    api/awscanales.php?id=N     -> modificacion (JSON body)
//   DELETE api/awscanales.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const AWS_CH_COLS = "id, uuid, nombre, correo, servidor, usuario, contrasena, habilitado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.aws.canales');
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
    $codigo     = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $habilitado = trim((string)($q['habilitado'] ?? ''));
    $search     = trim((string)($q['q']          ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'correo', 'servidor', 'usuario', 'habilitado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']     = $codigo; }
    if ($habilitado !== '')   { $where[] = 'habilitado = :habilitado';   $params[':habilitado'] = $habilitado; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR correo LIKE :s2 OR servidor LIKE :s3 OR usuario LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total,
            SUM(CASE WHEN habilitado = '1' THEN 1 ELSE 0 END)         AS habilitados
        FROM aws_canales
    ")->fetch();

    $sql = "
        SELECT " . AWS_CH_COLS . "
        FROM aws_canales
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
    $stmt = $pdo->prepare("SELECT " . AWS_CH_COLS . " FROM aws_canales WHERE id = :id");
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

function sanitizePayload(array $in): array {
    return [
        'nombre'     => nullableStr($in['nombre']     ?? null, 255),
        'correo'     => nullableStr($in['correo']     ?? null, 255),
        'servidor'   => nullableStr($in['servidor']   ?? null, 255),
        'usuario'    => nullableStr($in['usuario']    ?? null, 255),
        'contrasena' => nullableStr($in['contrasena'] ?? null, 255),
        'habilitado' => nullableStr($in['habilitado'] ?? null, 1),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $p['uuid'] = $in['uuid'] ?? bin2hex(random_bytes(16));

    $sql = "
        INSERT INTO aws_canales
            (uuid, nombre, correo, servidor, usuario, contrasena, habilitado)
        VALUES
            (:uuid, :nombre, :correo, :servidor, :usuario, :contrasena, :habilitado)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'       => $p['uuid'],
        ':nombre'     => $p['nombre'],
        ':correo'     => $p['correo'],
        ':servidor'   => $p['servidor'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':habilitado' => $p['habilitado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM aws_canales WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Canal no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE aws_canales SET
            nombre     = :nombre,
            correo     = :correo,
            servidor   = :servidor,
            usuario    = :usuario,
            contrasena = :contrasena,
            habilitado = :habilitado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':correo'     => $p['correo'],
        ':servidor'   => $p['servidor'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':habilitado' => $p['habilitado'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_canales WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Canal no encontrado', 404);
    jsonOk(['id' => $id]);
}
