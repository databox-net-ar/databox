<?php
// api/mercadopagosuscripciones.php
// ABM de suscripciones Mercadopago. Lee/escribe sobre la tabla
// `mercadopagosuscripciones` definida en db/schema.sql.
//   GET    api/mercadopagosuscripciones.php          -> listado con filtros (query string)
//   GET    api/mercadopagosuscripciones.php?id=N     -> registro individual
//   POST   api/mercadopagosuscripciones.php          -> alta (JSON body)
//   PUT    api/mercadopagosuscripciones.php?id=N     -> modificacion (JSON body)
//   DELETE api/mercadopagosuscripciones.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const MP_SUB_COLS = "id, uuid, cuenta, nombre, celular, correo, referencia,
                     concepto, monto, periodo, frecuencia,
                     pruebaPeriodo, pruebaFrecuencia, destino,
                     registrada, actualizada, iniciada, pausada, reactivada, finalizada,
                     estado, propiedades";

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
    $cuenta = isset($q['cuenta']) && $q['cuenta'] !== '' ? (int)$q['cuenta'] : null;
    $estado = trim((string)($q['estado'] ?? ''));
    $desde  = trim((string)($q['desde']  ?? ''));
    $hasta  = trim((string)($q['hasta']  ?? ''));
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'registrada', 'actualizada', 'iniciada', 'finalizada',
                     'cuenta', 'nombre', 'correo', 'concepto', 'monto', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';         $params[':codigo'] = $codigo; }
    if ($cuenta !== null) { $where[] = 'cuenta = :cuenta';     $params[':cuenta'] = $cuenta; }
    if ($estado !== '')   { $where[] = 'estado = :estado';     $params[':estado'] = $estado; }
    if ($desde  !== '')   { $where[] = 'registrada >= :desde'; $params[':desde']  = $desde . ' 00:00:00'; }
    if ($hasta  !== '')   { $where[] = 'registrada <= :hasta'; $params[':hasta']  = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR correo LIKE :s OR celular LIKE :s
                     OR referencia LIKE :s OR concepto LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                              AS total,
            SUM(CASE WHEN finalizada IS NULL THEN 1 ELSE 0 END)   AS activas,
            SUM(CASE WHEN pausada IS NOT NULL AND reactivada IS NULL AND finalizada IS NULL THEN 1 ELSE 0 END) AS pausadas
        FROM mercadopagosuscripciones
    ")->fetch();

    // Catálogo de estados existentes en la tabla — útil para poblar el select
    // de filtros porque `estado` es varchar(50) y no hay codificación fija.
    $estados = $pdo->query("
        SELECT DISTINCT estado FROM mercadopagosuscripciones
        WHERE estado IS NOT NULL AND estado <> ''
        ORDER BY estado ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $sql = "
        SELECT " . MP_SUB_COLS . "
        FROM mercadopagosuscripciones
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'    => (int)($stats['total']    ?? 0),
            'activas'  => (int)($stats['activas']  ?? 0),
            'pausadas' => (int)($stats['pausadas'] ?? 0),
        ],
        'estados' => $estados,
        'items'   => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . MP_SUB_COLS . " FROM mercadopagosuscripciones WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Suscripción no encontrada', 404);
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
        'uuid'             => nullableStr($in['uuid']             ?? null, 100),
        'cuenta'           => nullableInt($in['cuenta']           ?? null),
        'nombre'           => nullableStr($in['nombre']           ?? null, 100),
        'celular'          => nullableStr($in['celular']          ?? null, 100),
        'correo'           => nullableStr($in['correo']           ?? null, 100),
        'referencia'       => nullableStr($in['referencia']       ?? null, 100),
        'concepto'         => nullableStr($in['concepto']         ?? null, 255),
        'monto'            => nullableDec($in['monto']            ?? null),
        'periodo'          => nullableStr($in['periodo']          ?? null, 10),
        'frecuencia'       => nullableStr($in['frecuencia']       ?? null, 10),
        'pruebaPeriodo'    => nullableStr($in['pruebaPeriodo']    ?? null, 10),
        'pruebaFrecuencia' => nullableStr($in['pruebaFrecuencia'] ?? null, 10),
        'destino'          => nullableStr($in['destino']          ?? null, 1000),
        'registrada'       => nullableDateTime($in['registrada']  ?? null),
        'actualizada'      => nullableDateTime($in['actualizada'] ?? null),
        'iniciada'         => nullableDateTime($in['iniciada']    ?? null),
        'pausada'          => nullableDateTime($in['pausada']     ?? null),
        'reactivada'       => nullableDateTime($in['reactivada']  ?? null),
        'finalizada'       => nullableDateTime($in['finalizada']  ?? null),
        'estado'           => nullableStr($in['estado']           ?? null, 50),
        'propiedades'      => nullableStr($in['propiedades']      ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['uuid'] === null) {
        $p['uuid'] = substr(bin2hex(random_bytes(16)), 0, 32);
    }
    if ($p['registrada'] === null) {
        $p['registrada'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                           ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO mercadopagosuscripciones
            (uuid, cuenta, nombre, celular, correo, referencia,
             concepto, monto, periodo, frecuencia,
             pruebaPeriodo, pruebaFrecuencia, destino,
             registrada, actualizada, iniciada, pausada, reactivada, finalizada,
             estado, propiedades)
        VALUES
            (:uuid, :cuenta, :nombre, :celular, :correo, :referencia,
             :concepto, :monto, :periodo, :frecuencia,
             :pruebaPeriodo, :pruebaFrecuencia, :destino,
             :registrada, :actualizada, :iniciada, :pausada, :reactivada, :finalizada,
             :estado, :propiedades)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'             => $p['uuid'],
        ':cuenta'           => $p['cuenta'],
        ':nombre'           => $p['nombre'],
        ':celular'          => $p['celular'],
        ':correo'           => $p['correo'],
        ':referencia'       => $p['referencia'],
        ':concepto'         => $p['concepto'],
        ':monto'            => $p['monto'],
        ':periodo'          => $p['periodo'],
        ':frecuencia'       => $p['frecuencia'],
        ':pruebaPeriodo'    => $p['pruebaPeriodo'],
        ':pruebaFrecuencia' => $p['pruebaFrecuencia'],
        ':destino'          => $p['destino'],
        ':registrada'       => $p['registrada'],
        ':actualizada'      => $p['actualizada'],
        ':iniciada'         => $p['iniciada'],
        ':pausada'          => $p['pausada'],
        ':reactivada'       => $p['reactivada'],
        ':finalizada'       => $p['finalizada'],
        ':estado'           => $p['estado'],
        ':propiedades'      => $p['propiedades'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM mercadopagosuscripciones WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Suscripción no encontrada', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE mercadopagosuscripciones SET
            uuid             = :uuid,
            cuenta           = :cuenta,
            nombre           = :nombre,
            celular          = :celular,
            correo           = :correo,
            referencia       = :referencia,
            concepto         = :concepto,
            monto            = :monto,
            periodo          = :periodo,
            frecuencia       = :frecuencia,
            pruebaPeriodo    = :pruebaPeriodo,
            pruebaFrecuencia = :pruebaFrecuencia,
            destino          = :destino,
            registrada       = :registrada,
            actualizada      = :actualizada,
            iniciada         = :iniciada,
            pausada          = :pausada,
            reactivada       = :reactivada,
            finalizada       = :finalizada,
            estado           = :estado,
            propiedades      = :propiedades
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'             => $p['uuid'],
        ':cuenta'           => $p['cuenta'],
        ':nombre'           => $p['nombre'],
        ':celular'          => $p['celular'],
        ':correo'           => $p['correo'],
        ':referencia'       => $p['referencia'],
        ':concepto'         => $p['concepto'],
        ':monto'            => $p['monto'],
        ':periodo'          => $p['periodo'],
        ':frecuencia'       => $p['frecuencia'],
        ':pruebaPeriodo'    => $p['pruebaPeriodo'],
        ':pruebaFrecuencia' => $p['pruebaFrecuencia'],
        ':destino'          => $p['destino'],
        ':registrada'       => $p['registrada'],
        ':actualizada'      => $p['actualizada'],
        ':iniciada'         => $p['iniciada'],
        ':pausada'          => $p['pausada'],
        ':reactivada'       => $p['reactivada'],
        ':finalizada'       => $p['finalizada'],
        ':estado'           => $p['estado'],
        ':propiedades'      => $p['propiedades'],
        ':id'               => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM mercadopagosuscripciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Suscripción no encontrada', 404);
    jsonOk(['id' => $id]);
}
