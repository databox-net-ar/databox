<?php
// api/v4/telegram/health.php
// Endpoint liviano que verifica la SALUD de una sesion MTProto de Telegram:
// carga la sesion del canal y llama `getSelf()` contra Telegram. Devuelve
// {ok:true, user_id, username, first_name} si respondio, o {ok:false, error}
// en caso contrario.
//
// Consumido por el cron activo cloud/jobs/telegramcanales_actualizar_estados.php,
// que lo llama SOLO para los canales con `latido` viejo (>15 min) o nulo --
// asi complementamos la deteccion pasiva del sender (que actualiza
// telegram_canales.online/latido cada vez que un envio real sale OK).
//
//   POST /v4/telegram/health  (JSON body: {canal_slug} o {canal_id})
//     -> {ok:true, user_id, username, first_name}
//     -> jsonError si no se pudo ejecutar getSelf()
//
// GET/otros -> 405 Metodo no soportado.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que
// /v4/telegram/mensajes y /v4/evolution/mensajes).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__) . '/_lib/telegram.php';

// ---------------------------------------------------------------------------
// Auth (mirror de mensajes.php)
// ---------------------------------------------------------------------------

function readBearer(): string {
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

function requireApp(): array {
    $token = readBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1");
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    return $app;
}

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    requireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleHealth(readJsonBody());
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Resolucion de canal (por slug o id, obligatorio -- este endpoint no tiene
// auto-pick: siempre lo llama el cron iterando canal por canal)
// ---------------------------------------------------------------------------

function resolverCanalHealth(array $in): array {
    $pdo = db();

    $slug = trim((string)($in['canal_slug'] ?? ''));
    $id   = isset($in['canal_id']) && $in['canal_id'] !== '' ? (int)$in['canal_id'] : 0;

    if ($slug !== '') {
        $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado
                               FROM telegram_canales
                              WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $slug]);
        $row = $st->fetch();
        if (!$row) jsonError("Canal '{$slug}' no encontrado", 400);
        return $row;
    }

    if ($id > 0) {
        $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado
                               FROM telegram_canales
                              WHERE id = :i LIMIT 1");
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        if (!$row) jsonError("Canal #{$id} no encontrado", 400);
        return $row;
    }

    jsonError('Falta canal_slug o canal_id', 400);
    throw new RuntimeException('unreachable'); // jsonError() hace exit.
}

// ---------------------------------------------------------------------------
// POST /v4/telegram/health -> getSelf() sobre la sesion del canal
// ---------------------------------------------------------------------------

function handleHealth(array $in): void {
    if (!defined('TELEGRAM_API_ID') || !defined('TELEGRAM_API_HASH')) {
        jsonError('Faltan TELEGRAM_API_ID / TELEGRAM_API_HASH en el .env', 500);
    }

    $canal    = resolverCanalHealth($in);
    $telCanal = preg_replace('/\D+/', '', (string)($canal['telefono'] ?? ''));
    if ($telCanal === '') {
        jsonError("Canal '{$canal['slug']}' no tiene `telefono` cargado; no puedo ubicar la sesion.", 500);
    }

    // Mismo layout de sesion que mensajes.php: cada canal aislado en
    // canales/<telefono>/ con su bootstrap propio. NO compartir bootstrap
    // entre canales -- genera AUTH_KEY_DUPLICATED intermitente.
    $canalDir  = __DIR__ . '/canales/' . $telCanal;
    $sessDir   = $canalDir . '/session.madeline';
    $bootstrap = $canalDir . '/madeline.php';

    // Sesion inexistente = "nunca logueada". El cron lo interpreta como
    // offline y no falla el job, sigue con el proximo canal.
    if (!is_dir($sessDir)) {
        jsonError(
            "Sesion del canal '{$canal['slug']}' (+{$telCanal}) no inicializada "
            . "(falta canales/{$telCanal}/session.madeline/). "
            . "Loguear en desarrollo con login.php --canal={$canal['slug']}.",
            500
        );
    }

    if (!file_exists($bootstrap)) {
        if (!is_dir($canalDir)) @mkdir($canalDir, 0775, true);
        $src = @file_get_contents('https://phar.madelineproto.xyz/madeline.php');
        if ($src === false) {
            jsonError('No se pudo descargar el bootstrap de MadelineProto', 500);
        }
        file_put_contents($bootstrap, $src);
    }

    // Mismo preflight de permisos que mensajes.php: sin permiso de escritura
    // en el canalDir, MadelineProto muere con un TypeError de flock() que no
    // explica nada. Ver api/v4/_lib/telegram.php.
    telegramCanalPreflight($canalDir, (string)$canal['slug']);

    chdir($canalDir);
    require_once $bootstrap;

    $settings = (new \danog\MadelineProto\Settings())
        ->setAppInfo(
            (new \danog\MadelineProto\Settings\AppInfo())
                ->setApiId((int) TELEGRAM_API_ID)
                ->setApiHash((string) TELEGRAM_API_HASH)
        )
        ->setLogger(
            (new \danog\MadelineProto\Settings\Logger())
                ->setType(\danog\MadelineProto\Logger::FILE_LOGGER)
                ->setExtra($canalDir . '/MadelineProto.log')
                ->setLevel(\danog\MadelineProto\Logger::NOTICE)
        );

    $MadelineProto = new \danog\MadelineProto\API($sessDir, $settings);
    // start() reutiliza el daemon IPC si esta caliente (~sub-segundo). Si
    // esta frio paga 5-15s de cold start, pero solo la primera corrida.
    $MadelineProto->start();

    try {
        $me = $MadelineProto->getSelf();
    } catch (Throwable $e) {
        // Propagamos el mensaje tal cual: el cliente (cron) usa
        // esErrorSesionTelegramCaida() para clasificarlo y decidir si
        // marca al canal como offline.
        jsonError('getSelf fallo: ' . $e->getMessage(), 502);
    }

    if (!is_array($me) || empty($me['id'])) {
        jsonError('getSelf devolvio respuesta vacia o invalida', 502);
    }

    jsonOk([
        'canal' => [
            'id'       => (int)$canal['id'],
            'slug'     => (string)$canal['slug'],
            'nombre'   => (string)($canal['nombre'] ?? ''),
            'telefono' => $canal['telefono'] !== null ? '+' . $canal['telefono'] : null,
        ],
        'user_id'    => (int)$me['id'],
        'username'   => isset($me['username'])   ? (string)$me['username']   : null,
        'first_name' => isset($me['first_name']) ? (string)$me['first_name'] : null,
    ], 200);
}
