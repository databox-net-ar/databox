<?php
// api/awsmensajes.php
// ABM de mensajes AWS. Lee/escribe sobre la tabla `aws_mensajes`
// definida en db/schema.sql.
//   GET    api/awsmensajes.php          -> listado con filtros (query string)
//   GET    api/awsmensajes.php?id=N     -> registro individual
//   POST   api/awsmensajes.php          -> alta (JSON body)
//   PUT    api/awsmensajes.php?id=N     -> modificacion (JSON body)
//   DELETE api/awsmensajes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// SET de columnas de `aws_mensajes` (alias `m.`) + nombres traidos por LEFT
// JOIN a proyectos / aws_canales / datarocket_plantillas. Reusado por listado
// y consulta individual — el modal Consultar los muestra en la pestana
// Detalles.
const AWS_MSG_COLS = "m.id, m.fecha, m.proyecto_id, m.canal_id, m.plantilla_id,
                      m.remitente, m.remite, m.destinatario, m.destino,
                      m.prioridad, m.asunto, m.cuerpo, m.formato,
                      m.adjunto, m.tags, m.estado, m.error,
                      m.encolado, m.programado, m.enviado, m.demora,
                      p.nombre AS proyecto_nombre,
                      c.nombre AS canal_nombre,
                      t.nombre AS plantilla_nombre";

const AWS_MSG_JOINS = "LEFT JOIN proyectos             p ON p.id = m.proyecto_id
                       LEFT JOIN aws_canales           c ON c.id = m.canal_id
                       LEFT JOIN datarocket_plantillas t ON t.id = m.plantilla_id";

// Sanitizadores por columna. Debe declararse antes del bloque `try` porque
// las constantes top-level en PHP se procesan en orden de aparicion (no se
// hoistean como las funciones); si esto queda debajo del dispatcher,
// handleCreate/handleUpdate revientan con "Undefined constant".
// handleCreate llena con NULL los campos ausentes (comportamiento historico,
// con fallback especial de NOW() en zona AR para `fecha`), handleUpdate solo
// toca los presentes para no pisar columnas system-managed.
const AWS_MSG_SANITIZERS = [
    'fecha'        => 'dt',
    'proyecto_id'  => 'int',
    'canal_id'     => 'int',
    'plantilla_id' => 'int',
    'remitente'    => 'str:255',
    'remite'       => 'str:255',
    'destinatario' => 'str:255',
    'destino'      => 'str:255',
    'prioridad'    => 'int',
    'asunto'       => 'str:255',
    'cuerpo'       => 'str',
    'formato'      => 'str:10',
    'adjunto'      => 'str:500',
    'tags'         => 'str:255',
    'estado'       => 'str:20',
    'error'        => 'str:1000',
    'encolado'     => 'dt',
    'programado'   => 'dt',
    'enviado'      => 'dt',
    'demora'       => 'int',
];

// Campos obligatorios al encolar un mensaje nuevo. Coincide con lo que
// exige el sender (`datarocket_mensajes_enviar.php`): sin proyecto/canal
// no puede firmar contra SES, sin remite/destino/asunto/cuerpo no puede
// armar el email. Validado tambien en el front (guardarAwsMsg), este
// chequeo es defensivo por si alguien pega directo al endpoint.
const AWS_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Canal',
    'remite'      => 'Remite',
    'destino'     => 'Destino',
    'asunto'      => 'Asunto',
    'cuerpo'      => 'Cuerpo',
];

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.aws.mensajes');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdate($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDelete($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo    = isset($q['codigo'])       && $q['codigo']       !== '' ? (int)$q['codigo']       : null;
    $proyecto  = isset($q['proyecto_id'])  && $q['proyecto_id']  !== '' ? (int)$q['proyecto_id']  : null;
    $canal     = isset($q['canal_id'])     && $q['canal_id']     !== '' ? (int)$q['canal_id']     : null;
    $plantilla = isset($q['plantilla_id']) && $q['plantilla_id'] !== '' ? (int)$q['plantilla_id'] : null;
    $estado    = trim((string)($q['estado']    ?? ''));
    $desde     = trim((string)($q['desde']     ?? ''));
    $hasta     = trim((string)($q['hasta']     ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'proyecto_id', 'canal_id', 'plantilla_id',
                     'destinatario', 'destino', 'asunto', 'estado', 'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'm.id = :codigo';                     $params[':codigo']       = $codigo; }
    if ($proyecto  !== null) { $where[] = 'm.proyecto_id = :proyecto_id';       $params[':proyecto_id']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'm.canal_id = :canal_id';             $params[':canal_id']     = $canal; }
    if ($plantilla !== null) { $where[] = 'm.plantilla_id = :plantilla_id';     $params[':plantilla_id'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'm.estado = :estado';                 $params[':estado']       = $estado; }
    if ($desde     !== '')   { $where[] = 'm.fecha >= :desde';          $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'm.fecha <= :hasta';          $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(m.destinatario LIKE :s1 OR m.destino LIKE :s2 OR m.asunto LIKE :s3
                     OR m.remitente LIKE :s4 OR m.remite LIKE :s5 OR m.tags LIKE :s6)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
        $params[':s5'] = $like;
        $params[':s6'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total,
            SUM(CASE WHEN enviado IS NOT NULL THEN 1 ELSE 0 END)      AS enviados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM aws_mensajes
    ")->fetch();

    $sql = "
        SELECT " . AWS_MSG_COLS . "
        FROM aws_mensajes m
        " . AWS_MSG_JOINS . "
        {$sqlWhere}
        ORDER BY m.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'     => (int)($stats['total']     ?? 0),
            'enviados'  => (int)($stats['enviados']  ?? 0),
            'con_error' => (int)($stats['con_error'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $sql = "
        SELECT " . AWS_MSG_COLS . "
        FROM aws_mensajes m
        " . AWS_MSG_JOINS . "
        WHERE m.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

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

function nullableDateTime(mixed $v): ?string {
    $s = nullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function applySanitizer(string $rule, mixed $val): mixed {
    if ($rule === 'int') return nullableInt($val);
    if ($rule === 'dt')  return nullableDateTime($val);
    if ($rule === 'str') return nullableStr($val);
    if (str_starts_with($rule, 'str:')) {
        return nullableStr($val, (int)substr($rule, 4));
    }
    throw new RuntimeException("Sanitizer desconocido: {$rule}");
}

// Sanitiza TODAS las columnas (usado por handleCreate — los faltantes quedan
// en NULL, comportamiento historico).
function sanitizePayload(array $in): array {
    $out = [];
    foreach (AWS_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applySanitizer($rule, $in[$col] ?? null);
    }
    return $out;
}

// Sanitiza SOLO las columnas presentes en el payload (usado por handleUpdate
// — asi un ABM que solo manda 13 campos no pisa a NULL las columnas system-
// managed como `estado`, `encolado`, `enviado`, etc).
function sanitizePartialPayload(array $in): array {
    $out = [];
    foreach (AWS_MSG_SANITIZERS as $col => $rule) {
        if (array_key_exists($col, $in)) {
            $out[$col] = applySanitizer($rule, $in[$col]);
        }
    }
    return $out;
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    $faltantes = [];
    foreach (AWS_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
    }
    if ($faltantes) {
        jsonError('Faltan campos obligatorios: ' . implode(', ', $faltantes), 400);
    }

    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    // Al crear via ABM, el mensaje nace ya encolado y pendiente. `encolado`
    // reusa el mismo instante que `fecha` para evitar drift entre ambos.
    if ($p['encolado'] === null) $p['encolado'] = $p['fecha'];
    if ($p['estado']   === null) $p['estado']   = 'pendiente';

    $sql = "
        INSERT INTO aws_mensajes
            (fecha, proyecto_id, canal_id, plantilla_id, remitente, remite, destinatario,
             destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:fecha, :proyecto_id, :canal_id, :plantilla_id, :remitente, :remite, :destinatario,
             :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
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
        ':estado'       => $p['estado'],
        ':error'        => $p['error'],
        ':encolado'     => $p['encolado'],
        ':programado'   => $p['programado'],
        ':enviado'      => $p['enviado'],
        ':demora'       => $p['demora'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM aws_mensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Mensaje no encontrado', 404);

    // Update parcial: solo tocamos las columnas presentes en el payload.
    // Asi el ABM (que solo manda 13 campos editables) no pisa a NULL las
    // columnas system-managed (fecha, estado, encolado, enviado, demora,
    // error, tags) que setea el sender.
    $p = sanitizePartialPayload($in);
    if (!$p) jsonOk(['id' => $id]);   // payload vacio -> no-op

    $sets   = [];
    $params = [':id' => $id];
    foreach ($p as $col => $val) {
        $sets[]           = "`{$col}` = :{$col}";
        $params[":{$col}"] = $val;
    }

    $sql = 'UPDATE aws_mensajes SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
