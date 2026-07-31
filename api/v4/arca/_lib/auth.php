<?php
// api/v4/arca/_lib/auth.php
// Auth Bearer contra la tabla `aplicaciones` -- mismo esquema que
// /v4/telegram y /v4/evolution. Espeja readBearer() + requireApp() para no
// depender del scope global del endpoint.

declare(strict_types=1);

function arcaReadBearer(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    return stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
}

function arcaRequireApp(): array {
    $token = arcaReadBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare('SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1');
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);
    return $app;
}
