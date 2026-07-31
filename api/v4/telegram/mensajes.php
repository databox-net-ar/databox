<?php
// api/v4/telegram/mensajes.php
// Microservicio de envio de mensajes de Telegram en modo USUARIO
// (MTProto via MadelineProto). Envio SINCRONO: el POST no vuelve hasta que
// Telegram acepto (o rechazo) el mensaje.
//
// Multi-canal: el catalogo de cuentas remitentes vive en `telegram_canales`
// (una fila por cuenta, con `slug` como identificador). Cada canal tiene su
// propio directorio de sesion en api/v4/telegram/session_<slug>/, generado
// UNA UNICA VEZ en desarrollo via CLI interactivo (`login.php --canal=X`) y
// transportado a prod por deploy.sh. Prod jamas inicia sesion.
//
//   POST /v4/telegram/mensajes  (JSON body) -> envia, devuelve {canal, destinatario, mensaje, message_id, fecha}
//
// GET/otros -> 405 Metodo no soportado.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que
// /v4/evolution/mensajes).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

// ---------------------------------------------------------------------------
// Auth (idem /v4/evolution/mensajes)
// ---------------------------------------------------------------------------
// Apache no siempre propaga Authorization a $_SERVER (depende de mod_rewrite
// y CGIPassAuth). Chequeamos $_SERVER, REDIRECT_HTTP_AUTHORIZATION y como
// ultimo recurso getallheaders().
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

    // Contador de uso — best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    requireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleSend(readJsonBody());
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Resolucion de canal remitente
// ---------------------------------------------------------------------------

/**
 * Devuelve la fila del canal a usar segun el input. Prioridad:
 *   1. `canal_slug` explicito en el body.
 *   2. `canal_id`   explicito en el body.
 *   3. Auto-pick: si hay UN SOLO canal habilitado, usarlo.
 *   4. Si hay varios habilitados y el body no eligio, 400 con la lista.
 * Cualquier otro caso (slug inexistente, canal deshabilitado, tabla vacia)
 * termina en 400 con mensaje explicito.
 */
function resolverCanal(array $in): array {
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
        if ((string)$row['habilitado'] !== '1') jsonError("Canal '{$slug}' esta deshabilitado", 400);
        return $row;
    }

    if ($id > 0) {
        $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado
                               FROM telegram_canales
                              WHERE id = :i LIMIT 1");
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        if (!$row) jsonError("Canal #{$id} no encontrado", 400);
        if ((string)$row['habilitado'] !== '1') jsonError("Canal #{$id} esta deshabilitado", 400);
        return $row;
    }

    // Auto-pick: si hay exactamente un canal habilitado, usarlo.
    $rows = $pdo->query("SELECT id, slug, nombre, telefono, habilitado
                           FROM telegram_canales
                          WHERE habilitado = '1'
                            AND slug IS NOT NULL AND slug <> ''
                          ORDER BY id ASC")->fetchAll();
    if (count($rows) === 1) return $rows[0];
    if (count($rows) === 0) {
        jsonError('No hay canales de Telegram habilitados. Cargar uno en `telegram_canales` y loguearlo en dev.', 400);
    }
    $slugs = implode(', ', array_map(fn($r) => "'{$r['slug']}'", $rows));
    jsonError("Hay varios canales habilitados ({$slugs}); pasar `canal_slug` o `canal_id` en el body.", 400);
    throw new RuntimeException('unreachable'); // jsonError() hace exit; esto es para el analizador estatico.
}

// ---------------------------------------------------------------------------
// POST /v4/telegram/mensajes  -> envio sincrono
// ---------------------------------------------------------------------------

function handleSend(array $in): void {
    // Contrato minimo: destinatario (telefono E.164 sin '+') + mensaje.
    // Opcional: canal_slug o canal_id (obligatorio si hay >1 habilitado).
    $destinatario = trim((string)($in['destinatario'] ?? ''));
    $mensaje      = (string)($in['mensaje'] ?? '');

    if ($destinatario === '') jsonError('Falta destinatario', 400);
    if ($mensaje      === '') jsonError('Falta mensaje',      400);

    // Normalizacion del telefono: sacamos '+' y todo lo que no sea digito.
    // MadelineProto quiere el numero en E.164 SIN '+' (formato importContacts).
    $telDest = preg_replace('/\D+/', '', $destinatario);
    if ($telDest === '' || strlen($telDest) < 8) {
        jsonError('destinatario invalido: se espera telefono en formato E.164 (con o sin +)', 400);
    }

    // Credenciales de la App de Telegram (my.telegram.org) — necesarias para
    // que MadelineProto pueda hablar MTProto. Vienen del .env cargado por
    // env.php (constantes globales TELEGRAM_API_ID / TELEGRAM_API_HASH).
    if (!defined('TELEGRAM_API_ID') || !defined('TELEGRAM_API_HASH')) {
        jsonError('Faltan TELEGRAM_API_ID / TELEGRAM_API_HASH en el .env', 500);
    }

    // Resolver canal remitente. Cada canal vive en un directorio TOTALMENTE
    // AISLADO en canales/<telefono>/, con su propio phar, su propia sesion,
    // su propio log y su propio .phar.lock. NADA se comparte entre canales
    // -- probamos compartir bootstrap y phar.lock a nivel de toda la carpeta
    // y termino generando cross-contamination con AUTH_KEY_DUPLICATED al
    // hacer un envio poco despues de haber tocado la otra cuenta.
    $canal    = resolverCanal($in);
    $telCanal = preg_replace('/\D+/', '', (string)($canal['telefono'] ?? ''));
    if ($telCanal === '') {
        jsonError("Canal '{$canal['slug']}' no tiene `telefono` cargado; no puedo ubicar la sesion.", 500);
    }

    $canalDir  = __DIR__ . '/canales/' . $telCanal;
    $sessDir   = $canalDir . '/session.madeline';
    $bootstrap = $canalDir . '/madeline.php';

    // Si la sesion no existe, no podemos loguear desde HTTP (el login pide
    // codigo por SMS/Telegram interactivo). Devolvemos error explicito con
    // la ruta esperada, para que el operador sepa que hay que correr el
    // login CLI en dev (php login.php --canal=<slug>) y luego deployar.
    if (!is_dir($sessDir)) {
        jsonError(
            "Sesion del canal '{$canal['slug']}' (+{$telCanal}) no inicializada (falta canales/{$telCanal}/session.madeline/). "
            . "Loguear en desarrollo: docker exec -it databox-apache php /var/www/api/v4/telegram/login.php --canal={$canal['slug']}",
            500
        );
    }

    // Bootstrap por-canal. Si no existe (deploy corrupto o auto-download
    // fallo), lo bajamos on-the-fly para no romper el request.
    if (!file_exists($bootstrap)) {
        if (!is_dir($canalDir)) @mkdir($canalDir, 0775, true);
        $src = @file_get_contents('https://phar.madelineproto.xyz/madeline.php');
        if ($src === false) {
            jsonError('No se pudo descargar el bootstrap de MadelineProto', 500);
        }
        file_put_contents($bootstrap, $src);
    }
    // chdir al canalDir ANTES del require: MadelineProto usa cwd para
    // ubicar recursos temporales (auto-restart bootstrap, lock files
    // adicionales). Sin chdir, MadelineProto contamina el root de
    // api/v4/telegram/ con archivos por-canal que otros canales tambien
    // ven -- rompiendo la isolacion que queremos.
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

    // start() bajo HTTP es no-op si la sesion ya esta logueada. Si no lo
    // estuviera, tirarìa porque no hay stdin -- pero eso ya lo cubrimos
    // con el is_dir() de arriba.
    $MadelineProto->start();

    // Un cliente usuario NO puede mandar a un telefono si nunca hablo con
    // esa persona ("This peer is not present in the internal peer database").
    // resolvePhone registra el user en la peer database interna sin tocar
    // la agenda del usuario logueado.
    try {
        $resolved = $MadelineProto->contacts->resolvePhone(['phone' => $telDest]);
    } catch (Throwable $e) {
        jsonError('No se pudo resolver el destinatario +' . $telDest . ': ' . $e->getMessage(), 400);
    }
    if (empty($resolved['users'])) {
        jsonError('El numero +' . $telDest . ' no tiene cuenta de Telegram (o esta oculto).', 400);
    }
    $user = $resolved['users'][0];

    try {
        $res = $MadelineProto->messages->sendMessage([
            'peer'    => $user,
            'message' => $mensaje,
        ]);
    } catch (Throwable $e) {
        jsonError('Telegram rechazo el envio: ' . $e->getMessage(), 502);
    }

    // El id del mensaje puede venir en $res['id'] (updateShortSentMessage)
    // o dentro de $res['updates'][*]['message']['id'] (updates comunes).
    // Mostramos lo que consigamos sin sobreanalizar.
    $messageId = $res['id'] ?? null;
    if ($messageId === null && !empty($res['updates'])) {
        foreach ($res['updates'] as $u) {
            if (isset($u['message']['id'])) { $messageId = (int) $u['message']['id']; break; }
            if (isset($u['id'])            ) { $messageId = (int) $u['id'];            break; }
        }
    }

    $fecha = isset($res['date']) && is_numeric($res['date'])
        ? (new DateTime('@' . (int)$res['date']))
              ->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'))
              ->format('Y-m-d H:i:s')
        : (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
              ->format('Y-m-d H:i:s');

    jsonOk([
        'canal' => [
            'id'       => (int)$canal['id'],
            'slug'     => (string)$canal['slug'],
            'nombre'   => (string)($canal['nombre'] ?? ''),
            'telefono' => $canal['telefono'] !== null ? '+' . $canal['telefono'] : null,
        ],
        'destinatario' => '+' . $telDest,
        'mensaje'      => $mensaje,
        'message_id'   => $messageId,
        'fecha'        => $fecha,
    ], 200);
}
