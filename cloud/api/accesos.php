<?php
// api/accesos.php
// ABM de accesos: credenciales para sistemas externos (Movistar Kite, Claro
// Portal, paneles de proveedores, consolas de dominios, etc.). Lee/escribe
// sobre la tabla `accesos` definida en db/schema.sql.
//
//   GET    api/accesos.php             -> listado con filtros (query string)
//   GET    api/accesos.php?id=N        -> registro individual (contrasena en claro)
//   POST   api/accesos.php             -> alta (JSON body)
//   PUT    api/accesos.php?id=N        -> modificacion (JSON body)
//   DELETE api/accesos.php?id=N        -> baja
//
// La contrasena se guarda con la cifra reversible legacy del grupo
// (`encriptar/desencriptar` de db.php) igual que `usuarios.contrasena`, para
// que el operador pueda copiarla en claro desde la UI. Nunca sale de este
// endpoint sin ser descifrada primero: el listado la reemplaza por un
// placeholder ('***') y solo el GET por id devuelve el valor en claro.
//
// El campo `actualizado` es un timestamp automatico (ON UPDATE CURRENT_TIMESTAMP)
// que refleja cuando se toco la fila por ultima vez. No se acepta desde el
// payload — lo maneja la BD.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

const ACCESOS_ORDENES = ['id', 'nombre', 'url', 'usuario', 'actualizado', 'privado', 'empresa_id'];
// Columnas con alias `a.` porque hacemos LEFT JOIN con `datacount_empresas`
// para traer el nombre de la empresa en el mismo query.
const ACCESOS_COLS    = 'a.id, a.empresa_id, a.nombre, a.url, a.usuario, a.contrasena, a.privado, a.actualizado, e.nombre AS empresa_nombre';
const ACCESOS_FROM    = 'accesos a LEFT JOIN datacount_empresas e ON e.id = a.empresa_id';

try {
    requirePermCrud('seguridad.accesos');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Catalogo de empresas para el select del formulario (mismo permiso).
    if ($method === 'GET' && ($_GET['listar'] ?? '') === 'empresas') {
        handleListarEmpresas($pdo);
    } elseif ($method === 'GET' && $id > 0) {
        handleGetOneAcceso($pdo, $id);
    } elseif ($method === 'GET') {
        handleListAccesos($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateAcceso($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateAcceso($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteAcceso($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

// Normaliza una fila para el listado — enmascara la contrasena.
function normalizarFilaAccesoListado(array $r): array {
    return [
        'id'             => (int)($r['id'] ?? 0),
        'empresa_id'     => $r['empresa_id'] !== null ? (int)$r['empresa_id'] : null,
        'empresa_nombre' => $r['empresa_nombre'] !== null ? (string)$r['empresa_nombre'] : null,
        'nombre'         => (string)($r['nombre'] ?? ''),
        'url'            => $r['url']     !== null ? (string)$r['url']     : null,
        'usuario'        => $r['usuario'] !== null ? (string)$r['usuario'] : null,
        'contrasena'     => $r['contrasena'] !== null && $r['contrasena'] !== '' ? '***' : null,
        'privado'        => (int)($r['privado'] ?? 0) === 1 ? 1 : 0,
        'actualizado'    => $r['actualizado'] ?? null,
    ];
}

// Devuelve una fila con la contrasena descifrada — solo para GET por id.
function normalizarFilaAccesoDetalle(array $r): array {
    $pass = '';
    if ($r['contrasena'] !== null && $r['contrasena'] !== '') {
        $pass = desencriptar((string)$r['contrasena']);
    }
    return [
        'id'             => (int)($r['id'] ?? 0),
        'empresa_id'     => $r['empresa_id'] !== null ? (int)$r['empresa_id'] : null,
        'empresa_nombre' => $r['empresa_nombre'] !== null ? (string)$r['empresa_nombre'] : null,
        'nombre'         => (string)($r['nombre'] ?? ''),
        'url'            => $r['url']     !== null ? (string)$r['url']     : null,
        'usuario'        => $r['usuario'] !== null ? (string)$r['usuario'] : null,
        'contrasena'     => $pass,
        'privado'        => (int)($r['privado'] ?? 0) === 1 ? 1 : 0,
        'actualizado'    => $r['actualizado'] ?? null,
    ];
}

function sanitizePayloadAcceso(array $in, bool $esAlta): array {
    $nombre  = trim((string)($in['nombre']  ?? ''));
    $url     = trim((string)($in['url']     ?? ''));
    $usuario = trim((string)($in['usuario'] ?? ''));
    // La contrasena NO se trimea — puede tener espacios validos.
    $tienePass = array_key_exists('contrasena', $in);
    $pass      = $tienePass ? (string)$in['contrasena'] : '';
    $privado   = (int)(!empty($in['privado']) && $in['privado'] !== '0' && $in['privado'] !== false ? 1 : 0);

    if ($esAlta && $nombre === '') {
        jsonError('El nombre es obligatorio.', 400);
    }
    if ($nombre !== '' && mb_strlen($nombre) > 200) {
        jsonError('El nombre no puede superar los 200 caracteres.', 400);
    }
    if ($url !== '' && mb_strlen($url) > 500) {
        jsonError('La URL no puede superar los 500 caracteres.', 400);
    }
    if ($usuario !== '' && mb_strlen($usuario) > 200) {
        jsonError('El usuario no puede superar los 200 caracteres.', 400);
    }
    if ($pass !== '' && mb_strlen($pass) > 200) {
        jsonError('La contrasena no puede superar los 200 caracteres.', 400);
    }

    // empresa_id opcional; se acepta '', null, 0 como "sin empresa".
    $empresaId = null;
    if (array_key_exists('empresa_id', $in)) {
        $raw = $in['empresa_id'];
        if ($raw !== '' && $raw !== null) {
            $empresaId = (int)$raw;
            if ($empresaId <= 0) $empresaId = null;
        }
    }

    $out = [
        'nombre'     => $nombre,
        'url'        => $url === '' ? null : $url,
        'usuario'    => $usuario === '' ? null : $usuario,
        'privado'    => $privado,
        'empresa_id' => $empresaId,
    ];

    // Solo tocamos contrasena si vino en el payload. En edicion, si el operador
    // no toco el campo, mantenemos la que ya estaba (no la borramos).
    if ($esAlta) {
        $out['contrasena'] = $pass !== '' ? encriptar($pass) : null;
    } elseif ($tienePass) {
        $out['contrasena'] = $pass !== '' ? encriptar($pass) : null;
    }

    return $out;
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListarEmpresas(PDO $pdo): void {
    $rows = $pdo->query('SELECT id, nombre FROM datacount_empresas ORDER BY nombre ASC')->fetchAll();
    $out = array_map(fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']], $rows);
    jsonOk(['items' => $out]);
}

function handleListAccesos(PDO $pdo, array $q): void {
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $search    = trim((string)($q['q']          ?? ''));
    $privado   = trim((string)($q['privado']    ?? ''));
    $empresaId = isset($q['empresa_id']) && $q['empresa_id'] !== '' ? (int)$q['empresa_id'] : null;

    $orden  = in_array(($q['order_by'] ?? ''), ACCESOS_ORDENES, true) ? $q['order_by'] : 'id';
    $dir    = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $limite = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'a.id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($search !== '') {
        $where[] = '(a.nombre LIKE :s_nom OR a.url LIKE :s_url OR a.usuario LIKE :s_usr OR e.nombre LIKE :s_emp)';
        $like = "%{$search}%";
        $params[':s_nom'] = $like;
        $params[':s_url'] = $like;
        $params[':s_usr'] = $like;
        $params[':s_emp'] = $like;
    }
    if ($privado === '1' || $privado === '0') {
        $where[] = 'a.privado = :privado';
        $params[':privado'] = (int)$privado;
    }
    if ($empresaId !== null && $empresaId > 0) {
        $where[] = 'a.empresa_id = :empresa_id';
        $params[':empresa_id'] = $empresaId;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query('
        SELECT
            COUNT(*)                                       AS total,
            SUM(CASE WHEN privado = 1 THEN 1 ELSE 0 END)   AS privados,
            SUM(CASE WHEN privado = 0 THEN 1 ELSE 0 END)   AS publicos
        FROM accesos
    ')->fetch();

    // El order_by usa el nombre sin alias — lo prefijamos con `a.` para que
    // no colisione con `e.nombre` en el JOIN.
    $orderCol = 'a.' . $orden;
    $sql = 'SELECT ' . ACCESOS_COLS . ' FROM ' . ACCESOS_FROM . " {$sqlWhere} ORDER BY {$orderCol} {$dir} LIMIT {$limite}";
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaAccesoListado', $st->fetchAll());

    jsonOk([
        'stats' => [
            'total'    => (int)($stats['total']    ?? 0),
            'publicos' => (int)($stats['publicos'] ?? 0),
            'privados' => (int)($stats['privados'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOneAcceso(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . ACCESOS_COLS . ' FROM ' . ACCESOS_FROM . ' WHERE a.id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Acceso no encontrado', 404);
    jsonOk(normalizarFilaAccesoDetalle($row));
}

function handleCreateAcceso(PDO $pdo, array $body): void {
    $p = sanitizePayloadAcceso($body, true);

    $st = $pdo->prepare(
        'INSERT INTO accesos (empresa_id, nombre, url, usuario, contrasena, privado)
         VALUES (:empresa_id, :nombre, :url, :usuario, :contrasena, :privado)'
    );
    $st->execute([
        ':empresa_id' => $p['empresa_id'],
        ':nombre'     => $p['nombre'],
        ':url'        => $p['url'],
        ':usuario'    => $p['usuario'],
        ':contrasena' => $p['contrasena'] ?? null,
        ':privado'    => $p['privado'],
    ]);
    $id = (int)$pdo->lastInsertId();

    registrarSuceso($pdo, 'accesos', 'info',
        "Alta acceso #{$id} \"{$p['nombre']}\"");

    handleGetOneAcceso($pdo, $id);
}

function handleUpdateAcceso(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT nombre FROM accesos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Acceso no encontrado', 404);

    $p = sanitizePayloadAcceso($body, false);

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('url', $body)) {
        $sets[] = 'url = :url';
        $params[':url'] = $p['url'];
    }
    if (array_key_exists('usuario', $body)) {
        $sets[] = 'usuario = :usuario';
        $params[':usuario'] = $p['usuario'];
    }
    if (array_key_exists('privado', $body)) {
        $sets[] = 'privado = :privado';
        $params[':privado'] = $p['privado'];
    }
    if (array_key_exists('empresa_id', $body)) {
        $sets[] = 'empresa_id = :empresa_id';
        $params[':empresa_id'] = $p['empresa_id'];
    }
    if (array_key_exists('contrasena', $p)) {
        $sets[] = 'contrasena = :contrasena';
        $params[':contrasena'] = $p['contrasena'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE accesos SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'accesos', 'info',
        "Modificacion acceso #{$id} \"{$prev['nombre']}\"");

    handleGetOneAcceso($pdo, $id);
}

function handleDeleteAcceso(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM accesos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Acceso no encontrado', 404);

    $sd = $pdo->prepare('DELETE FROM accesos WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'accesos', 'info',
        "Baja acceso #{$id} \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
