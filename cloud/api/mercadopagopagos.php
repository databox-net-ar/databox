<?php
// api/mercadopagopagos.php
// ABM de pagos Mercadopago. Lee/escribe sobre la tabla `mercadopagopagos`
// definida en db/schema.sql.
//   GET    api/mercadopagopagos.php          -> listado con filtros (query string)
//   GET    api/mercadopagopagos.php?id=N     -> registro individual
//   POST   api/mercadopagopagos.php          -> alta (JSON body)
//   PUT    api/mercadopagopagos.php?id=N     -> modificacion (JSON body)
//   DELETE api/mercadopagopagos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const MP_PAG_COLS = "id, uuid, cuenta, factura, recibo, iniciado, finalizado,
                     concepto, monto, operacion, retorno, estado,
                     notificacion, propiedades";

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
    $codigo    = isset($q['codigo'])    && $q['codigo']    !== '' ? (int)$q['codigo']    : null;
    $cuenta    = isset($q['cuenta'])    && $q['cuenta']    !== '' ? (int)$q['cuenta']    : null;
    $factura   = isset($q['factura'])   && $q['factura']   !== '' ? (int)$q['factura']   : null;
    $recibo    = isset($q['recibo'])    && $q['recibo']    !== '' ? (int)$q['recibo']    : null;
    $estado    = trim((string)($q['estado'] ?? ''));
    $desde     = trim((string)($q['desde']  ?? ''));
    $hasta     = trim((string)($q['hasta']  ?? ''));
    $search    = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'iniciado', 'finalizado', 'cuenta', 'factura',
                     'recibo', 'concepto', 'monto', 'operacion', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo  !== null) { $where[] = 'id = :codigo';       $params[':codigo']  = $codigo; }
    if ($cuenta  !== null) { $where[] = 'cuenta = :cuenta';   $params[':cuenta']  = $cuenta; }
    if ($factura !== null) { $where[] = 'factura = :factura'; $params[':factura'] = $factura; }
    if ($recibo  !== null) { $where[] = 'recibo = :recibo';   $params[':recibo']  = $recibo; }
    if ($estado  !== '')   { $where[] = 'estado = :estado';   $params[':estado']  = $estado; }
    if ($desde   !== '')   { $where[] = 'iniciado >= :desde'; $params[':desde']   = $desde . ' 00:00:00'; }
    if ($hasta   !== '')   { $where[] = 'iniciado <= :hasta'; $params[':hasta']   = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(uuid LIKE :s OR concepto LIKE :s OR operacion LIKE :s OR retorno LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                    AS total,
            SUM(CASE WHEN finalizado IS NOT NULL THEN 1 ELSE 0 END)     AS finalizados,
            COALESCE(SUM(CASE WHEN finalizado IS NOT NULL THEN monto ELSE 0 END), 0) AS monto_total
        FROM mercadopagopagos
    ")->fetch();

    $sql = "
        SELECT " . MP_PAG_COLS . "
        FROM mercadopagopagos
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
            'finalizados' => (int)($stats['finalizados'] ?? 0),
            'monto_total' => (float)($stats['monto_total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MP_PAG_COLS . " FROM mercadopagopagos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Pago no encontrado', 404);
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

function nullableDec(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    $s = str_replace(',', '.', trim((string)$v));
    if (!is_numeric($s)) return null;
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
        'uuid'         => nullableStr($in['uuid']         ?? null, 50),
        'cuenta'       => nullableInt($in['cuenta']       ?? null),
        'factura'      => nullableInt($in['factura']      ?? null),
        'recibo'       => nullableInt($in['recibo']       ?? null),
        'iniciado'     => nullableDateTime($in['iniciado']   ?? null),
        'finalizado'   => nullableDateTime($in['finalizado'] ?? null),
        'concepto'     => nullableStr($in['concepto']     ?? null, 255),
        'monto'        => nullableDec($in['monto']        ?? null),
        'operacion'    => nullableStr($in['operacion']    ?? null, 255),
        'retorno'      => nullableStr($in['retorno']      ?? null, 1000),
        'estado'       => nullableStr($in['estado']       ?? null, 1),
        'notificacion' => nullableStr($in['notificacion'] ?? null),
        'propiedades'  => nullableStr($in['propiedades']  ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['uuid'] === null) {
        $p['uuid'] = substr(bin2hex(random_bytes(16)), 0, 32);
    }
    if ($p['iniciado'] === null) {
        $p['iniciado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                         ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO mercadopagopagos
            (uuid, cuenta, factura, recibo, iniciado, finalizado,
             concepto, monto, operacion, retorno, estado,
             notificacion, propiedades)
        VALUES
            (:uuid, :cuenta, :factura, :recibo, :iniciado, :finalizado,
             :concepto, :monto, :operacion, :retorno, :estado,
             :notificacion, :propiedades)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'         => $p['uuid'],
        ':cuenta'       => $p['cuenta'],
        ':factura'      => $p['factura'],
        ':recibo'       => $p['recibo'],
        ':iniciado'     => $p['iniciado'],
        ':finalizado'   => $p['finalizado'],
        ':concepto'     => $p['concepto'],
        ':monto'        => $p['monto'],
        ':operacion'    => $p['operacion'],
        ':retorno'      => $p['retorno'],
        ':estado'       => $p['estado'],
        ':notificacion' => $p['notificacion'],
        ':propiedades'  => $p['propiedades'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM mercadopagopagos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Pago no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE mercadopagopagos SET
            uuid         = :uuid,
            cuenta       = :cuenta,
            factura      = :factura,
            recibo       = :recibo,
            iniciado     = :iniciado,
            finalizado   = :finalizado,
            concepto     = :concepto,
            monto        = :monto,
            operacion    = :operacion,
            retorno      = :retorno,
            estado       = :estado,
            notificacion = :notificacion,
            propiedades  = :propiedades
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'         => $p['uuid'],
        ':cuenta'       => $p['cuenta'],
        ':factura'      => $p['factura'],
        ':recibo'       => $p['recibo'],
        ':iniciado'     => $p['iniciado'],
        ':finalizado'   => $p['finalizado'],
        ':concepto'     => $p['concepto'],
        ':monto'        => $p['monto'],
        ':operacion'    => $p['operacion'],
        ':retorno'      => $p['retorno'],
        ':estado'       => $p['estado'],
        ':notificacion' => $p['notificacion'],
        ':propiedades'  => $p['propiedades'],
        ':id'           => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mercadopagopagos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Pago no encontrado', 404);
    jsonOk(['id' => $id]);
}
