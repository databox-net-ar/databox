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
//   POST /v4/telegram/mensajes  (JSON body) -> envia, devuelve {canal, destinatario, mensaje, message_id, fecha, mensaje_id}
//
// GET/otros -> 405 Metodo no soportado.
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que
// /v4/evolution/mensajes).
//
// Historial: cada envio queda registrado en `telegram_mensajes`, que es lo que
// lista el ABM cloud (Plataformas > Telegram > Mensajes). Ver el bloque
// "Registro en `telegram_mensajes`" mas abajo para el ciclo de vida de la fila
// y para el guard que evita duplicarla cuando quien llama es el worker de la
// cola.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/telegram_mensajes.php';
require_once dirname(__DIR__) . '/_lib/log.php';
require_once dirname(__DIR__) . '/_lib/telegram.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro, y
// antes del require del phar de MadelineProto para atrapar un fatal de ahi.
v4InitLog('v4/telegram.mensajes');

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
    v4LogApp(requireApp());
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleSend(readJsonBody());
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    // Si ya habiamos abierto la fila del historial, cerrarla como error antes
    // de contestar -- si no, queda un 'enviando' colgado para siempre (el
    // lock del worker solo levanta 'pendiente'/'error').
    tgRegistroCerrarError($e->getMessage());
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
        $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado, proyecto
                               FROM telegram_canales
                              WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $slug]);
        $row = $st->fetch();
        if (!$row) jsonError("Canal '{$slug}' no encontrado", 400);
        if ((string)$row['habilitado'] !== '1') jsonError("Canal '{$slug}' esta deshabilitado", 400);
        return $row;
    }

    if ($id > 0) {
        $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado, proyecto
                               FROM telegram_canales
                              WHERE id = :i LIMIT 1");
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        if (!$row) jsonError("Canal #{$id} no encontrado", 400);
        if ((string)$row['habilitado'] !== '1') jsonError("Canal #{$id} esta deshabilitado", 400);
        return $row;
    }

    // Auto-pick: si hay exactamente un canal habilitado, usarlo.
    $rows = $pdo->query("SELECT id, slug, nombre, telefono, habilitado, proyecto
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
// Registro en `telegram_mensajes` (historial que lista el ABM cloud)
// ---------------------------------------------------------------------------
// Este endpoint envia de forma SINCRONA, pero la fila se abre ANTES de hablar
// con Telegram y se cierra despues, con el mismo ciclo de vida que usa el
// worker de la cola:
//
//     'enviando' -> 'enviado'  (Telegram acepto)
//                -> 'error'    (fallo la resolucion del destino o el envio)
//
// Abrirla antes es a proposito: si MadelineProto se cuelga o el proceso muere
// a mitad de camino, en el panel queda un 'enviando' visible en vez de no
// quedar rastro de que se intento. Se abre en estado 'enviando' (y no
// 'pendiente') para que el cron worker NUNCA la levante -- su SELECT toma
// solo 'pendiente' --, con lo cual no hay riesgo de que el mensaje se mande
// dos veces.
//
// GUARD DE DUPLICADOS: el worker de la cola
// (cloud/api/lib/telegram_mensajes_enviar.php) tambien despacha por aca, pero
// el ya tiene su propia fila y la persiste el mismo. Manda su id en
// `mensaje_id`; cuando ese campo viene, este endpoint no registra nada. Sin
// ese guard cada mensaje originado en el ABM aparecería duplicado.
//
// El registro es BEST EFFORT: el producto de este endpoint es el mensaje
// entregado, la fila es contabilidad. Si la insercion falla (proyecto sin
// resolver, DB caida, drift de schema) se deja el rastro en el Visor de
// sucesos y el envio sigue igual.

/** Getter/setter del id de `telegram_mensajes` abierto por este request. */
function tgRegistroId(?int $id = null): ?int {
    static $actual = null;
    if ($id !== null) $actual = $id;
    return $actual;
}

/**
 * Abre la fila del historial en estado 'enviando'. No-op si quien llama ya
 * tiene la suya (worker de la cola, via `mensaje_id`).
 *
 * `proyecto_id` se resuelve body -> `telegram_canales.proyecto`. El ABM lista
 * con LEFT JOIN a `proyectos`, asi que un mensaje sin proyecto igual se ve.
 */
function tgRegistroAbrir(array $in, array $canal, string $telDest, string $mensaje): void {
    if (isset($in['mensaje_id']) && (int)$in['mensaje_id'] > 0) return;

    try {
        // Se registra EXACTAMENTE lo que se manda por el cable: no se pasa
        // `plantilla_id` aunque venga en el body, porque encolarTelegramMensaje()
        // reescribiria `cuerpo` con el texto de la plantilla y la fila dejaria
        // de coincidir con lo que Telegram recibio.
        $id = encolarTelegramMensaje(db(), [
            'proyecto_id'  => $in['proyecto_id'] ?? $in['proyecto'] ?? $canal['proyecto'] ?? null,
            'canal_id'     => (int)$canal['id'],
            'prospecto_id' => $in['prospecto_id'] ?? null,
            'remitente'    => $canal['nombre']   ?? null,
            'remite'       => $canal['telefono'] ?? null,
            'destinatario' => $in['destinatario'] ?? null,
            'destino'      => $telDest,
            'cuerpo'       => $mensaje,
            'tags'         => $in['tags'] ?? null,
            'estado'       => 'enviando',
        ]);
        tgRegistroId($id);
    } catch (Throwable $e) {
        tgRegistroAvisarFallo('abrir la fila', $e->getMessage());
    }
}

/** Cierra la fila como 'enviado'. No-op si no habiamos abierto ninguna. */
function tgRegistroCerrarOk(): void {
    $id = tgRegistroId();
    if ($id === null) return;
    try {
        db()->prepare("
            UPDATE telegram_mensajes
               SET estado  = 'enviado',
                   error   = NULL,
                   enviado = NOW(),
                   demora  = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
             WHERE id = :id
        ")->execute([':id' => $id]);
    } catch (Throwable $e) {
        tgRegistroAvisarFallo('cerrar la fila como enviado', $e->getMessage());
    }
}

/** Cierra la fila como 'error'. No-op si no habiamos abierto ninguna. */
function tgRegistroCerrarError(string $err): void {
    $id = tgRegistroId();
    if ($id === null) return;
    try {
        db()->prepare("
            UPDATE telegram_mensajes
               SET estado = 'error',
                   error  = :err,
                   demora = TIMESTAMPDIFF(SECOND, COALESCE(encolado, fecha, NOW()), NOW())
             WHERE id = :id
        ")->execute([':err' => substr($err, 0, 1000), ':id' => $id]);
    } catch (Throwable $e) {
        tgRegistroAvisarFallo('cerrar la fila como error', $e->getMessage());
    }
}

/**
 * Deja constancia en `sucesos` de que el historial no se pudo escribir. Se
 * traga cualquier fallo propio: un problema del log no puede tumbar el envio.
 */
function tgRegistroAvisarFallo(string $paso, string $err): void {
    try {
        registrarSuceso(db(), 'v4/telegram.mensajes', 'alerta',
            "No se pudo {$paso} en telegram_mensajes (el envio sigue su curso): {$err}");
    } catch (Throwable) { /* ignore */ }
}

/**
 * Marca la fila como error y contesta. Reemplaza a jsonError() en todo camino
 * de salida POSTERIOR a tgRegistroAbrir() -- jsonError() hace exit, asi que si
 * no se cierra aca la fila se queda en 'enviando' para siempre.
 */
function tgFallar(string $msg, int $code): void {
    tgRegistroCerrarError($msg);
    jsonError($msg, $code);
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

    // Historial: se abre ANTES de tocar MadelineProto para que un cuelgue del
    // phar deje rastro. De aca en adelante toda salida de error va por
    // tgFallar() (o por el catch global), nunca por jsonError() pelado.
    tgRegistroAbrir($in, $canal, $telDest, $mensaje);

    $canalDir  = __DIR__ . '/canales/' . $telCanal;
    $sessDir   = $canalDir . '/session.madeline';
    $bootstrap = $canalDir . '/madeline.php';

    // Si la sesion no existe, no podemos loguear desde HTTP (el login pide
    // codigo por SMS/Telegram interactivo). Devolvemos error explicito con
    // la ruta esperada, para que el operador sepa que hay que correr el
    // login CLI en dev (php login.php --canal=<slug>) y luego deployar.
    if (!is_dir($sessDir)) {
        tgFallar(
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
            tgFallar('No se pudo descargar el bootstrap de MadelineProto', 500);
        }
        file_put_contents($bootstrap, $src);
    }
    // Preflight de permisos. MadelineProto escribe el lock del phar, el log y
    // la sesion dentro del canalDir, y si no puede muere con un TypeError de
    // flock() que no dice nada del problema real. Ver api/v4/_lib/telegram.php.
    telegramCanalPreflight($canalDir, (string)$canal['slug']);

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
        tgFallar('No se pudo resolver el destinatario +' . $telDest . ': ' . $e->getMessage(), 400);
    }
    if (empty($resolved['users'])) {
        tgFallar('El numero +' . $telDest . ' no tiene cuenta de Telegram (o esta oculto).', 400);
    }
    $user = $resolved['users'][0];

    try {
        $res = $MadelineProto->messages->sendMessage([
            'peer'    => $user,
            'message' => $mensaje,
        ]);
    } catch (Throwable $e) {
        tgFallar('Telegram rechazo el envio: ' . $e->getMessage(), 502);
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

    // Telegram acepto: cerrar la fila del historial antes de contestar.
    tgRegistroCerrarOk();

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
        // Id de la fila en `telegram_mensajes` (lo que lista el ABM cloud).
        // null cuando quien llama trajo su propia fila via `mensaje_id`, o si
        // el registro best-effort no pudo escribirse.
        'mensaje_id'   => tgRegistroId(),
    ], 200);
}
