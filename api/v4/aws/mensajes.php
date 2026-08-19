<?php
// api/v4/aws/mensajes.php
// Microservicio de ingesta de mensajes AWS SES.
// Alta a la cola de envio y consulta del estado de un mensaje encolado.
//
//   POST /v4/aws/mensajes           (JSON body) -> encola, devuelve {id, uuid, estado, fecha, encolado, programado}
//   GET  /v4/aws/mensajes                       -> listado con filtros (query string)
//   GET  /v4/aws/mensajes?id=N                  -> estado actual del mensaje N (incluye uuid y resultado)
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el resto
// del stack — ver cloud/api/lib/apikey_auth.php).
//
// Tabla destino: `aws_mensajes` (schema en db/schema.sql).
//
// Punto UNICO de entrada: la insercion se delega a
// `cloud/api/lib/aws_mensajes.php::encolarAwsMensaje()`, la misma funcion que
// usa el ABM cloud. Asi ambos callers aplican las mismas reglas de
// sanitizacion, obligatorios y defaults, y ambos levantan la bandera
// `parametros.aws.mensajes.enviar='2'` para despertar al sender worker
// (cloud/jobs/aws_mensajes_enviar.php).
//
// Espejo estructural de api/v4/evolution/mensajes.php.
//
// (Cuando v4 se mueva a otro DocumentRoot habra que reajustar el include del
// require_once — es el unico acoplamiento con el runtime del panel.)

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/aws_mensajes.php';
require_once dirname(__DIR__) . '/_lib/log.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
// El endpoint hermano /v4/aws/eventos ya registraba los suyos a mano.
v4InitLog('v4/aws.mensajes');

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// db() / jsonOk() / jsonError() / readJsonBody() vienen del panel cloud via
// el require_once de arriba (cloud/api/lib/aws_mensajes.php -> cloud/api/db.php).
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

    // Contador de uso — best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// Etiquetas de estado alineadas con la tabla `estados` (aws_mensaje_estado).
// Solo se usan para el campo `estado_label` de conveniencia — la fuente de
// verdad del valor sigue siendo `aws_mensajes.estado` (varchar 20).
// Se declara aca (antes del dispatcher) porque `const` a nivel de archivo
// se registra cuando la ejecucion pasa por esa linea, no al compilar.
const AWS_MSG_ESTADO_LABEL = [
    'pendiente' => 'Pendiente',
    'enviando'  => 'Enviando',
    'enviado'   => 'Enviado',
    'anulado'   => 'Anulado',
    'error'     => 'Error',
];

// Etiquetas de resultado (entrega end-to-end) alineadas con la tabla
// `estados` (aws_mensaje_resultado). `resultado` lo pobla /v4/aws/eventos
// al recibir notificaciones SNS de SES (Delivery/Open/Click/Bounce/
// Complaint/Reject). Precedencia: nunca hace downgrade.
const AWS_MSG_RESULTADO_LABEL = [
    'entregado' => 'Entregado',
    'abierto'   => 'Abierto',
    'cliqueado' => 'Cliqueado',
    'spam'      => 'Spam',
    'rebotado'  => 'Rebotado',
    'rechazado' => 'Rechazado',
];

// ---------------------------------------------------------------------------
// Ruteo
// ---------------------------------------------------------------------------

try {
    v4LogApp(requireApp());
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleEnqueue(readJsonBody());
    } elseif ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) handleStatus($id);
        else         handleList($_GET);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// POST /v4/aws/mensajes  -> encolar
// ---------------------------------------------------------------------------
//
// Toda la logica de sanitizacion, validacion, defaults e INSERT vive en la
// funcion compartida encolarAwsMensaje() (cloud/api/lib/aws_mensajes.php).
// El handler v4 solo mapea la respuesta HTTP y traduce las excepciones a los
// codigos de status apropiados.

function handleEnqueue(array $in): void {
    try {
        $id = encolarAwsMensaje(db(), $in);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }

    // Releemos las columnas system-managed (fecha/encolado/programado/uuid
    // que el encolador puede haber defaulteado a NOW) para que la respuesta
    // sea fiel a lo persistido — asi el cliente v4 no tiene que adivinar.
    // `uuid` sale NULL al encolar; lo pobla el sender cuando SES devuelve el
    // MessageId. Aca lo devolvemos igual para no cambiar el shape entre
    // "recien encolado" y "ya despachado".
    $st = db()->prepare("SELECT uuid, fecha, encolado, programado, estado
                           FROM aws_mensajes WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch() ?: [];

    jsonOk([
        'id'         => $id,
        'uuid'       => $r['uuid']       ?? null,
        'estado'     => $r['estado']     ?? 'pendiente',
        'fecha'      => $r['fecha']      ?? null,
        'encolado'   => $r['encolado']   ?? null,
        'programado' => $r['programado'] ?? null,
    ], 201);
}

// ---------------------------------------------------------------------------
// GET /v4/aws/mensajes  -> listado con filtros
// ---------------------------------------------------------------------------
// Mismo espiritu que el listado del ABM cloud (`cloud/api/awsmensajes.php`)
// pero sin `stats` ni JOINs a nombres — el caller externo trabaja con ids.

function handleList(array $q): void {
    $codigo    = isset($q['codigo'])       && $q['codigo']       !== '' ? (int)$q['codigo']       : null;
    $proyecto  = isset($q['proyecto_id'])  && $q['proyecto_id']  !== '' ? (int)$q['proyecto_id']  : null;
    $canal     = isset($q['canal_id'])     && $q['canal_id']     !== '' ? (int)$q['canal_id']     : null;
    $plantilla = isset($q['plantilla_id']) && $q['plantilla_id'] !== '' ? (int)$q['plantilla_id'] : null;
    $estado    = trim((string)($q['estado']    ?? ''));
    $resultado = trim((string)($q['resultado'] ?? ''));
    $uuid      = trim((string)($q['uuid']      ?? ''));
    $desde     = trim((string)($q['desde']     ?? ''));
    $hasta     = trim((string)($q['hasta']     ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'proyecto_id', 'canal_id', 'plantilla_id',
                     'destino', 'asunto', 'estado', 'resultado', 'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'id = :codigo';                 $params[':codigo']       = $codigo; }
    if ($proyecto  !== null) { $where[] = 'proyecto_id = :proyecto_id';   $params[':proyecto_id']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'canal_id = :canal_id';         $params[':canal_id']     = $canal; }
    if ($plantilla !== null) { $where[] = 'plantilla_id = :plantilla_id'; $params[':plantilla_id'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'estado = :estado';             $params[':estado']       = $estado; }
    if ($resultado !== '')   { $where[] = 'resultado = :resultado';       $params[':resultado']    = $resultado; }
    if ($uuid      !== '')   { $where[] = 'uuid = :uuid';                 $params[':uuid']         = $uuid; }
    if ($desde     !== '')   { $where[] = 'fecha >= :desde';              $params[':desde']        = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'fecha <= :hasta';              $params[':hasta']        = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(destinatario LIKE :s1 OR destino LIKE :s2 OR asunto LIKE :s3
                     OR remitente LIKE :s4 OR remite LIKE :s5 OR tags LIKE :s6)';
        $like = "%{$search}%";
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT id, uuid, canal_id, proyecto_id, plantilla_id, prospecto_id,
                   remite, remitente, destino, destinatario, asunto,
                   estado, resultado, error, fecha, encolado, programado,
                   enviado, demora
              FROM aws_mensajes
              {$sqlWhere}
              ORDER BY {$orderBy} {$dirSql}
              LIMIT {$limite}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Anotar los labels de conveniencia (mismo criterio que handleStatus).
    foreach ($rows as &$r) {
        $r['id']              = (int)$r['id'];
        $r['canal_id']        = $r['canal_id']     !== null ? (int)$r['canal_id']     : null;
        $r['proyecto_id']     = $r['proyecto_id']  !== null ? (int)$r['proyecto_id']  : null;
        $r['plantilla_id']    = $r['plantilla_id'] !== null ? (int)$r['plantilla_id'] : null;
        $r['prospecto_id']     = $r['prospecto_id']  !== null ? (int)$r['prospecto_id']  : null;
        $r['demora']          = $r['demora']       !== null ? (int)$r['demora']       : null;
        $r['estado_label']    = AWS_MSG_ESTADO_LABEL[$r['estado']] ?? $r['estado'];
        $r['resultado_label'] = $r['resultado'] !== null
            ? (AWS_MSG_RESULTADO_LABEL[$r['resultado']] ?? $r['resultado'])
            : null;
    }
    unset($r);

    jsonOk([
        'total' => count($rows),
        'items' => $rows,
    ]);
}

// ---------------------------------------------------------------------------
// GET /v4/aws/mensajes?id=N  -> consultar estado individual
// ---------------------------------------------------------------------------

function handleStatus(int $id): void {
    $pdo = db();
    $st  = $pdo->prepare(
        "SELECT id, uuid, canal_id, remite, remitente, destino, destinatario, asunto,
                estado, resultado, error, fecha, encolado, programado, enviado, demora
           FROM aws_mensajes WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);

    $row['id']              = (int)$row['id'];
    $row['canal_id']        = $row['canal_id'] !== null ? (int)$row['canal_id'] : null;
    $row['demora']          = $row['demora']   !== null ? (int)$row['demora']   : null;
    $row['estado_label']    = AWS_MSG_ESTADO_LABEL[$row['estado']] ?? $row['estado'];
    $row['resultado_label'] = $row['resultado'] !== null
        ? (AWS_MSG_RESULTADO_LABEL[$row['resultado']] ?? $row['resultado'])
        : null;

    jsonOk($row);
}
