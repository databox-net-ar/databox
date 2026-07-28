<?php
// api/v4/telegram/mensajes.php
// Microservicio de ingesta de mensajes de Telegram (Bot API).
// Alta con envio SINCRONO y consulta del resultado de un mensaje enviado.
//
//   POST /v4/telegram/mensajes           (JSON body) -> envia al toque, devuelve {id, estado, error, enviado}
//   GET  /v4/telegram/mensajes?id=N                  -> resultado del mensaje N
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack -- ver cloud/api/lib/apikey_auth.php).
//
// Tabla destino: `telegram_mensajes` (schema en db/schema.sql).
//
// Punto UNICO de entrada: el envio se delega a
// `cloud/api/lib/telegram_mensajes.php::enviarTelegramMensaje()`, la misma
// funcion que usa el ABM cloud. Asi ambos callers aplican las mismas reglas
// de sanitizacion, obligatorios, defaults y envio sincrono contra la Bot API.
//
// A diferencia del microservicio v4 de Evolution API, aca NO hay cola ni
// bandera de motor: el envio es sincrono y la respuesta del endpoint refleja
// el resultado real (Telegram acepto o rechazo el mensaje).

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/telegram_mensajes.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// db() / jsonOk() / jsonError() / readJsonBody() vienen del panel cloud via
// el require_once de arriba (cloud/api/lib/telegram_mensajes.php -> cloud/api/db.php).
// Aca solo agregamos los helpers propios del microservicio: lectura del Bearer
// y validacion del apikey contra la tabla `aplicaciones`.

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

    // Contador de uso -- best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// Etiquetas de estado alineadas con la tabla `estados` (telegram_mensaje_estado).
// Solo se usan para el campo `estado_label` de conveniencia -- la fuente de
// verdad del valor sigue siendo `telegram_mensajes.estado` (varchar 20).
const TG_MSG_ESTADO_LABEL = [
    'enviando' => 'Enviando',
    'enviado'  => 'Enviado',
    'error'    => 'Error',
];

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    requireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleSend(readJsonBody());
    } elseif ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) jsonError('Falta id (int > 0)', 400);
        handleStatus($id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// POST /v4/telegram/mensajes  -> enviar (sincrono)
// ---------------------------------------------------------------------------
//
// Toda la logica de sanitizacion, validacion, defaults y envio HTTP contra la
// Bot API vive en la funcion compartida enviarTelegramMensaje()
// (cloud/api/lib/telegram_mensajes.php). El handler v4 solo mapea la respuesta
// HTTP y traduce las excepciones a los codigos de status apropiados.

function handleSend(array $in): void {
    try {
        $id = enviarTelegramMensaje(db(), $in);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
        return; // inalcanzable -- jsonError() hace exit; aca solo para el analizador estatico.
    }

    // Releemos las columnas system-managed (fecha/estado/enviado/demora/error)
    // que el envio deja seteadas -- asi la respuesta refleja fielmente si
    // Telegram acepto el mensaje o dio error.
    $st = db()->prepare("SELECT fecha, estado, enviado, demora, error
                           FROM telegram_mensajes WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch() ?: [];

    $estado = $r['estado'] ?? 'error';

    jsonOk([
        'id'           => $id,
        'estado'       => $estado,
        'estado_label' => TG_MSG_ESTADO_LABEL[$estado] ?? $estado,
        'fecha'        => $r['fecha']   ?? null,
        'enviado'      => $r['enviado'] ?? null,
        'demora'       => $r['demora']  !== null ? (int)$r['demora'] : null,
        'error'        => $r['error']   ?? null,
    ], $estado === 'enviado' ? 201 : 502);
}

// ---------------------------------------------------------------------------
// GET /v4/telegram/mensajes?id=N  -> consultar resultado
// ---------------------------------------------------------------------------

function handleStatus(int $id): void {
    $pdo = db();
    $st  = $pdo->prepare(
        "SELECT id, canal_id, destino, estado, error, encolado, enviado, demora
         FROM telegram_mensajes WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);

    $row['id']           = (int)$row['id'];
    $row['canal_id']     = $row['canal_id'] !== null ? (int)$row['canal_id'] : null;
    $row['demora']       = $row['demora'] !== null ? (int)$row['demora'] : null;
    $row['estado_label'] = TG_MSG_ESTADO_LABEL[$row['estado']] ?? $row['estado'];

    jsonOk($row);
}
