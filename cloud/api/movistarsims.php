<?php
// api/movistarsims.php
// ABM del catalogo de SIMs M2M administradas via Kite Platform (Movistar).
// Lee/escribe sobre la tabla `movistarsims` definida en db/schema.sql.
//   GET    api/movistarsims.php          -> listado con filtros (query string)
//   GET    api/movistarsims.php?id=N     -> registro individual
//   POST   api/movistarsims.php          -> alta (JSON body)
//   PUT    api/movistarsims.php?id=N     -> modificacion (JSON body)
//   DELETE api/movistarsims.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const MSIM_COLS = "id, nombre, linea, icc, estado, estado_gprs, estado_lte, limite_datos, imei, msisdn, actualizado";

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
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 2000) $limite = 2000;

    $allowedOrder = ['id', 'nombre', 'linea', 'icc', 'estado', 'msisdn', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';       $params[':codigo'] = $codigo; }
    if ($estado !== '')   { $where[] = 'estado = :estado';   $params[':estado'] = $estado; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR linea LIKE :s OR icc LIKE :s OR msisdn LIKE :s OR imei LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                                  AS total,
            SUM(CASE WHEN LOWER(estado) IN ('activada','activa','active') THEN 1 END) AS activas,
            SUM(CASE WHEN estado IS NULL OR estado = '' THEN 1 END)                   AS sin_estado,
            MAX(actualizado)                                                          AS ultima_sync
        FROM movistarsims
    ")->fetch();

    $sql = "
        SELECT " . MSIM_COLS . "
        FROM movistarsims
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']      ?? 0),
            'activas'     => (int)($stats['activas']    ?? 0),
            'sin_estado'  => (int)($stats['sin_estado'] ?? 0),
            'ultima_sync' => $stats['ultima_sync']      ?? null,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MSIM_COLS . " FROM movistarsims WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('SIM no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, int $max): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function sanitizePayload(array $in): array {
    return [
        'nombre'       => nullableStr($in['nombre']       ?? null, 255),
        'linea'        => nullableStr($in['linea']        ?? null, 30),
        'icc'          => nullableStr($in['icc']          ?? null, 25),
        'estado'       => nullableStr($in['estado']       ?? null, 40),
        'estado_gprs'  => nullableStr($in['estado_gprs']  ?? null, 40),
        'estado_lte'   => nullableStr($in['estado_lte']   ?? null, 40),
        'limite_datos' => nullableStr($in['limite_datos'] ?? null, 40),
        'imei'         => nullableStr($in['imei']         ?? null, 30),
        'msisdn'       => nullableStr($in['msisdn']       ?? null, 30),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    try {
        $sql = "
            INSERT INTO movistarsims
                (nombre, linea, icc, estado, estado_gprs, estado_lte, limite_datos, imei, msisdn)
            VALUES
                (:nombre, :linea, :icc, :estado, :estado_gprs, :estado_lte, :limite_datos, :imei, :msisdn)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nombre'       => $p['nombre'],
            ':linea'        => $p['linea'],
            ':icc'          => $p['icc'],
            ':estado'       => $p['estado'],
            ':estado_gprs'  => $p['estado_gprs'],
            ':estado_lte'   => $p['estado_lte'],
            ':limite_datos' => $p['limite_datos'],
            ':imei'         => $p['imei'],
            ':msisdn'       => $p['msisdn'],
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) jsonError('Ya existe una SIM con ese ICC', 409);
        throw $e;
    }

    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM movistarsims WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('SIM no encontrada', 404);

    $p = sanitizePayload($in);

    try {
        $sql = "
            UPDATE movistarsims SET
                nombre       = :nombre,
                linea        = :linea,
                icc          = :icc,
                estado       = :estado,
                estado_gprs  = :estado_gprs,
                estado_lte   = :estado_lte,
                limite_datos = :limite_datos,
                imei         = :imei,
                msisdn       = :msisdn
            WHERE id = :id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nombre'       => $p['nombre'],
            ':linea'        => $p['linea'],
            ':icc'          => $p['icc'],
            ':estado'       => $p['estado'],
            ':estado_gprs'  => $p['estado_gprs'],
            ':estado_lte'   => $p['estado_lte'],
            ':limite_datos' => $p['limite_datos'],
            ':imei'         => $p['imei'],
            ':msisdn'       => $p['msisdn'],
            ':id'           => $id,
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) jsonError('Ya existe otra SIM con ese ICC', 409);
        throw $e;
    }

    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM movistarsims WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('SIM no encontrada', 404);
    jsonOk(['id' => $id]);
}
