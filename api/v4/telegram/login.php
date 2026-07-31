<?php
// api/v4/telegram/login.php
// Helper CLI para dar de alta la SESION de un canal de Telegram (MTProto,
// modo usuario) en desarrollo. Es la UNICA via para autenticar una cuenta:
// el endpoint HTTP (mensajes.php) explicitamente rechaza envios contra
// canales sin sesion en disco, porque el flujo de login pide un codigo por
// Telegram que hay que ingresar interactivamente.
//
// Flujo esperado:
//   1. Alta de la fila en `telegram_canales` (slug, nombre, telefono).
//   2. Login en DEV via este script:
//        docker exec -it databox-apache php /var/www/api/v4/telegram/login.php --canal=<slug>
//      (pide el numero, despues el codigo que llega por Telegram, y la
//      password 2FA si aplica). El script mira `telegram_canales.telefono`
//      del slug pasado y crea/actualiza session_<telefono>/.
//   3. `bash scripts/deploy.sh` -- transporta el directorio a produccion.
//   4. El endpoint HTTP en prod ya puede enviar por ese canal.
//
// Uso:
//   php login.php --canal=<slug>            (obligatorio: identifica el canal)
//   php login.php --canal=<slug> --force    (fuerza re-login aunque ya haya sesion)
//
// El nombre del subdirectorio se deriva de `telegram_canales.telefono` del
// canal (ej. session_541163219578/), no del slug. Asi el filesystem refleja
// que numero esta logueado, y renombrar el slug en la DB no muda la sesion.
//
// Restricciones:
//   * Solo corre desde CLI (no HTTP).
//   * Solo corre en desarrollo (APP_ENV != 'production'), por decision de
//     producto: prod jamas inicia sesion, siempre recibe la sesion via deploy.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo corre desde CLI.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

if (APP_ENV === 'production') {
    fwrite(STDERR, "APP_ENV=production. Este script solo se corre en desarrollo -- "
                 . "en prod las sesiones se traen desde dev via deploy.sh.\n");
    exit(1);
}

if (!defined('TELEGRAM_API_ID') || !defined('TELEGRAM_API_HASH')) {
    fwrite(STDERR, "Faltan TELEGRAM_API_ID / TELEGRAM_API_HASH en el .env cargado.\n");
    exit(1);
}

// Parseo minimo de flags. El login es de DOS FASES:
//   1) --send-code       -> phoneLogin(): Telegram manda SMS/mensaje con OTP.
//                            No hay readLine interactivo; el script termina
//                            inmediatamente despues de mandar el pedido.
//   2) --code=NNNNN       -> completePhoneLogin(): consume el OTP y persiste
//                            la sesion (getSelf + stop cierra limpio).
// Modo interactivo (sin flags) tambien existe pero MadelineProto tiene un
// timeout duro de ~60s en el readLine del codigo, imposible de cumplir con
// el ida-vuelta manual de un OTP -- por eso se hace en dos comandos.
$opts = getopt('', ['canal:', 'force', 'send-code', 'code:']);
$slug  = isset($opts['canal']) ? trim((string)$opts['canal']) : '';
$force = array_key_exists('force', $opts);
$sendCode = array_key_exists('send-code', $opts);
$code     = isset($opts['code']) ? trim((string)$opts['code']) : '';

if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
    fwrite(STDERR, "Uso:\n"
                 . "  Fase 1 (mandar OTP):  php login.php --canal=<slug> --send-code\n"
                 . "  Fase 2 (completar):   php login.php --canal=<slug> --code=<OTP>\n"
                 . "  Ambas juntas (interactivo, timeout ~60s):  php login.php --canal=<slug>\n"
                 . "El slug tiene que ser [a-z0-9-] (ej: --canal=databox).\n");
    exit(1);
}
if ($sendCode && $code !== '') {
    fwrite(STDERR, "--send-code y --code son mutuamente excluyentes.\n");
    exit(1);
}

// Lookup del canal en la DB. Necesitamos el telefono para saber que
// subdirectorio de sesion crear.
$st = db()->prepare("SELECT id, slug, nombre, telefono, habilitado
                       FROM telegram_canales WHERE slug = :s LIMIT 1");
$st->execute([':s' => $slug]);
$canal = $st->fetch();

if (!$canal) {
    fwrite(STDERR, "Canal '{$slug}' no existe en `telegram_canales`. "
                 . "Alta primero:\n"
                 . "  INSERT INTO telegram_canales (slug, nombre, telefono, habilitado, actualizado) "
                 . "VALUES ('{$slug}', '<nombre>', '<telefono E.164 sin +>', '1', NOW());\n");
    exit(1);
}

$telefono = preg_replace('/\D+/', '', (string)($canal['telefono'] ?? ''));
if ($telefono === '') {
    fwrite(STDERR, "Canal '{$slug}' no tiene `telefono` cargado. Actualizar la fila antes de loguear.\n");
    exit(1);
}

// Cada canal vive en canales/<telefono>/, TOTALMENTE aislado: bootstrap
// propio, phar propio, .phar.lock propio, log propio, session propio.
// Compartir el bootstrap a nivel de toda la carpeta causaba conflictos
// (AUTH_KEY_DUPLICATED intermitente al usar dos canales en el mismo panel).
$canalDir  = __DIR__ . '/canales/' . $telefono;
$sessDir   = $canalDir . '/session.madeline';
$bootstrap = $canalDir . '/madeline.php';

// La validacion "ya existe la sesion" solo aplica cuando arrancamos algo
// nuevo (fase 1: --send-code, o modo interactivo sin flags). En la fase 2
// (--code), *queremos* que exista la sesion parcial que dejo la fase 1.
$startingNew = $sendCode || ($code === '');

if ($startingNew && is_dir($sessDir) && !$force) {
    fwrite(STDERR, "Ya existe {$sessDir}. Si querés re-loguear, pasá --force.\n"
                 . "(si estas en Fase 2 con --code, no borres la sesion parcial de Fase 1)\n");
    exit(1);
}
if ($startingNew && $force && is_dir($sessDir)) {
    // Limpieza: eliminamos la sesion vieja completa antes de arrancar el
    // nuevo login. Los sockets Unix (ipc/callback.ipc) los sacamos primero
    // porque unlink() sobre socket en bind mount de Windows puede fallar
    // silenciosamente si el daemon esta vivo; los IPC daemons persisten
    // entre requests y hay que matarlos antes.
    fwrite(STDOUT, "Forzando re-login: borrando {$sessDir}...\n");
    // Best-effort: si algun archivo no se puede borrar, MadelineProto
    // igual lo va a re-escribir al arrancar.
    exec('rm -rf ' . escapeshellarg($sessDir));
}
if ($code !== '' && !is_dir($sessDir)) {
    fwrite(STDERR, "No existe {$sessDir}. Corré primero: php login.php --canal={$slug} --send-code\n");
    exit(1);
}

if (!is_dir($canalDir)) {
    if (!@mkdir($canalDir, 0775, true)) {
        fwrite(STDERR, "No pude crear {$canalDir}.\n");
        exit(1);
    }
}

// Bootstrap POR-CANAL (autodescarga la primera vez).
if (!file_exists($bootstrap)) {
    fwrite(STDOUT, "Descargando bootstrap de MadelineProto en {$canalDir}...\n");
    $src = @file_get_contents('https://phar.madelineproto.xyz/madeline.php');
    if ($src === false) {
        fwrite(STDERR, "No pude descargar el bootstrap.\n");
        exit(1);
    }
    file_put_contents($bootstrap, $src);
}
// chdir al canalDir ANTES del require -- MadelineProto usa cwd para
// ubicar recursos temporales (auto-restart bootstrap, lock files
// adicionales). Sin chdir, MadelineProto contamina el parent dir con
// archivos que otros canales tambien verian, rompiendo la isolacion.
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
            ->setType(\danog\MadelineProto\Logger::ECHO_LOGGER)
            ->setLevel(\danog\MadelineProto\Logger::NOTICE)
            ->setExtra($canalDir . '/MadelineProto.log')
    );

$MadelineProto = new \danog\MadelineProto\API($sessDir, $settings);

// -- Fase 1: mandar el codigo (--send-code) --------------------------------
if ($sendCode) {
    // phoneLogin devuelve la respuesta SentCode y persiste el phone_hash
    // en la sesion. La sesion queda "a la espera de codigo".
    try {
        $MadelineProto->phoneLogin('+' . $telefono);
    } catch (Throwable $e) {
        fwrite(STDERR, "phoneLogin fallo: " . $e->getMessage() . "\n");
        exit(1);
    }
    // Cerramos el daemon IPC limpio para no dejar conexion abierta a Telegram
    // entre las dos fases del login.
    try { $MadelineProto->stop(); } catch (Throwable) {}

    fwrite(STDOUT, "\n"
                 . "======================================================================\n"
                 . "  Canal:            {$canal['slug']}  (+{$telefono})\n"
                 . "  Fase 1 OK: Telegram te mando un codigo al +{$telefono}.\n"
                 . "  Fase 2: cuando llegue, correr:\n"
                 . "      docker exec databox-apache php /var/www/api/v4/telegram/login.php --canal={$slug} --code=<OTP>\n"
                 . "======================================================================\n");
    exit(0);
}

// -- Fase 2: completar con el codigo (--code=NNNNN) ------------------------
if ($code !== '') {
    try {
        $MadelineProto->completePhoneLogin($code);
    } catch (Throwable $e) {
        fwrite(STDERR, "completePhoneLogin fallo: " . $e->getMessage() . "\n");
        try { $MadelineProto->stop(); } catch (Throwable) {}
        exit(1);
    }
    // Warmup + shutdown limpio (misma logica que el modo interactivo).
    warmupYCerrar($MadelineProto);
    exitOk($canal, $telefono, $sessDir);
}

// -- Modo interactivo (fallback: pide el codigo por stdin, timeout ~60s) ----
$MadelineProto->start();
warmupYCerrar($MadelineProto);
exitOk($canal, $telefono, $sessDir);

// --------------------------------------------------------------------------

function warmupYCerrar(\danog\MadelineProto\API $api): void {
    global $canalDir;

    // "Calentar" la sesion contra el home DC del usuario. El login inicial
    // autentica contra DC 2 (bootstrap), pero cada cuenta tiene un HOME DC
    // (2 o 4 tipicamente en LATAM; 1 en otros paises) donde vive el usuario.
    // La primera llamada real (getSelf, sendMessage, etc.) descubre el home
    // DC y hace el key transfer para autorizar la sesion tambien alli. Si
    // esa primera llamada ocurre en PROD post-deploy, DC del home devuelve
    // AUTH_KEY_UNREGISTERED y Telegram invalida. Forzando getSelf aca en
    // dev, safe.php queda listo para todos los DCs.
    try {
        $me = $api->getSelf();
        fwrite(STDOUT, "\n  Sesion calentada -- Telegram devolvio getSelf: id={$me['id']}"
                     . (isset($me['username']) ? " username=@{$me['username']}" : "")
                     . (isset($me['first_name']) ? " nombre='" . $me['first_name'] . "'" : "")
                     . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "\n  ADVERTENCIA: getSelf fallo: " . $e->getMessage() . "\n"
                     . "  La sesion puede tener DCs sin autorizar. El primer envio en\n"
                     . "  prod probablemente falle con AUTH_KEY_UNREGISTERED.\n");
    }

    // Cierre LIMPIO del IPC daemon antes de salir. Sin esto, la conexion
    // a Telegram queda registrada y cuando prod se conecte con la misma
    // sesion despues del deploy, Telegram devuelve AUTH_KEY_DUPLICATED.
    try {
        $api->stop();
        fwrite(STDOUT, "  Daemon IPC cerrado limpiamente (stop()).\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, "  Nota: stop() reporto: " . $e->getMessage() . " -- continuando.\n");
    }
}

function exitOk(array $canal, string $telefono, string $sessDir): void {
    fwrite(STDOUT, "\n"
                 . "======================================================================\n"
                 . "  Canal:            {$canal['slug']}  (+{$telefono})\n"
                 . "  Sesion creada en: {$sessDir}\n"
                 . "  Proximos pasos:\n"
                 . "    1) Verificar que NO haya IPC daemons vivos en dev antes de deployar:\n"
                 . "         docker exec databox-apache ps -ef | grep madeline | grep -v grep\n"
                 . "       Si quedan, matalos con SIGTERM (no SIGKILL):\n"
                 . "         docker exec -u root databox-apache pkill -f madeline && sleep 8\n"
                 . "    2) bash scripts/deploy.sh   -- sube la sesion a prod.\n"
                 . "======================================================================\n");
    exit(0);
}
