<?php
// api/awscuentas.php
// ABM de cuentas AWS. Lee/escribe sobre la tabla `awscuentas` definida en db/schema.sql.
//   GET    api/awscuentas.php          -> listado con filtros (query string)
//   GET    api/awscuentas.php?id=N     -> registro individual
//   POST   api/awscuentas.php          -> alta (JSON body)
//   PUT    api/awscuentas.php?id=N     -> modificacion (JSON body)
//   DELETE api/awscuentas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.aws.cuentas');
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
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $nombre    = trim((string)($q['nombre']    ?? ''));
    $numero    = trim((string)($q['numero']    ?? ''));
    $accesskey = trim((string)($q['accesskey'] ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'numero', 'accesskey', 'facturas_total', 'facturas_cantidad', 'facturas_actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'id = :codigo';                  $params[':codigo']    = $codigo; }
    if ($nombre    !== '')   { $where[] = 'nombre LIKE :nombre';           $params[':nombre']    = "%{$nombre}%"; }
    if ($numero    !== '')   { $where[] = 'numero LIKE :numero';           $params[':numero']    = "%{$numero}%"; }
    if ($accesskey !== '')   { $where[] = 'accesskey LIKE :accesskey';     $params[':accesskey'] = "%{$accesskey}%"; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s_nombre OR numero LIKE :s_numero OR accesskey LIKE :s_accesskey)';
        $params[':s_nombre']    = "%{$search}%";
        $params[':s_numero']    = "%{$search}%";
        $params[':s_accesskey'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("SELECT COUNT(*) AS total FROM awscuentas")->fetch();

    $sql = "
        SELECT id, nombre, numero, usuario, contrasena, accesskey, secreto,
               facturas_cantidad, facturas_total, facturas_moneda, facturas_actualizado
        FROM awscuentas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    // El listado NO incluye facturas_json (puede ser grande); el getOne si.
    $stmt = $pdo->prepare('
        SELECT id, nombre, numero, usuario, contrasena, accesskey, secreto,
               facturas_cantidad, facturas_total, facturas_moneda, facturas_actualizado,
               facturas_json
        FROM awscuentas WHERE id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Cuenta AWS no encontrada', 404);
    // PDO devuelve JSON como string; lo decodificamos para que el front lo
    // reciba como objeto anidado directamente.
    if (!empty($row['facturas_json'])) {
        $decoded = json_decode($row['facturas_json'], true);
        $row['facturas_json'] = is_array($decoded) ? $decoded : null;
    } else {
        $row['facturas_json'] = null;
    }
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function sanitizePayload(array $in): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);

    return [
        'nombre'     => $nombre,
        'numero'     => trim((string)($in['numero']     ?? '')) ?: null,
        'usuario'    => trim((string)($in['usuario']    ?? '')) ?: null,
        'contrasena' => trim((string)($in['contrasena'] ?? '')) ?: null,
        'accesskey'  => trim((string)($in['accesskey']  ?? '')) ?: null,
        'secreto'    => trim((string)($in['secreto']    ?? '')) ?: null,
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $stmt = $pdo->prepare('
        INSERT INTO awscuentas (nombre, numero, usuario, contrasena, accesskey, secreto)
        VALUES (:nombre, :numero, :usuario, :contrasena, :accesskey, :secreto)
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':numero'     => $p['numero'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':accesskey'  => $p['accesskey'],
        ':secreto'    => $p['secreto'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM awscuentas WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Cuenta AWS no encontrada', 404);

    $p = sanitizePayload($in);
    $stmt = $pdo->prepare('
        UPDATE awscuentas
           SET nombre     = :nombre,
               numero     = :numero,
               usuario    = :usuario,
               contrasena = :contrasena,
               accesskey  = :accesskey,
               secreto    = :secreto
         WHERE id = :id
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':numero'     => $p['numero'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':accesskey'  => $p['accesskey'],
        ':secreto'    => $p['secreto'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM awscuentas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Cuenta AWS no encontrada', 404);
    jsonOk(['id' => $id]);
}
