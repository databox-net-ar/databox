<?php
// api/datarocket_expertos_chat.php
// Datarocket > Expertos > Conversar: hablar CON el experto, no sobre él.
//
//   POST api/datarocket_expertos_chat.php
//   body: { experto_id: N, mensajes: [{rol: 'user'|'assistant', texto}] }
//
// Respuesta: {ok: true, data: {respuesta, modelo, tokens_entrada, tokens_salida}}
//
// EN QUE SE DIFERENCIA DE _mejorar.php
// ------------------------------------
// `_mejorar.php` es el EDITOR: le habla a un redactor de prompts y le pide que
// cambie el documento. Este es el BANCO DE PRUEBAS: monta al experto tal como va
// a atender a un interesado y le hace preguntas. Uno escribe la definicion, el
// otro la ejecuta.
//
// EL CONTEXTO SE USA CRUDO, SIN ENVOLTORIO
// ----------------------------------------
// El `contexto` del experto se manda como mensaje `system` TAL CUAL esta
// guardado. Es a proposito y es lo unico que hace util esta pantalla: si le
// agregaramos instrucciones nuestras ("sos un asistente de prueba", "se breve"),
// lo que se prueba dejaria de ser el experto y pasaria a ser el experto MAS
// nuestro agregado — y el dia que un canal real lo consuma, contestaria
// distinto de lo que se vio aca. Lo que se ve en esta pantalla es exactamente
// lo que va a contestar en produccion.
//
// Por eso mismo un experto sin contexto no se puede probar: sin prompt de
// sistema el modelo contesta como un asistente generico, que es justamente el
// resultado que haria pensar que el experto "anda" cuando no esta definido.
//
// NO ESCRIBE NADA. Ni el experto ni la conversacion se persisten: el historial
// vive en el navegador y se pierde al cerrar. Es una prueba, no un canal de
// atencion — el dia que haya canales reales, van a tener su propia tabla de
// conversaciones.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/parametros.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

// Mismo default que el editor, pero parametro propio: son dos usos con
// economias distintas. El editor corre de a una vez por sesion de redaccion; el
// experto, el dia que lo consuma un canal real, contesta en volumen — ahi puede
// convenir un modelo mas barato sin tocar el del editor.
const DREXCH_MODELO_DEFAULT = 'gpt-5.6-sol';

const DREXCH_TIMEOUT = 120;

// Turnos del historial que se reenvian. El experto atiende consultas de
// interesados: conversaciones cortas, no expedientes.
const DREXCH_HISTORIAL_MAX = 20;

// Tope de salida. Holgado respecto de lo que dura una respuesta a un interesado
// porque los reasoning tokens de la linea gpt-5 salen de este mismo presupuesto
// y, si queda corto, la respuesta vuelve VACIA en vez de truncada.
const DREXCH_MAX_SALIDA = 4000;

try {
    requirePermission('datarocket.expertos.consultar');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Método no soportado', 405);
    }
    if (!defined('OPENAI_API_KEY') || (string) constant('OPENAI_API_KEY') === '') {
        jsonError('Falta configurar OPENAI_API_KEY en el .env', 500);
    }

    @set_time_limit(DREXCH_TIMEOUT + 20);

    $pdo  = db();
    $body = readJsonBody();

    // Se siembra ANTES de cualquier validación para que el parámetro aparezca
    // en Herramientas > Editor de parámetros aunque el primer intento falle
    // (que es lo que pasa si el primer experto que se prueba está sin contexto).
    parametroAsegurar($pdo, 'datarocket_expertos_modelo_chat', DREXCH_MODELO_DEFAULT,
        'Modelo de OpenAI con el que responden los expertos de Datarocket (Conversar). '
        . 'Separado del de Mejorar: el experto contesta en volumen y el editor no.');
    $modelo = trim((string) parametroLeer($pdo, 'datarocket_expertos_modelo_chat', DREXCH_MODELO_DEFAULT));
    if ($modelo === '') $modelo = DREXCH_MODELO_DEFAULT;

    $id = (int) ($body['experto_id'] ?? 0);
    if ($id <= 0) jsonError('Falta el experto', 400);

    $st = $pdo->prepare('SELECT id, nombre, contexto, activo FROM datarocket_expertos WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $exp = $st->fetch();
    if (!$exp) jsonError('Experto no encontrado', 404);

    $contexto = trim((string) ($exp['contexto'] ?? ''));
    if ($contexto === '') {
        jsonError('Este experto no tiene contexto cargado: sin prompt de sistema no hay nada que probar. '
                . 'Cargáselo desde Editar > Contexto (o pedíselo al chat de Mejorar).', 400);
    }

    // El historial llega entero del navegador y el ULTIMO mensaje es la
    // pregunta recién escrita. Se valida que haya al menos uno del interesado.
    $historial = is_array($body['mensajes'] ?? null) ? $body['mensajes'] : [];
    $historial = array_slice($historial, -DREXCH_HISTORIAL_MAX);

    $mensajes = [['role' => 'system', 'content' => $contexto]];
    $hayUser  = false;
    foreach ($historial as $m) {
        $texto = trim((string) ($m['texto'] ?? ''));
        if ($texto === '') continue;
        if (mb_strlen($texto) > 8000) $texto = mb_substr($texto, 0, 8000);
        $rol = ($m['rol'] ?? '') === 'assistant' ? 'assistant' : 'user';
        if ($rol === 'user') $hayUser = true;
        $mensajes[] = ['role' => $rol, 'content' => $texto];
    }
    if (!$hayUser) jsonError('Escribí una consulta.', 400);

    $payload = [
        'model'    => $modelo,
        'messages' => $mensajes,
        // `max_completion_tokens` y no `max_tokens`: la línea gpt-5 rechaza el
        // segundo con un 400. `temperature` no se manda — esos modelos sólo
        // aceptan el default.
        'max_completion_tokens' => DREXCH_MAX_SALIDA,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => DREXCH_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . constant('OPENAI_API_KEY'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        jsonError('Error cURL contra OpenAI: ' . curl_error($ch), 502);
    }
    $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpcode < 200 || $httpcode >= 300) {
        $det = json_decode((string) $response, true);
        jsonError('OpenAI respondió HTTP ' . $httpcode . ': '
            . ($det['error']['message'] ?? substr((string) $response, 0, 500)), 502);
    }

    $data   = json_decode((string) $response, true);
    $choice = $data['choices'][0] ?? [];
    $texto  = trim((string) ($choice['message']['content'] ?? ''));

    if ($texto === '') {
        $razon = (string) ($choice['finish_reason'] ?? '');
        jsonError($razon === 'length'
            ? 'El modelo se quedó sin presupuesto de salida antes de contestar. Suele pasar con contextos muy largos: probá acortar el prompt del experto.'
            : 'Respuesta vacía de OpenAI.', 502);
    }

    jsonOk([
        'respuesta'      => $texto,
        'modelo'         => (string) ($data['model'] ?? $modelo),
        'tokens_entrada' => (int) ($data['usage']['prompt_tokens']     ?? 0),
        'tokens_salida'  => (int) ($data['usage']['completion_tokens'] ?? 0),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
