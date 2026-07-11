<?php
// api/mercadopagodebitos.php
// ABM de débitos Mercadopago. Lee/escribe sobre la tabla `mercadopagodebitos`
// definida en db/schema.sql.
//   GET    api/mercadopagodebitos.php          -> listado con filtros (query string)
//   GET    api/mercadopagodebitos.php?id=N     -> registro individual
//   POST   api/mercadopagodebitos.php          -> alta (JSON body)
//   PUT    api/mercadopagodebitos.php?id=N     -> modificacion (JSON body)
//   DELETE api/mercadopagodebitos.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const MP_DEB_COLS = "id, uuid, cuenta, suscripcion, referencia, recibo,
                     fecha, concepto, monto, operacion, estado, propiedades";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.mercadopago.debitos');
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
    $codigo      = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $cuenta      = isset($q['cuenta'])      && $q['cuenta']      !== '' ? (int)$q['cuenta']      : null;
    $suscripcion = isset($q['suscripcion']) && $q['suscripcion'] !== '' ? (int)$q['suscripcion'] : null;
    $recibo      = isset($q['recibo'])      && $q['recibo']      !== '' ? (int)$q['recibo']      : null;
    $estado      = trim((string)($q['estado'] ?? ''));
    $desde       = trim((string)($q['desde']  ?? ''));
    $hasta       = trim((string)($q['hasta']  ?? ''));
    $search      = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'cuenta', 'suscripcion', 'referencia',
                     'recibo', 'concepto', 'monto', 'operacion', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo      !== null) { $where[] = 'id = :codigo';                 $params[':codigo']      = $codigo; }
    if ($cuenta      !== null) { $where[] = 'cuenta = :cuenta';             $params[':cuenta']      = $cuenta; }
    if ($suscripcion !== null) { $where[] = 'suscripcion = :suscripcion';   $params[':suscripcion'] = $suscripcion; }
    if ($recibo      !== null) { $where[] = 'recibo = :recibo';             $params[':recibo']      = $recibo; }
    if ($estado      !== '')   { $where[] = 'estado = :estado';             $params[':estado']      = $estado; }
    if ($desde       !== '')   { $where[] = 'fecha >= :desde';              $params[':desde']       = $desde . ' 00:00:00'; }
    if ($hasta       !== '')   { $where[] = 'fecha <= :hasta';              $params[':hasta']       = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(uuid LIKE :s OR referencia LIKE :s OR concepto LIKE :s OR operacion LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                        AS total,
            SUM(CASE WHEN estado = 'A' THEN 1 ELSE 0 END)   AS aprobados,
            COALESCE(SUM(CASE WHEN estado = 'A' THEN monto ELSE 0 END), 0) AS monto_cobrado
        FROM mercadopagodebitos
    ")->fetch();

    $sql = "
        SELECT " . MP_DEB_COLS . "
        FROM mercadopagodebitos
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'         => (int)($stats['total']         ?? 0),
            'aprobados'     => (int)($stats['aprobados']     ?? 0),
            'monto_cobrado' => (float)($stats['monto_cobrado'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MP_DEB_COLS . " FROM mercadopagodebitos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Débito no encontrado', 404);
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
        'uuid'        => nullableStr($in['uuid']        ?? null, 50),
        'cuenta'      => nullableInt($in['cuenta']      ?? null),
        'suscripcion' => nullableInt($in['suscripcion'] ?? null),
        'referencia'  => nullableStr($in['referencia']  ?? null, 100),
        'recibo'      => nullableInt($in['recibo']      ?? null),
        'fecha'       => nullableDateTime($in['fecha']  ?? null),
        'concepto'    => nullableStr($in['concepto']    ?? null, 255),
        'monto'       => nullableDec($in['monto']       ?? null),
        'operacion'   => nullableStr($in['operacion']   ?? null, 255),
        'estado'      => nullableStr($in['estado']      ?? null, 1),
        'propiedades' => nullableStr($in['propiedades'] ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['uuid'] === null) {
        $p['uuid'] = substr(bin2hex(random_bytes(16)), 0, 32);
    }
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO mercadopagodebitos
            (uuid, cuenta, suscripcion, referencia, recibo,
             fecha, concepto, monto, operacion, estado, propiedades)
        VALUES
            (:uuid, :cuenta, :suscripcion, :referencia, :recibo,
             :fecha, :concepto, :monto, :operacion, :estado, :propiedades)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'        => $p['uuid'],
        ':cuenta'      => $p['cuenta'],
        ':suscripcion' => $p['suscripcion'],
        ':referencia'  => $p['referencia'],
        ':recibo'      => $p['recibo'],
        ':fecha'       => $p['fecha'],
        ':concepto'    => $p['concepto'],
        ':monto'       => $p['monto'],
        ':operacion'   => $p['operacion'],
        ':estado'      => $p['estado'],
        ':propiedades' => $p['propiedades'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM mercadopagodebitos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Débito no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE mercadopagodebitos SET
            uuid        = :uuid,
            cuenta      = :cuenta,
            suscripcion = :suscripcion,
            referencia  = :referencia,
            recibo      = :recibo,
            fecha       = :fecha,
            concepto    = :concepto,
            monto       = :monto,
            operacion   = :operacion,
            estado      = :estado,
            propiedades = :propiedades
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'        => $p['uuid'],
        ':cuenta'      => $p['cuenta'],
        ':suscripcion' => $p['suscripcion'],
        ':referencia'  => $p['referencia'],
        ':recibo'      => $p['recibo'],
        ':fecha'       => $p['fecha'],
        ':concepto'    => $p['concepto'],
        ':monto'       => $p['monto'],
        ':operacion'   => $p['operacion'],
        ':estado'      => $p['estado'],
        ':propiedades' => $p['propiedades'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mercadopagodebitos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Débito no encontrado', 404);
    jsonOk(['id' => $id]);
}
