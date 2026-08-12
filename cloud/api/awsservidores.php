<?php
// api/awsservidores.php
// ABM de servidores AWS. Lee/escribe sobre la tabla `aws_servidores`.
//   GET    api/awsservidores.php          -> listado con filtros (query string)
//   GET    api/awsservidores.php?id=N     -> registro individual
//   POST   api/awsservidores.php          -> alta (JSON body)
//   PUT    api/awsservidores.php?id=N     -> modificacion (JSON body)
//   DELETE api/awsservidores.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.aws.servidores');
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
    $host      = trim((string)($q['host']      ?? ''));
    $region    = trim((string)($q['region']    ?? ''));
    $cuentaId  = isset($q['cuenta_id']) && $q['cuenta_id'] !== '' ? (int)$q['cuenta_id'] : null;
    $existe    = trim((string)($q['existe']    ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'host', 'ip', 'region', 'tipo_instancia', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 's.id = :codigo';                    $params[':codigo']    = $codigo; }
    if ($nombre   !== '')   { $where[] = 's.nombre LIKE :nombre';             $params[':nombre']    = "%{$nombre}%"; }
    if ($host     !== '')   { $where[] = '(s.host LIKE :host OR s.ip LIKE :host2)'; $params[':host'] = "%{$host}%"; $params[':host2'] = "%{$host}%"; }
    if ($region   !== '')   { $where[] = 's.region = :region';                $params[':region']    = $region; }
    if ($cuentaId !== null) { $where[] = 's.cuenta_id = :cuenta_id';          $params[':cuenta_id'] = $cuentaId; }
    if ($existe   !== '')   { $where[] = 's.existe = :existe';                $params[':existe']    = $existe; }

    if ($search !== '') {
        $where[] = '(s.nombre LIKE :s_n OR s.host LIKE :s_h OR s.ip LIKE :s_i OR s.region LIKE :s_r)';
        $params[':s_n'] = "%{$search}%";
        $params[':s_h'] = "%{$search}%";
        $params[':s_i'] = "%{$search}%";
        $params[':s_r'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalGlobal    = (int)$pdo->query("SELECT COUNT(*) FROM aws_servidores")->fetchColumn();
    $existenGlobal  = (int)$pdo->query(
        "SELECT COUNT(*) FROM aws_servidores WHERE existe = '1'"
    )->fetchColumn();

    $sql = "
        SELECT s.id, s.nombre, s.host, s.ip, s.region, s.tipo_instancia,
               s.estado_ec2, s.sistema_operativo, s.cpu, s.memoria,
               s.almacenamiento, s.usuario_ssh, s.cuenta_id, s.existe, s.notas,
               s.actualizado,
               c.nombre AS cuenta_nombre
        FROM aws_servidores s
        LEFT JOIN aws_cuentas c ON c.id = s.cuenta_id
        {$sqlWhere}
        ORDER BY s.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'    => $totalGlobal,
            'existen'  => $existenGlobal,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('
        SELECT s.id, s.nombre, s.host, s.ip, s.region, s.tipo_instancia,
               s.estado_ec2, s.sistema_operativo, s.cpu, s.memoria,
               s.almacenamiento, s.usuario_ssh, s.contrasena_ssh, s.cuenta_id,
               s.existe, s.notas, s.actualizado,
               c.nombre AS cuenta_nombre
        FROM aws_servidores s
        LEFT JOIN aws_cuentas c ON c.id = s.cuenta_id
        WHERE s.id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Servidor AWS no encontrado', 404);
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

    // cpu (vCPUs, entero) y memoria (MiB, entero) son opcionales: string vacio
    // o valor no numerico -> NULL. Negativos se limpian a NULL tambien.
    $cpu     = $in['cpu']     ?? null;
    $memoria = $in['memoria'] ?? null;
    $cpu     = ($cpu     !== null && $cpu     !== '' && is_numeric($cpu))     ? max(0, (int)$cpu)     : null;
    $memoria = ($memoria !== null && $memoria !== '' && is_numeric($memoria)) ? max(0, (int)$memoria) : null;
    if ($cpu     === 0) $cpu     = null;
    if ($memoria === 0) $memoria = null;

    return [
        'nombre'            => $nombre,
        'host'              => trim((string)($in['host']              ?? '')) ?: null,
        'ip'                => trim((string)($in['ip']                ?? '')) ?: null,
        'region'            => trim((string)($in['region']            ?? '')) ?: null,
        'tipo_instancia'    => trim((string)($in['tipo_instancia']    ?? '')) ?: null,
        'sistema_operativo' => trim((string)($in['sistema_operativo'] ?? '')) ?: null,
        'cpu'               => $cpu,
        'memoria'           => $memoria,
        'almacenamiento'    => trim((string)($in['almacenamiento']    ?? '')) ?: null,
        'usuario_ssh'       => trim((string)($in['usuario_ssh']       ?? '')) ?: null,
        'contrasena_ssh'    => trim((string)($in['contrasena_ssh']    ?? '')) ?: null,
        'cuenta_id'         => $cuentaId,
        'notas'             => trim((string)($in['notas']             ?? '')) ?: null,
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $stmt = $pdo->prepare('
        INSERT INTO aws_servidores (
            nombre, host, ip, region, tipo_instancia, sistema_operativo,
            cpu, memoria, almacenamiento, usuario_ssh, contrasena_ssh,
            cuenta_id, notas
        ) VALUES (
            :nombre, :host, :ip, :region, :tipo_instancia, :sistema_operativo,
            :cpu, :memoria, :almacenamiento, :usuario_ssh, :contrasena_ssh,
            :cuenta_id, :notas
        )
    ');
    $stmt->execute([
        ':nombre'            => $p['nombre'],
        ':host'              => $p['host'],
        ':ip'                => $p['ip'],
        ':region'            => $p['region'],
        ':tipo_instancia'    => $p['tipo_instancia'],
        ':sistema_operativo' => $p['sistema_operativo'],
        ':cpu'               => $p['cpu'],
        ':memoria'           => $p['memoria'],
        ':almacenamiento'    => $p['almacenamiento'],
        ':usuario_ssh'       => $p['usuario_ssh'],
        ':contrasena_ssh'    => $p['contrasena_ssh'],
        ':cuenta_id'         => $p['cuenta_id'],
        ':notas'             => $p['notas'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM aws_servidores WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Servidor AWS no encontrado', 404);

    // Update parcial: solo actualizamos los campos que el operador puede
    // editar a mano desde el modal (usuario_ssh / contrasena_ssh / notas).
    // El resto de las columnas (nombre / host / ip / region / tipo /
    // cpu / memoria / almacenamiento / sistema_operativo / estado_ec2 /
    // cuenta_id / instance_id) las maneja exclusivamente el boton
    // "Obtener" (awsservidores_obtener.php) — el modal Editar ya no expone
    // esos campos para evitar que un guardado pise datos que vinieron
    // autoritativamente desde AWS.
    $usuarioSsh    = trim((string)($in['usuario_ssh']    ?? '')) ?: null;
    $contrasenaSsh = trim((string)($in['contrasena_ssh'] ?? '')) ?: null;
    $notas         = trim((string)($in['notas']          ?? '')) ?: null;

    $stmt = $pdo->prepare('
        UPDATE aws_servidores
           SET usuario_ssh    = :usuario_ssh,
               contrasena_ssh = :contrasena_ssh,
               notas          = :notas
         WHERE id = :id
    ');
    $stmt->execute([
        ':usuario_ssh'    => $usuarioSsh,
        ':contrasena_ssh' => $contrasenaSsh,
        ':notas'          => $notas,
        ':id'             => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_servidores WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Servidor AWS no encontrado', 404);
    jsonOk(['id' => $id]);
}
