<?php
// api/usuarios.php
// ABM de usuarios. Lee/escribe sobre la tabla `usuarios` definida en db/schema.sql.
//   GET    api/usuarios.php             -> listado con filtros (query string)
//   GET    api/usuarios.php?id=N        -> registro individual
//   POST   api/usuarios.php             -> alta (JSON body)
//   PUT    api/usuarios.php?id=N        -> modificacion (JSON body)
//   DELETE api/usuarios.php?id=N        -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

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
    $codigo  = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $nombre  = trim((string)($q['nombre']  ?? ''));
    $dni     = trim((string)($q['dni']     ?? ''));
    $correo  = trim((string)($q['correo']  ?? ''));
    $celular = trim((string)($q['celular'] ?? ''));
    $estado  = trim((string)($q['estado']  ?? ''));
    $search  = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by']  ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'dni', 'correo', 'registrado', 'estado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo';            $params[':codigo'] = $codigo; }
    if ($nombre  !== '')  { $where[] = 'nombre  LIKE :nombre';    $params[':nombre']  = "%{$nombre}%"; }
    if ($dni     !== '')  { $where[] = 'dni     LIKE :dni';       $params[':dni']     = "%{$dni}%"; }
    if ($correo  !== '')  { $where[] = 'correo  LIKE :correo';    $params[':correo']  = "%{$correo}%"; }
    if ($celular !== '')  { $where[] = 'celular LIKE :celular';   $params[':celular'] = "%{$celular}%"; }
    if ($estado  !== '')  { $where[] = 'estado = :estado';        $params[':estado']  = $estado; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR correo LIKE :s OR dni LIKE :s OR celular LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    // estado: '1' = activo, '0' (o cualquier otro valor / NULL) = inactivo.
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                        AS total,
            SUM(CASE WHEN estado = '1' THEN 1 ELSE 0 END)   AS activos,
            SUM(CASE WHEN estado <> '1' OR estado IS NULL THEN 1 ELSE 0 END) AS inactivos
        FROM usuarios
    ")->fetch();

    $sql = "
        SELECT id, uuid, nombre, dni, nacimiento, celular, correo,
               registrado, ingresado, terminal, sistemas, roles, estado
        FROM usuarios
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'     => (int)($stats['total']     ?? 0),
            'activos'   => (int)($stats['activos']   ?? 0),
            'inactivos' => (int)($stats['inactivos'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("
        SELECT id, uuid, nombre, dni, nacimiento, celular, correo, contrasena,
               registrado, ingresado, terminal, sistemas, roles, estado
        FROM usuarios WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Usuario no encontrado', 404);
    // Devolvemos la contrasena en claro para que el ABM la muestre
    // (cifra reversible legacy — ver auth.php).
    $row['contrasena'] = $row['contrasena'] !== null && $row['contrasena'] !== ''
        ? desencriptar((string)$row['contrasena'])
        : '';
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function sanitizePayload(array $in, bool $isCreate): array {
    $nombre = trim((string)($in['nombre'] ?? ''));
    if ($nombre === '') jsonError('El nombre es obligatorio', 400);

    $estado = trim((string)($in['estado'] ?? '1'));
    if ($estado === '') $estado = '1';

    $payload = [
        'nombre'     => $nombre,
        'dni'        => trim((string)($in['dni']        ?? '')) ?: null,
        'nacimiento' => trim((string)($in['nacimiento'] ?? '')) ?: null,
        'celular'    => trim((string)($in['celular']    ?? '')) ?: null,
        'correo'     => trim((string)($in['correo']     ?? '')) ?: null,
        'sistemas'   => trim((string)($in['sistemas']   ?? '')) ?: null,
        'roles'      => trim((string)($in['roles']      ?? '')) ?: null,
        'estado'     => substr($estado, 0, 1),
    ];

    if ($isCreate) {
        $pass = trim((string)($in['contrasena'] ?? ''));
        $payload['contrasena'] = $pass !== '' ? encriptar($pass) : null;
    } elseif (isset($in['contrasena']) && trim((string)$in['contrasena']) !== '') {
        $payload['contrasena'] = encriptar(trim((string)$in['contrasena']));
    }

    return $payload;
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in, true);
    $p['uuid']       = bin2hex(random_bytes(16));
    $p['registrado'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO usuarios
            (uuid, nombre, dni, nacimiento, celular, correo, contrasena,
             registrado, sistemas, roles, estado)
        VALUES
            (:uuid, :nombre, :dni, :nacimiento, :celular, :correo, :contrasena,
             :registrado, :sistemas, :roles, :estado)
    ");
    $stmt->execute([
        ':uuid'       => $p['uuid'],
        ':nombre'     => $p['nombre'],
        ':dni'        => $p['dni'],
        ':nacimiento' => $p['nacimiento'],
        ':celular'    => $p['celular'],
        ':correo'     => $p['correo'],
        ':contrasena' => $p['contrasena'] ?? null,
        ':registrado' => $p['registrado'],
        ':sistemas'   => $p['sistemas'],
        ':roles'      => $p['roles'],
        ':estado'     => $p['estado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Usuario no encontrado', 404);

    $p = sanitizePayload($in, false);
    $fields = [
        'nombre = :nombre',
        'dni = :dni',
        'nacimiento = :nacimiento',
        'celular = :celular',
        'correo = :correo',
        'sistemas = :sistemas',
        'roles = :roles',
        'estado = :estado',
    ];
    $params = [
        ':nombre'     => $p['nombre'],
        ':dni'        => $p['dni'],
        ':nacimiento' => $p['nacimiento'],
        ':celular'    => $p['celular'],
        ':correo'     => $p['correo'],
        ':sistemas'   => $p['sistemas'],
        ':roles'      => $p['roles'],
        ':estado'     => $p['estado'],
        ':id'         => $id,
    ];
    if (array_key_exists('contrasena', $p)) {
        $fields[] = 'contrasena = :contrasena';
        $params[':contrasena'] = $p['contrasena'];
    }

    $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Usuario no encontrado', 404);
    jsonOk(['id' => $id]);
}
