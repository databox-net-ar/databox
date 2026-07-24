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
const AWS_MSG_COLS = "m.id, m.fecha, m.proyecto, m.canal, m.plantilla,
                      m.remitente, m.remite, m.destinatario, m.destino,
                      m.prioridad, m.asunto, m.cuerpo, m.formato,
                      m.adjunto, m.tags, m.estado, m.error,
                      m.encolado, m.programado, m.enviado, m.demora,
                      p.nombre AS proyecto_nombre,
                      c.nombre AS canal_nombre,
                      t.nombre AS plantilla_nombre";

const AWS_MSG_JOINS = "LEFT JOIN proyectos             p ON p.id = m.proyecto
                       LEFT JOIN aws_canales           c ON c.id = m.canal
                       LEFT JOIN datarocket_plantillas t ON t.id = m.plantilla";

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
    $codigo   = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $canal    = isset($q['canal'])    && $q['canal']    !== '' ? (int)$q['canal']    : null;
    $plantilla = isset($q['plantilla']) && $q['plantilla'] !== '' ? (int)$q['plantilla'] : null;
    $estado    = trim((string)($q['estado']    ?? ''));
    $desde     = trim((string)($q['desde']     ?? ''));
    $hasta     = trim((string)($q['hasta']     ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'proyecto', 'canal', 'plantilla',
                     'destinatario', 'destino', 'asunto', 'estado', 'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'm.id = :codigo';             $params[':codigo']    = $codigo; }
    if ($proyecto  !== null) { $where[] = 'm.proyecto = :proyecto';     $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'm.canal = :canal';           $params[':canal']     = $canal; }
    if ($plantilla !== null) { $where[] = 'm.plantilla = :plantilla';   $params[':plantilla'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'm.estado = :estado';         $params[':estado']    = $estado; }
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

function sanitizePayload(array $in): array {
    return [
        'fecha'        => nullableDateTime($in['fecha']        ?? null),
        'proyecto'     => nullableInt($in['proyecto']          ?? null),
        'canal'        => nullableInt($in['canal']             ?? null),
        'plantilla'    => nullableInt($in['plantilla']         ?? null),
        'remitente'    => nullableStr($in['remitente']         ?? null, 255),
        'remite'       => nullableStr($in['remite']            ?? null, 255),
        'destinatario' => nullableStr($in['destinatario']      ?? null, 255),
        'destino'      => nullableStr($in['destino']           ?? null, 255),
        'prioridad'    => nullableStr($in['prioridad']         ?? null, 1),
        'asunto'       => nullableStr($in['asunto']            ?? null, 255),
        'cuerpo'       => nullableStr($in['cuerpo']            ?? null),
        'formato'      => nullableStr($in['formato']           ?? null, 1),
        'adjunto'      => nullableStr($in['adjunto']           ?? null, 500),
        'tags'         => nullableStr($in['tags']              ?? null, 255),
        'estado'       => nullableStr($in['estado']            ?? null, 20),
        'error'        => nullableStr($in['error']             ?? null, 1000),
        'encolado'     => nullableDateTime($in['encolado']     ?? null),
        'programado'   => nullableDateTime($in['programado']   ?? null),
        'enviado'      => nullableDateTime($in['enviado']      ?? null),
        'demora'       => nullableInt($in['demora']            ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO aws_mensajes
            (fecha, proyecto, canal, plantilla, remitente, remite, destinatario,
             destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:fecha, :proyecto, :canal, :plantilla, :remitente, :remite, :destinatario,
             :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
        ':proyecto'     => $p['proyecto'],
        ':canal'        => $p['canal'],
        ':plantilla'    => $p['plantilla'],
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

    $p = sanitizePayload($in);

    $sql = "
        UPDATE aws_mensajes SET
            fecha        = :fecha,
            proyecto     = :proyecto,
            canal        = :canal,
            plantilla    = :plantilla,
            remitente    = :remitente,
            remite       = :remite,
            destinatario = :destinatario,
            destino      = :destino,
            prioridad    = :prioridad,
            asunto       = :asunto,
            cuerpo       = :cuerpo,
            formato      = :formato,
            adjunto      = :adjunto,
            tags         = :tags,
            estado       = :estado,
            error        = :error,
            encolado     = :encolado,
            programado   = :programado,
            enviado      = :enviado,
            demora       = :demora
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
        ':proyecto'     => $p['proyecto'],
        ':canal'        => $p['canal'],
        ':plantilla'    => $p['plantilla'],
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
        ':id'           => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
