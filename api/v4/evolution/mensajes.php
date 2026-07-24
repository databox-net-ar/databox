<?php
// api/v4/evolution/mensajes.php
// Microservicio de ingesta de mensajes de Evolution API.
// Alta a la cola de envio y consulta del estado de un mensaje encolado.
//
//   POST /v4/evolution/mensajes           (JSON body) -> encola, devuelve {id, estado, encolado}
//   GET  /v4/evolution/mensajes?id=N                  -> estado actual del mensaje N
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php).
//
// Tabla destino: `evolution_mensajes` (schema en db/schema.sql). Estados
// (columna `estado`, char(1)) segun la convencion del panel cloud:
//   P = Pendiente   E = Enviado   F = Fallado   C = Cancelado   R = Reintento
// Este endpoint solo inserta con estado='P'; el sender externo actualiza el
// resto (`estado`, `enviado`, `demora`, `error`).
//
// Archivo autocontenido a proposito: no depende de cloud/api/lib/*, para que
// v4 pueda evolucionar (y eventualmente moverse a otro DocumentRoot) sin
// arrastrar el runtime del panel.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';

// ---------------------------------------------------------------------------
// Helpers de respuesta / DB / auth
// ---------------------------------------------------------------------------

function jsonOk(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'databox_dev';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: 'root';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) jsonError('Cuerpo no es JSON valido', 400);
    return $j;
}

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
        handleEnqueue(readJsonBody());
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
// POST /v4/evolution/mensajes  -> encolar
// ---------------------------------------------------------------------------

function nullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function nullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// Acepta "2026-07-24T20:15", "2026-07-24 20:15" o "2026-07-24 20:15:00" y
// devuelve el formato MySQL "Y-m-d H:i:s". Formato invalido -> null (el
// default del handleEnqueue se hace cargo).
function nullableDateTime(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function handleEnqueue(array $in): void {
    // Aceptamos tanto `canal_id` (nuevo, alineado con la columna) como el alias
    // corto `canal` para no romper clientes viejos durante la transicion. Idem
    // proyecto_id / plantilla_id.
    $canal   = nullableInt($in['canal_id'] ?? $in['canal'] ?? null);
    $destino = nullableStr($in['destino']  ?? null, 255);
    if ($canal === null || $canal <= 0) jsonError('Falta canal_id (int > 0)', 400);
    if ($destino === null)              jsonError('Falta destino (string no vacio)', 400);

    // El mensaje necesita al menos una fuente de contenido: una plantilla
    // pre-definida o un cuerpo inline. Sin alguna de las dos el sender no
    // tendria que enviar.
    $plantilla = nullableInt($in['plantilla_id'] ?? $in['plantilla'] ?? null);
    $cuerpo    = nullableStr($in['cuerpo']       ?? null);
    if ($plantilla === null && $cuerpo === null) {
        jsonError('Se requiere plantilla_id o cuerpo', 400);
    }

    $p = [
        'proyecto_id'  => nullableInt($in['proyecto_id'] ?? $in['proyecto'] ?? null),
        'canal_id'     => $canal,
        'plantilla_id' => $plantilla,
        'remitente'    => nullableStr($in['remitente']    ?? null, 255),
        'remite'       => nullableStr($in['remite']       ?? null, 255),
        'destinatario' => nullableStr($in['destinatario'] ?? null, 255),
        'destino'      => $destino,
        'prioridad'    => nullableInt($in['prioridad']    ?? null),
        'asunto'       => nullableStr($in['asunto']       ?? null, 255),
        'cuerpo'       => $cuerpo,
        'formato'      => nullableStr($in['formato']      ?? null, 20),
        'adjunto'      => nullableStr($in['adjunto']      ?? null, 500),
        'tags'         => nullableStr($in['tags']         ?? null, 255),
        'programado'   => nullableDateTime($in['programado'] ?? null),
    ];

    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
             ->format('Y-m-d H:i:s');

    // Defaults en profundidad (mismos que aplica cloud/api/evolutionmensajes.php):
    //   formato    -> 'texto'
    //   prioridad  -> 3 (media)
    //   programado -> NOW (enviar apenas el worker lo tome)
    if ($p['formato']    === null) $p['formato']    = 'texto';
    if ($p['prioridad']  === null) $p['prioridad']  = 3;
    if ($p['programado'] === null) $p['programado'] = $ahora;

    $sql = "INSERT INTO evolution_mensajes
                (fecha, proyecto_id, canal_id, plantilla_id, remitente, remite, destinatario,
                 destino, prioridad, asunto, cuerpo, formato,
                 adjunto, tags, estado, encolado, programado)
            VALUES
                (:fecha, :proyecto_id, :canal_id, :plantilla_id, :remitente, :remite, :destinatario,
                 :destino, :prioridad, :asunto, :cuerpo, :formato,
                 :adjunto, :tags, 'pendiente', :encolado, :programado)";
    $pdo = db();
    $st  = $pdo->prepare($sql);
    $st->execute([
        ':fecha'        => $ahora,
        ':proyecto_id'  => $p['proyecto_id'],
        ':canal_id'     => $p['canal_id'],
        ':plantilla_id' => $p['plantilla_id'],
        ':remitente'    => $p['remitente'],
        ':remite'       => $p['remite'],
        ':destinatario' => $p['destinatario'],
        ':destino'      => $p['destino'],
        ':prioridad'    => $p['prioridad'],
        ':asunto'       => $p['asunto'],
        ':cuerpo'       => $p['cuerpo'],
        ':formato'      => $p['formato'],
        ':adjunto'      => $p['adjunto'],
        ':tags'         => $p['tags'],
        ':encolado'     => $ahora,
        ':programado'   => $p['programado'],
    ]);

    jsonOk([
        'id'         => (int)$pdo->lastInsertId(),
        'estado'     => 'pendiente',
        'encolado'   => $ahora,
        'programado' => $p['programado'],
    ], 201);
}

// ---------------------------------------------------------------------------
// GET /v4/evolution/mensajes?id=N  -> consultar estado
// ---------------------------------------------------------------------------

// Etiquetas de estado alineadas con la tabla `estados` (evolution_mensaje_estado).
// Solo se usan para el campo `estado_label` de conveniencia — la fuente de
// verdad del valor sigue siendo `evolution_mensajes.estado` (varchar 20).
const EVO_MSG_ESTADO_LABEL = [
    'pendiente' => 'Pendiente',
    'enviando'  => 'Enviando',
    'enviado'   => 'Enviado',
    'anulado'   => 'Anulado',
    'error'     => 'Error',
];

function handleStatus(int $id): void {
    $pdo = db();
    $st  = $pdo->prepare(
        "SELECT id, canal_id, destino, estado, error, encolado, enviado, demora
         FROM evolution_mensajes WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);

    $row['id']           = (int)$row['id'];
    $row['canal_id']     = $row['canal_id'] !== null ? (int)$row['canal_id'] : null;
    $row['demora']       = $row['demora'] !== null ? (int)$row['demora'] : null;
    $row['estado_label'] = EVO_MSG_ESTADO_LABEL[$row['estado']] ?? $row['estado'];

    jsonOk($row);
}
