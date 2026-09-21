<?php
// api/lib/apikey_auth.php
// PUERTA UNICA de autenticacion por API key contra la tabla `aplicaciones`.
// Es la unica implementacion del stack: no hay copias por endpoint. Todo
// endpoint que consuma un agente externo (openclaw, kernel, integraciones,
// etc.) via Bearer estatico debe empezar con:
//
//   // desde cloud/api/*.php
//   require_once __DIR__ . '/lib/apikey_auth.php';
//   // desde api/v4/<modulo>/*.php
//   require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';
//
//   $app = requireAppApikey();  // 401 si falta bearer / apikey desconocida / deshabilitada
//
// Hasta la consolidacion cada microservicio de `api/v4` traia su propia copia
// de este bloque (8 variantes con nombres prefijados: readBearer, ubiReadBearer,
// embReadBearer, ...). Eran byte a byte iguales salvo que la de `arca` se habia
// olvidado el contador de `usos`, que es exactamente como divergen las copias.
// Si hay que tocar la auth --scope por aplicacion, rate limit, rotacion de
// apikey-- se toca ACA y vale para los 20 endpoints.
//
// Las apikeys se administran desde el ABM de aplicaciones (api/aplicaciones.php).
// Cada llamada exitosa incrementa `aplicaciones.usos` para dar visibilidad de
// actividad en el listado.
//
// Ojo: este helper NO valida permisos ni scope — cualquier apikey habilitada
// pasa. Si un endpoint necesita restringir a una app puntual, chequear
// `$app['id']` o `$app['nombre']` despues de llamar a requireAppApikey().

require_once __DIR__ . '/../db.php';

// Extrae el bearer del header Authorization. Apache/PHP-FPM en este stack NO
// propaga Authorization a $_SERVER, pero getallheaders() si la ve. Chequeamos
// ambas y REDIRECT_HTTP_AUTHORIZATION como fallback para mod_rewrite.
function readBearerToken(): string {
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

function requireAppApikey(): array {
    $token = readBearerToken();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1");
    $stmt->execute([':k' => $token]);
    $app = $stmt->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    // Contador de uso, best-effort. No abortamos si falla el UPDATE.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}
