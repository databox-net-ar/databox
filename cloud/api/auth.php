<?php
// api/auth.php
// Autenticacion del panel. Sin roles ni autorizaciones todavia (STACK.md §5).
//
//   POST   api/auth.php?action=login    { correo, contrasena }  -> setea cookie databox_token + datos
//   POST   api/auth.php?action=logout                            -> borra cookie
//   GET    api/auth.php?action=me                                -> usuario actual (200) o 401
//
// Verificacion de contrasena: la columna `usuarios.contrasena` esta cifrada con
// encriptar()/desencriptar() (cifra reversible legacy del grupo). NO usar hashes.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

header('Content-Type: application/json; charset=utf-8');

const AUTH_COOKIE = 'databox_token';
const AUTH_TTL    = 28800; // 8 horas

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'login'  && $method === 'POST') { handleLogin(readJsonBody()); }
    elseif ($action === 'logout' && $method === 'POST') { handleLogout(); }
    elseif ($action === 'me'     && $method === 'GET')  { handleMe(); }
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
    ]);
}

function handleLogout(): void {
    clearAuthCookie();
    jsonOk(['logged_out' => true]);
}

function handleMe(): void {
    $payload = currentAuth();
    if (!$payload) jsonError('No autenticado', 401);
    jsonOk([
        'user' => [
            'id'     => (int)($payload['sub']    ?? 0),
            'uuid'   => $payload['uuid']   ?? null,
            'nombre' => $payload['nombre'] ?? null,
            'correo' => $payload['correo'] ?? null,
        ],
        'exp' => (int)($payload['exp'] ?? 0),
    ]);
}

// Devuelve el payload decodificado del JWT actual (cookie o header Authorization),
// o null si no hay sesion valida. Disponible para futuro requireAuth().
function currentAuth(): ?array {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if ($token === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if ($token === '') return null;
    return jwtDecode($token);
}

function requireAuth(): array {
    $p = currentAuth();
    if (!$p) jsonError('No autenticado', 401);
    return $p;
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
    setcookie(AUTH_COOKIE, $token, [
        'expires'  => time() + AUTH_TTL,
        'path'     => '/',
        'secure'   => $isProd,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAuthCookie(): void {
    $isProd = (getenv('APP_ENV') ?: 'development') === 'production';
    setcookie(AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isProd,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
