<?php
// cloud/api/lib/evolution_api.php
// Cliente HTTP contra Evolution API (el servidor de WhatsApp) — transporte
// puro, sin reglas de dominio y sin tocar la base.
//
// POR QUE EXISTE
// --------------
// Hasta el port de las operaciones de grupos habia tres copias del mismo
// bloque de cURL repartidas por el stack:
//
//   cloud/api/lib/mensajes_enviar.php           -> EVOLUTION_ENDPOINT + evolutionApiEnviar()
//   cloud/api/lib/evolution_canales_reiniciar.php -> EVO_API_BASE + evoApiLlamar()
//   cloud/jobs/evolution_canales_actualizar_estados.php -> EVOLUTION_ENDPOINT + curl inline
//
// Tres constantes con la misma URL y tres criterios de error distintos. Los
// endpoints de grupos habrian sido la cuarta. Esta lib es la puerta unica del
// transporte: quien quiera hablar con Evolution llama a evoApiLlamar().
//
// `mensajes_enviar.php` queda como esta a proposito: tiene su propio armado de
// payload por formato y su propio manejo del "200 con {error:...} adentro", y
// migrarlo obligaria a revalidar el sender entero. La deuda esta anotada, no
// resuelta.
//
// CONTRATO
// --------
// evoApiLlamar() NUNCA lanza: todo fallo (red, HTTP no-2xx, timeout) vuelve
// como ok=false con el detalle en `error`. El caller tipico recorre N canales
// y la caida de uno no puede frenar a los demas.

require_once __DIR__ . '/../db.php';

// Mismo host que usan el sender y el job de estados. Se define con guard
// porque evolution_canales_reiniciar.php lo exponia antes y algun caller
// podria seguir incluyendo los dos archivos.
if (!defined('EVO_API_BASE')) {
    define('EVO_API_BASE', 'https://evolution.york.databox.net.ar');
}

// Techo por request. Generoso porque /group/fetchAllGroups de una cuenta con
// cientos de grupos tarda varios segundos del lado de Evolution.
const EVO_API_TIMEOUT_SEG = 30;

// Solo el handshake. Si Evolution no acepta la conexion en 10s no esta lento:
// esta caido, y conviene enterarse rapido.
const EVO_API_CONNECT_TIMEOUT_SEG = 10;

/**
 * Request contra Evolution API autenticado con la apikey del canal.
 *
 * @param string     $metodo  GET | POST | PUT | DELETE
 * @param string     $ruta    Con barra inicial, ej. "/group/create/mi-bot"
 * @param string     $token   `evolution_canales.token` (apikey de la instancia)
 * @param array|null $payload Body JSON. null = request sin cuerpo.
 * @param int        $timeout Segundos. Default EVO_API_TIMEOUT_SEG.
 *
 * @return array{ok: bool, status: int, data: ?array, raw: string, error: string}
 *         `data` es el JSON decodificado (null si la respuesta no era un
 *         objeto/array JSON). `raw` siempre trae el cuerpo tal cual vino.
 */
function evoApiLlamar(
    string $metodo,
    string $ruta,
    string $token,
    ?array $payload = null,
    int $timeout = EVO_API_TIMEOUT_SEG
): array {
    $cabeceras = [
        'accept: application/json',
        'apikey: ' . $token,
    ];

    $opciones = [
        CURLOPT_URL            => EVO_API_BASE . $ruta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => EVO_API_CONNECT_TIMEOUT_SEG,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => $metodo,
    ];

    if ($payload !== null) {
        $cabeceras[] = 'content-type: application/json';
        // JSON_UNESCAPED_UNICODE: los nombres de grupo llevan acentos y emojis,
        // y Evolution los guarda tal cual llegan.
        $opciones[CURLOPT_POSTFIELDS] = json_encode(
            $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    $opciones[CURLOPT_HTTPHEADER] = $cabeceras;

    $curl = curl_init();
    curl_setopt_array($curl, $opciones);
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

/**
 * Normaliza un numero al JID que espera WhatsApp. Si ya viene con el sufijo
 * correcto lo devuelve intacto.
 *
 *   evoJid('5492644984568')            -> '5492644984568@s.whatsapp.net'
 *   evoJid('120363404912440827', 'g')  -> '120363404912440827@g.us'
 *
 * Espeja mcEvolution::jid() del legacy (databox_legacy/databox-api/modulos/evolution.php).
 *
 * @param string $tipo 'u' = usuario | 'g' = grupo
 */
function evoJid(string $numero, string $tipo = 'u'): string {
    $numero = trim($numero);
    if ($numero === '') return '';

    $sufijo = $tipo === 'g' ? '@g.us' : '@s.whatsapp.net';
    if (strpos($numero, $sufijo) !== false) return $numero;

    // Un JID del otro tipo se devuelve como vino: corregirlo por las nuestras
    // taparia un error del caller (mandar un grupo donde va un usuario) con un
    // request que Evolution rechaza sin explicar.
    if (strpos($numero, '@') !== false) return $numero;

    return $numero . $sufijo;
}

/**
 * Elige la instancia que corresponde a un canal dentro de la respuesta de
 * /instance/fetchInstances (que es un array, aunque con apikey de canal suela
 * traer una sola).
 *
 * Preferencia: match por slug (`name` / `instanceName`) o por token.
 * Fallback: la primera. Devuelve null si el array esta vacio.
 *
 * @param array $canal Fila de `evolution_canales`; se usan `slug` y `token`.
 */
function evoElegirInstancia(array $data, array $canal): ?array {
    if (!$data) return null;
    $slug  = (string) ($canal['slug']  ?? '');
    $token = (string) ($canal['token'] ?? '');
    foreach ($data as $inst) {
        if (!is_array($inst)) continue;
        $instName  = (string) ($inst['name'] ?? $inst['instanceName'] ?? '');
        $instToken = (string) ($inst['token'] ?? '');
        if ($slug  !== '' && $instName  === $slug)  return $inst;
        if ($token !== '' && $instToken === $token) return $inst;
    }
    $first = $data[0] ?? null;
    return is_array($first) ? $first : null;
}
