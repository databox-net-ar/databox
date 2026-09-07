<?php
// api/lib/evolution_canales_reiniciar.php
// Reinicio de una instancia de Evolution API. Es el equivalente exacto de los
// dos botones del dashboard del Manager:
//
//   RESTART           -> POST /instance/restart/{slug}
//   el icono de al lado -> GET  /instance/connectionState/{slug}
//
// Los metodos estan verificados contra el servidor real (Evolution 2.3.7):
// GET y PUT sobre /instance/restart devuelven 404 "Cannot GET|PUT ...", solo
// POST entra al controller. No cambiarlos sin volver a probar: la firma varia
// entre v1 y v2 de Evolution.
//
// POR QUE VIVE EN UNA LIB Y NO ADENTRO DEL JOB
// -------------------------------------------
// Hoy la usa un solo caller (cloud/jobs/evolution_canales_reiniciar.php), pero
// "reiniciar un canal" es una operacion de dominio con reglas propias -- no
// declarar exito hasta ver el canal de vuelta en 'open', no pisar el cache de
// `canalEstado` -- y un boton "Reiniciar" en el menu contextual del ABM de
// canales va a querer exactamente esto. Una sola puerta por operacion.
//
// QUE REINICIA Y QUE NO
// ---------------------
// Reinicia la CONEXION, no la sesion: las credenciales vinculadas siguen
// guardadas, asi que un canal sano se cae y vuelve solo en pocos segundos. Un
// canal deslogueado (sin credenciales) NO se recupera con esto: se queda en
// 'connecting' y hay que escanear el QR a mano. Por eso la funcion devuelve el
// estado final observado y no un booleano pelado -- el que llama tiene que
// poder distinguir "reinicie y volvio" de "reinicie y quedo colgado".
//
// POR QUE NO ESCRIBE `canalEstado`
// --------------------------------
// Esa columna la mantiene cloud/jobs/evolution_canales_actualizar_estados.php
// con el JSON completo de /instance/fetchInstances (ownerJid, profileName,
// contadores) y es lo que muestra el modal "Consultar" del ABM.
// /instance/connectionState devuelve solo {instanceName, state}: guardarlo ahi
// seria cambiar un cache rico por uno pobre. Esta lib toca unicamente
// `online`, `latido` y `actualizado`, que es lo que si sabe de primera mano.

require_once __DIR__ . '/../db.php';

// Mismo endpoint que usan api/lib/mensajes_enviar.php y el job de estados.
// Se declara con guard (y con nombre propio) para que incluir esta lib junto
// con cualquiera de esos dos archivos no dispare un "constant already defined".
if (!defined('EVO_API_BASE')) {
    define('EVO_API_BASE', 'https://evolution.york.databox.net.ar');
}

// Timeout de cada request HTTP suelta contra Evolution. Corto a proposito: el
// restart responde de inmediato (el trabajo pesado lo hace en background) y el
// connectionState es una lectura de memoria.
const EVO_REINICIO_TIMEOUT_HTTP = 20;

// Cuanto se espera antes del primer sondeo. Preguntar el estado en el mismo
// instante del restart devuelve el 'open' viejo, todavia no derribado, y daria
// un falso OK.
const EVO_REINICIO_ESPERA_INICIAL = 5;

// Cada cuanto se vuelve a preguntar el estado mientras reconecta.
const EVO_REINICIO_POLL_SEG = 3;

/**
 * Reinicia la instancia de un canal y espera a verla de vuelta en 'open'.
 *
 * @param array         $canal  Fila de `evolution_canales`; se usan id, nombre,
 *                              slug y token.
 * @param callable|null $log    Callback opcional para el log en vivo del job.
 * @param array         $opts   ['verificar_seg' => int] cuantos segundos como
 *                              maximo esperar a que reconecte (0 = no
 *                              verificar, se devuelve estado null).
 *
 * @return array{
 *     ok: bool,            exito completo: reinicio aceptado Y canal en 'open'
 *     reiniciado: bool,    Evolution acepto el POST /instance/restart
 *     estado: ?string,     ultimo `state` observado ('open'|'connecting'|'close')
 *     segundos: int,       cuanto tardo en volver (o cuanto se espero en vano)
 *     error: string        vacio cuando ok = true
 * }
 *
 * No lanza: todo fallo de red, HTTP o JSON vuelve en `error` con ok = false,
 * porque el caller tipico es un loop sobre N canales y la caida de uno no
 * tiene que frenar a los demas.
 */
function evoCanalReiniciar(PDO $pdo, array $canal, ?callable $log = null, array $opts = []): array {
    $anotar = $log ?? static function (string $l): void {};

    $id    = (int)   ($canal['id']    ?? 0);
    $slug  = trim((string) ($canal['slug']  ?? ''));
    $token = trim((string) ($canal['token'] ?? ''));

    $verificarSeg = array_key_exists('verificar_seg', $opts)
        ? max(0, (int) $opts['verificar_seg'])
        : 45;

    $fallo = static function (string $msg, bool $reiniciado = false, ?string $estado = null): array {
        return ['ok' => false, 'reiniciado' => $reiniciado, 'estado' => $estado,
                'segundos' => 0, 'error' => $msg];
    };

    if ($id <= 0)      return $fallo('Canal sin id');
    if ($slug === '')  return $fallo('Canal sin slug (nombre de instancia en Evolution)');
    if ($token === '') return $fallo('Canal sin token (apikey de la instancia)');

    // --- 1. El boton RESTART ------------------------------------------------
    $anotar("POST /instance/restart/{$slug}");
    $r = evoApiLlamar('POST', "/instance/restart/{$slug}", $token);
    if (!$r['ok']) {
        return $fallo($r['error']);
    }

    // --- 2. El boton de refresco, hasta que vuelva o se acabe el plazo ------
    // Sin este paso el job seria un "toca y reza": el restart devuelve 200
    // apenas encola el reinicio, no cuando la sesion volvio a estar arriba.
    if ($verificarSeg === 0) {
        $anotar('Reinicio aceptado; verificacion desactivada (verificar_seg = 0)');
        return ['ok' => true, 'reiniciado' => true, 'estado' => null,
                'segundos' => 0, 'error' => ''];
    }

    $inicio = time();
    sleep(min(EVO_REINICIO_ESPERA_INICIAL, $verificarSeg));

    $estado    = null;
    $ultimoErr = '';
    while (true) {
        $c = evoCanalConnectionState($canal);
        if ($c['ok']) {
            $estado    = $c['estado'];
            $ultimoErr = '';
            if ($estado === 'open') break;
        } else {
            // Un 404 / 500 durante la reconexion es esperable: la instancia
            // esta a medio levantar. Se guarda por si el plazo se agota sin
            // que llegue a responder nunca.
            $ultimoErr = $c['error'];
        }

        if (time() - $inicio >= $verificarSeg) break;
        sleep(min(EVO_REINICIO_POLL_SEG, max(1, $verificarSeg - (time() - $inicio))));
    }

    $segundos = time() - $inicio;

    // --- 3. Cache del canal en la BD ---------------------------------------
    // `latido` solo se pisa cuando el canal esta arriba: el contrato de esa
    // columna (fijado por el job de estados) es "ultima vez que se lo supo
    // vivo", no "ultima vez que se lo miro".
    evoCanalPersistirOnline($pdo, $id, $estado === 'open');

    if ($estado === 'open') {
        $anotar("Volvio a 'open' en {$segundos}s");
        return ['ok' => true, 'reiniciado' => true, 'estado' => 'open',
                'segundos' => $segundos, 'error' => ''];
    }

    // Reinicio aceptado pero el canal no volvio. El caso mas comun es una
    // sesion deslogueada, que necesita QR y no se arregla reintentando.
    $detalle = $estado !== null
        ? "quedo en '{$estado}'"
        : ($ultimoErr !== '' ? "no respondio ({$ultimoErr})" : 'no respondio');
    $anotar("Reinicio aceptado pero {$detalle} tras {$segundos}s");

    return ['ok' => false, 'reiniciado' => true, 'estado' => $estado,
            'segundos' => $segundos,
            'error' => "Reinicio aceptado pero el canal {$detalle} tras {$segundos}s"
                     . ($estado === 'connecting' || $estado === 'close'
                        ? ' (revisar si la sesion quedo deslogueada y necesita QR)'
                        : '')];
}

/**
 * Lee el estado de conexion de un canal. Es el icono de refresco del Manager.
 * Solo lectura: no toca la BD.
 *
 * @return array{ok: bool, estado: ?string, error: string}
 */
function evoCanalConnectionState(array $canal): array {
    $slug  = trim((string) ($canal['slug']  ?? ''));
    $token = trim((string) ($canal['token'] ?? ''));
    if ($slug === '' || $token === '') {
        return ['ok' => false, 'estado' => null, 'error' => 'Canal sin slug/token'];
    }

    $r = evoApiLlamar('GET', "/instance/connectionState/{$slug}", $token);
    if (!$r['ok']) {
        return ['ok' => false, 'estado' => null, 'error' => $r['error']];
    }

    // Forma de la respuesta en 2.3.7: {"instance":{"instanceName":"x","state":"open"}}
    $estado = $r['data']['instance']['state'] ?? null;
    if (!is_string($estado) || $estado === '') {
        return ['ok' => false, 'estado' => null,
                'error' => 'Respuesta sin instance.state: ' . substr($r['raw'], 0, 200)];
    }
    return ['ok' => true, 'estado' => $estado, 'error' => ''];
}

/**
 * Refresca en `evolution_canales` lo unico que esta lib sabe de primera mano.
 * Ver el docblock de cabecera para por que no se toca `canalEstado`.
 */
function evoCanalPersistirOnline(PDO $pdo, int $id, bool $online): void {
    $st = $pdo->prepare("
        UPDATE evolution_canales
           SET online      = :online,
               latido      = CASE WHEN :online_flag = '1' THEN NOW() ELSE latido END,
               actualizado = NOW()
         WHERE id = :id
    ");
    $flag = $online ? '1' : '0';
    $st->execute([':online' => $flag, ':online_flag' => $flag, ':id' => $id]);
}

/**
 * Request contra Evolution API con la apikey del canal. Devuelve
 * ['ok' => bool, 'status' => int, 'data' => ?array, 'raw' => string,
 *  'error' => string]. Encapsula fallos de red y HTTP no-2xx en ok = false.
 */
function evoApiLlamar(string $metodo, string $ruta, string $token): array {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => EVO_API_BASE . $ruta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => EVO_REINICIO_TIMEOUT_HTTP,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'apikey: ' . $token,
        ],
    ]);
    $body   = curl_exec($curl);
    $err    = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $raw = (string) $body;

    if ($err !== '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '',
                'error' => "cURL: {$err}"];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'raw' => $raw,
                'error' => "HTTP {$status}: " . substr($raw, 0, 200)];
    }

    $data = json_decode($raw, true);
    return ['ok' => true, 'status' => $status,
            'data' => is_array($data) ? $data : null, 'raw' => $raw, 'error' => ''];
}
