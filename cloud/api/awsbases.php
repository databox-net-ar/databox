<?php
// api/awsbases.php
// ABM de bases de datos AWS. Lee/escribe sobre la tabla `aws_bases`.
//   GET    api/awsbases.php          -> listado con filtros (query string)
//   GET    api/awsbases.php?id=N     -> registro individual
//   POST   api/awsbases.php          -> alta (JSON body)
//   PUT    api/awsbases.php?id=N     -> modificacion (JSON body)
//   DELETE api/awsbases.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.aws.bases_datos');
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
// Listado
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $nombre    = trim((string)($q['nombre']    ?? ''));
    $motor     = trim((string)($q['motor']     ?? ''));
    $host      = trim((string)($q['host']      ?? ''));
    $base      = trim((string)($q['base']      ?? ''));
    $cuentaId  = isset($q['cuenta_id']) && $q['cuenta_id'] !== '' ? (int)$q['cuenta_id'] : null;
    $estado    = trim((string)($q['estado']    ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'motor', 'host', 'puerto', 'base', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 'b.id = :codigo';           $params[':codigo']    = $codigo; }
    if ($nombre   !== '')   { $where[] = 'b.nombre LIKE :nombre';    $params[':nombre']    = "%{$nombre}%"; }
    if ($motor    !== '')   { $where[] = 'b.motor = :motor';         $params[':motor']     = $motor; }
    if ($host     !== '')   { $where[] = 'b.host LIKE :host';        $params[':host']      = "%{$host}%"; }
    if ($base     !== '')   { $where[] = 'b.base LIKE :base';        $params[':base']      = "%{$base}%"; }
    if ($cuentaId !== null) { $where[] = 'b.cuenta_id = :cuenta_id'; $params[':cuenta_id'] = $cuentaId; }
    if ($estado   !== '')   { $where[] = 'b.estado = :estado';       $params[':estado']    = $estado; }

    if ($search !== '') {
        $where[] = '(b.nombre LIKE :s_n OR b.host LIKE :s_h OR b.base LIKE :s_b OR b.motor LIKE :s_m)';
        $params[':s_n'] = "%{$search}%";
        $params[':s_h'] = "%{$search}%";
        $params[':s_b'] = "%{$search}%";
        $params[':s_m'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalGlobal  = (int)$pdo->query("SELECT COUNT(*) FROM aws_bases")->fetchColumn();
    $activosGlobal = (int)$pdo->query(
        "SELECT COUNT(*) FROM aws_bases WHERE estado = '1'"
    )->fetchColumn();

    $sql = "
        SELECT b.id, b.nombre, b.motor, b.host, b.puerto, b.base, b.usuario,
               b.cuenta_id, b.estado, b.actualizado,
               c.nombre AS cuenta_nombre
        FROM aws_bases b
        LEFT JOIN aws_cuentas c ON c.id = b.cuenta_id
        {$sqlWhere}
        ORDER BY b.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'   => $totalGlobal,
            'activos' => $activosGlobal,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('
        SELECT b.id, b.nombre, b.motor, b.host, b.puerto, b.base, b.usuario,
               b.contrasena, b.cuenta_id, b.estado, b.notas, b.actualizado,
               c.nombre AS cuenta_nombre
        FROM aws_bases b
        LEFT JOIN aws_cuentas c ON c.id = b.cuenta_id
        WHERE b.id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Base AWS no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function sanitizePayload(array $in): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);

    $cuentaId = $in['cuenta_id'] ?? null;
    if ($cuentaId === '' || $cuentaId === 0 || $cuentaId === '0') $cuentaId = null;
    $cuentaId = $cuentaId !== null ? (int)$cuentaId : null;

    $puerto = $in['puerto'] ?? null;
    if ($puerto === '' || $puerto === null) {
        $puerto = null;
    } else {
        $puerto = (int)$puerto;
        if ($puerto < 1 || $puerto > 65535) jsonError('Puerto invalido (1-65535)', 400);
    }

    $estado = trim((string)($in['estado'] ?? '1'));
    if (!in_array($estado, ['0', '1'], true)) $estado = '1';

    return [
        'nombre'     => $nombre,
        'motor'      => trim((string)($in['motor']      ?? '')) ?: null,
        'host'       => trim((string)($in['host']       ?? '')) ?: null,
        'puerto'     => $puerto,
        'base'       => trim((string)($in['base']       ?? '')) ?: null,
        'usuario'    => trim((string)($in['usuario']    ?? '')) ?: null,
        'contrasena' => trim((string)($in['contrasena'] ?? '')) ?: null,
        'cuenta_id'  => $cuentaId,
        'estado'     => $estado,
        'notas'      => trim((string)($in['notas']      ?? '')) ?: null,
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $stmt = $pdo->prepare('
        INSERT INTO aws_bases (
            nombre, motor, host, puerto, base,
            usuario, contrasena, cuenta_id, estado, notas
        ) VALUES (
            :nombre, :motor, :host, :puerto, :base,
            :usuario, :contrasena, :cuenta_id, :estado, :notas
        )
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':motor'      => $p['motor'],
        ':host'       => $p['host'],
        ':puerto'     => $p['puerto'],
        ':base'       => $p['base'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':cuenta_id'  => $p['cuenta_id'],
        ':estado'     => $p['estado'],
        ':notas'      => $p['notas'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM aws_bases WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Base AWS no encontrada', 404);

    $p = sanitizePayload($in);
    $stmt = $pdo->prepare('
        UPDATE aws_bases
           SET nombre     = :nombre,
               motor      = :motor,
               host       = :host,
               puerto     = :puerto,
               base       = :base,
               usuario    = :usuario,
               contrasena = :contrasena,
               cuenta_id  = :cuenta_id,
               estado     = :estado,
               notas      = :notas
         WHERE id = :id
    ');
    $stmt->execute([
        ':nombre'     => $p['nombre'],
        ':motor'      => $p['motor'],
        ':host'       => $p['host'],
        ':puerto'     => $p['puerto'],
        ':base'       => $p['base'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'],
        ':cuenta_id'  => $p['cuenta_id'],
        ':estado'     => $p['estado'],
        ':notas'      => $p['notas'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_bases WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Base AWS no encontrada', 404);
    jsonOk(['id' => $id]);
}
