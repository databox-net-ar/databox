<?php
// api/v4/_lib/log.php
// Registro automatico de los errores de un microservicio v4 en la tabla
// `sucesos` — la que lee el Visor de sucesos del panel cloud
// (Herramientas > Visor de sucesos).
//
// ---------------------------------------------------------------------------
// USO
// ---------------------------------------------------------------------------
// Dos lineas en el endpoint, apenas cargadas sus dependencias y ANTES de la
// validacion del apikey (asi los 401 tambien quedan registrados):
//
//     require_once dirname(__DIR__) . '/_lib/log.php';
//     v4InitLog('v4/datarocket.embudos');
//
// Y, si el endpoint valida apikey, envolver esa llamada para que el suceso
// diga QUE aplicacion lo provoco — es lo primero que se quiere saber ante un
// 4xx repetido. v4LogApp() devuelve la misma fila que recibe, asi que se
// encadena sin tocar el resto:
//
//     $app = v4LogApp(embRequireApp());
//
// ---------------------------------------------------------------------------
// COMO FUNCIONA
// ---------------------------------------------------------------------------
// jsonOk() / jsonError() (cloud/api/db.php) hacen `exit`, asi que no hay un
// punto del endpoint por el que pasen todas las salidas: instrumentar los
// errores a mano obligaria a tocar las ~100 llamadas a jsonError() del arbol
// v4 y a acordarse de cada una nueva.
//
// En vez de eso v4InitLog() abre un buffer de salida y registra un shutdown
// handler. PHP corre los shutdown handlers DESPUES del `exit`, con la
// respuesta todavia retenida en el buffer: ahi se lee que contesto el
// endpoint, se decide si fue un error y se escribe la fila. Ninguna llamada a
// jsonError() hay que tocar, y un rechazo nuevo queda cubierto solo.
//
// Es la misma tecnica de api/v4/arca/_lib/log.php, generalizada. Aquella se
// deja como esta: tiene helpers propios del microservicio (arcaLogInfo() y el
// contexto explicito por endpoint) que no aplican al resto.
//
// ---------------------------------------------------------------------------
// QUE SE REGISTRA
// ---------------------------------------------------------------------------
//   * Fatales PHP no capturados (E_ERROR y compania)         -> tipo `error`
//   * Respuestas 5xx (excepciones, fallos de upstream)        -> tipo `error`
//   * Respuestas 4xx (validacion, apikey, 404, 409)           -> tipo `alerta`
//   * Respuestas ok:true                                      -> no se registra
//
// El 4xx va como `alerta` y no como `error` a proposito: son errores DEL
// CALLER, no del servicio, y varios son respuestas de diseño — el 409 con
// `coincidencias` de `/v4/datarocket/prospectos?verificar=1` es el resultado
// normal de una verificacion de duplicados, no una falla. Mezclarlos con los
// 5xx dejaria el chip `Error` del visor inservible para lo unico que importa
// ahi: enterarse de que algo se rompio. El codigo HTTP viaja igual en el
// detalle de las dos severidades, asi que no se pierde nada.
//
// NO se registra el body del request. Puede traer el texto de un mensaje de
// WhatsApp o los renglones de un comprobante, y el detalle esta para
// diagnosticar, no para archivar payloads. Si va la query string, que es lo
// que suele explicar un 4xx.

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/sucesos.php';

// `sucesos.detalle` es TEXT, asi que el corte no es por la columna: es para
// que una excepcion con un dump adentro no convierta una fila del visor en un
// muro. Mismo techo que arca/_lib/log.php.
const V4_LOG_DETALLE_MAX = 4000;

/**
 * Instala el registro automatico de errores para el request en curso.
 * `$origen` es lo que se ve en la columna `Origen` del visor; la convencion
 * del arbol es `v4/<carpeta>.<archivo>` (registrarSuceso lo corta a 50).
 */
function v4InitLog(string $origen): void {
    static $instalado = false;
    if ($instalado) return;   // dos llamadas no pueden duplicar la fila
    $instalado = true;

    // Este buffer es lo que despues deja leer la respuesta desde el shutdown
    // handler: para cuando ese corre, jsonError() ya hizo `exit`.
    ob_start();

    register_shutdown_function(static function () use ($origen): void {
        v4LogShutdown($origen);
    });
}

/**
 * Informa que aplicacion (fila de `aplicaciones`) esta haciendo el request,
 * para que el suceso diga quien provoco el error. Devuelve la misma fila que
 * recibe para poder envolver la llamada existente:
 *
 *     $app = v4LogApp(etqRequireApp());
 */
function v4LogApp(array $app): array {
    $nombre = trim((string)($app['nombre'] ?? ''));
    if ($nombre !== '') v4LogAppNombre($nombre);
    return $app;
}

/** Getter/setter del nombre de la app. Estado del request, no del proceso. */
function v4LogAppNombre(?string $nombre = null): ?string {
    static $actual = null;
    if ($nombre !== null) $actual = $nombre;
    return $actual;
}

// ---------------------------------------------------------------------------
// Shutdown handler
// ---------------------------------------------------------------------------

function v4LogShutdown(string $origen): void {
    // Vaciar el buffer PRIMERO. El cliente se lleva su respuesta pase lo que
    // pase con el log: si la DB esta caida, el request igual contesta.
    $salida = ob_get_level() > 0 ? ob_get_clean() : false;
    if (is_string($salida) && $salida !== '') echo $salida;

    $codigo = http_response_code();
    if (!is_int($codigo)) $codigo = 200;   // CLI devuelve false

    [$tipo, $motivo] = v4LogClasificar(is_string($salida) ? $salida : '', $codigo);
    if ($tipo === null) return;            // la respuesta fue exitosa

    try {
        $pdo = db();
    } catch (Throwable $_) {
        return;                            // sin DB no hay donde escribir
    }

    // registrarSuceso() ya swallowea sus propios fallos: un error del log no
    // puede convertirse en un error del endpoint.
    registrarSuceso($pdo, $origen, $tipo,
        substr(v4LogDetalle($codigo, $motivo), 0, V4_LOG_DETALLE_MAX));
}

/**
 * Decide si lo que se esta por devolver es un error y con que severidad.
 * Devuelve `[tipo, motivo]`, o `[null, '']` cuando no hay nada que registrar.
 */
function v4LogClasificar(string $salida, int $codigo): array {
    // 1. Fatal PHP. Va primero porque en ese caso la respuesta suele salir
    //    truncada o vacia y el JSON no cuenta nada util.
    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'] ?? 0,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return ['error', sprintf(
            'Fatal PHP: %s en %s:%d',
            $fatal['message'] ?? '(sin mensaje)',
            $fatal['file']    ?? '?',
            (int)($fatal['line'] ?? 0)
        )];
    }

    // 2. Sobre JSON de la casa: {"ok":false,"error":"..."}. Es el camino
    //    normal — todo rechazo del arbol v4 sale por jsonError().
    [$json, $ruido] = v4LogSobre($salida);
    if ($json !== null && array_key_exists('ok', $json)) {
        if ($json['ok'] !== false) return [null, ''];
        $motivo = (string)($json['error'] ?? '(sin mensaje)');
        // El ruido que PHP haya escupido antes del sobre es parte del
        // diagnostico (suele ser el deprecation o el warning que explica el
        // fallo), pero va DESPUES del mensaje real para no taparlo.
        if ($ruido !== '') $motivo .= "\nSalida previa de PHP: " . substr($ruido, 0, 400);
        return [v4LogTipoPorCodigo($codigo), $motivo];
    }

    // 3. Respuesta que no se pudo interpretar (buffer cerrado por una
    //    dependencia, salida no-JSON, o vacia) pero con status de error.
    //    Vale dejar la traza aunque no se sepa el mensaje: sin esto ese caso
    //    desaparece sin dejar rastro.
    if ($codigo >= 400) {
        return [v4LogTipoPorCodigo($codigo), 'Respuesta no interpretable: '
            . ($salida === '' ? '(vacia)' : substr($salida, 0, 300))];
    }

    return [null, ''];
}

/**
 * Extrae el sobre `{"ok":...}` de la respuesta. Devuelve
 * `[array|null $sobre, string $ruido]`, donde `$ruido` es lo que PHP haya
 * emitido ANTES del sobre.
 *
 * El reintento no es teorico: /v4/telegram/mensajes carga el phar de
 * MadelineProto, que dispara deprecations de Revolt hacia el cuerpo de la
 * respuesta. El JSON sale igual, pero detras de un par de `<b>Deprecated</b>`,
 * y un json_decode() del buffer entero devuelve null — con lo cual el motivo
 * real ("The endpoint does not exist!") se perdia y quedaba una fila que solo
 * mostraba el HTML del aviso.
 */
function v4LogSobre(string $salida): array {
    $directo = json_decode($salida, true);
    if (is_array($directo)) return [$directo, ''];

    // Los avisos de PHP van siempre ANTES del sobre (jsonOk/jsonError son lo
    // ultimo que se ejecuta), asi que la PRIMERA apertura es la del sobre:
    // una `{"ok"` posterior seria texto adentro del propio mensaje de error.
    $pos = strpos($salida, '{"ok"');
    if ($pos === false || $pos === 0) return [null, ''];

    $sobre = json_decode(substr($salida, $pos), true);
    if (!is_array($sobre)) return [null, ''];

    return [$sobre, trim(strip_tags(substr($salida, 0, $pos)))];
}

/**
 * 4xx = el caller mando algo que no corresponde -> `alerta`.
 * 5xx = se rompio el servicio -> `error`. Cualquier combinacion rara (un
 * ok:false con status 200, por ejemplo) cae tambien en `error`: si el codigo
 * y el cuerpo no coinciden, el problema es de este lado.
 */
function v4LogTipoPorCodigo(int $codigo): string {
    return ($codigo >= 400 && $codigo <= 499) ? 'alerta' : 'error';
}

/**
 * Arma el texto de la columna `Detalle`. Tres lineas como mucho:
 * que se pidio, que fallo, y quien lo pidio.
 */
function v4LogDetalle(int $codigo, string $motivo): string {
    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '?'));
    // REQUEST_URI ya trae la query string. El apikey no viaja ahi (va en el
    // header Authorization), asi que no hay secretos que filtrar.
    $uri = (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '?');

    $lineas = ["{$metodo} {$uri} -> HTTP {$codigo}", $motivo];

    $pie = [];
    $app = v4LogAppNombre();
    if ($app !== null && $app !== '') $pie[] = 'app: ' . $app;
    $ip = v4LogIp();
    if ($ip !== '')                   $pie[] = 'ip: ' . $ip;
    if ($pie)                         $lineas[] = implode(' | ', $pie);

    return implode("\n", $lineas);
}

// Detras del nginx que publica api.databox.net.ar, REMOTE_ADDR es el del
// proxy; el del cliente real viaja en X-Forwarded-For (primer valor de la
// cadena, que es el que agrego el borde).
function v4LogIp(): string {
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        $primera = trim(explode(',', $xff)[0]);
        if ($primera !== '') return $primera;
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}
