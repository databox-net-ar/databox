<?php
// api/auth.php
// Autenticacion del panel.
//
//   POST   api/auth.php?action=login    { correo, contrasena }  -> setea cookie databox_token + datos + perms
//   POST   api/auth.php?action=logout                            -> borra cookie
//   GET    api/auth.php?action=me                                -> usuario actual + perms (200) o 401
//   GET    api/auth.php?action=magic&token=XX                    -> canjea magic link (invitacion), setea cookie y redirige a /
//
// Verificacion de contrasena: la columna `usuarios.contrasena` esta cifrada con
// encriptar()/desencriptar() (cifra reversible legacy del grupo). NO usar hashes.
//
// Permisos: se resuelven en cada respuesta (login y me) contra la BD; NO viven
// en el JWT para evitar cookies enormes y para que cambios de roles impacten
// al siguiente boot del SPA sin necesidad de re-login. Ver
// `computePermisosUsuario()` en lib/auth_check.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'login'  && $method === 'POST') { handleLogin(readJsonBody()); }
    elseif ($action === 'logout' && $method === 'POST') { handleLogout(); }
    elseif ($action === 'me'     && $method === 'GET')  { handleMe(); }
    elseif ($action === 'magic'  && $method === 'GET')  { handleMagic(); }
    else { jsonError('Accion no soportada', 405); }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function handleLogin(array $in): void {
    $correo     = trim((string)($in['correo']     ?? ''));
    $contrasena = (string)($in['contrasena'] ?? '');
    if ($correo === '' || $contrasena === '') {
        jsonError('Correo y contrasena son obligatorios', 400);
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT id, uuid, nombre, correo, contrasena, estado
         FROM usuarios
         WHERE correo = :correo
         LIMIT 1'
    );
    $stmt->execute([':correo' => $correo]);
    $u = $stmt->fetch();

    if (!$u || (string)$u['contrasena'] === '' || desencriptar((string)$u['contrasena']) !== $contrasena) {
        jsonError('Credenciales invalidas', 401);
    }
    if ((string)($u['estado'] ?? '') !== '1') {
        jsonError('Usuario deshabilitado', 403);
    }

    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE usuarios SET ingresado = :i WHERE id = :id')
        ->execute([':i' => $ahora, ':id' => (int)$u['id']]);

    $token = jwtEncode([
        'sub'    => (int)$u['id'],
        'uuid'   => $u['uuid'],
        'nombre' => $u['nombre'],
        'correo' => $u['correo'],
    ], AUTH_TTL);

    setAuthCookie($token);

    jsonOk([
        'token' => $token,
        'user'  => publicUser($u),
        'perms' => computePermisosUsuario($pdo, (int)$u['id']),
    ]);
}

function handleLogout(): void {
    clearAuthCookie();
    jsonOk(['logged_out' => true]);
}

function handleMe(): void {
    $payload = currentAuth();
    if (!$payload) jsonError('No autenticado', 401);
    $userId = (int)($payload['sub'] ?? 0);
    jsonOk([
        'user' => [
            'id'     => $userId,
            'uuid'   => $payload['uuid']   ?? null,
            'nombre' => $payload['nombre'] ?? null,
            'correo' => $payload['correo'] ?? null,
        ],
        'perms' => $userId > 0 ? computePermisosUsuario(db(), $userId) : [],
        'exp'   => (int)($payload['exp'] ?? 0),
    ]);
}

// Canje del magic link generado por api/usuarios_invitar.php. Es una peticion
// GET desde el navegador (usuario clickea el link del mail), asi que a
// diferencia de las otras acciones responde con `Location:` — no JSON.
function handleMagic(): void {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') { magicRedirectError('Enlace invalido'); return; }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT i.id AS inv_id, i.usuario, i.expira, i.usado, i.un_solo_uso,
                u.uuid, u.nombre, u.correo, u.estado
           FROM usuarios_invitaciones i
           JOIN usuarios u ON u.id = i.usuario
          WHERE i.token = :t
          LIMIT 1'
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();

    if (!$row) { magicRedirectError('Enlace invalido'); return; }

    // `un_solo_uso` distingue los dos usos del magic link:
    //   - '1' (default) invitacion por mail: se quema al primer canje.
    //   - '0'           iniciar sesion como: multi-uso dentro de `expira`.
    $unSoloUso = (string)($row['un_solo_uso'] ?? '1') === '1';

    if ($unSoloUso && !empty($row['usado']))            { magicRedirectError('Este enlace ya fue usado'); return; }
    if (strtotime((string)$row['expira']) < time())     { magicRedirectError('Este enlace expiro'); return; }
    if ((string)($row['estado'] ?? '') !== '1')         { magicRedirectError('Usuario deshabilitado'); return; }

    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
             ->format('Y-m-d H:i:s');

    // Registramos el uso ANTES de emitir la cookie: para links de un solo uso,
    // preferimos "consumida y no logueado" antes que "no consumida y logueado"
    // (asi el link no queda reutilizable). Para multi-uso, `usado` queda como
    // el timestamp del ultimo acceso.
    $pdo->prepare('UPDATE usuarios_invitaciones SET usado = :now WHERE id = :id')
        ->execute([':now' => $ahora, ':id' => (int)$row['inv_id']]);

    $pdo->prepare('UPDATE usuarios SET ingresado = :i WHERE id = :id')
        ->execute([':i' => $ahora, ':id' => (int)$row['usuario']]);

    $jwt = jwtEncode([
        'sub'    => (int)$row['usuario'],
        'uuid'   => $row['uuid'],
        'nombre' => $row['nombre'],
        'correo' => $row['correo'],
    ], AUTH_TTL);
    setAuthCookie($jwt);

    header('Location: /', true, 302);
    exit;
}

function magicRedirectError(string $msg): void {
    header('Location: /?login_error=' . rawurlencode($msg), true, 302);
    exit;
}

function publicUser(array $row): array {
    return [
        'id'     => (int)$row['id'],
        'uuid'   => $row['uuid']   ?? null,
        'nombre' => $row['nombre'] ?? null,
        'correo' => $row['correo'] ?? null,
    ];
}

function setAuthCookie(string $token): void {
    $isProd = (getenv('APP_ENV') ?: 'development') === 'production';
    setcookie(AUTH_COOKIE_NAME, $token, [
        'expires'  => time() + AUTH_TTL,
        'path'     => '/',
        'secure'   => $isProd,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAuthCookie(): void {
    $isProd = (getenv('APP_ENV') ?: 'development') === 'production';
    setcookie(AUTH_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isProd,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
