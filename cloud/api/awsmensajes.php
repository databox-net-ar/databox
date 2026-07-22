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

const AWS_MSG_COLS = "id, fecha, proyecto, canal, plantilla, remitente, remite,
                      destinatario, destino, prioridad, asunto, cuerpo, variables,
                      codificado, formato, adjunto, parametros, tags, estado, error,
                      encolado, enviado, demora";

// Para el listado: mismo SET de columnas pero prefijadas con `m.` (alias),
// mas el `nombre` del canal traido por LEFT JOIN a `aws_canales`.
const AWS_MSG_COLS_LIST = "m.id, m.fecha, m.proyecto, m.canal, m.plantilla,
                           m.remitente, m.remite, m.destinatario, m.destino,
                           m.prioridad, m.asunto, m.cuerpo, m.variables,
                           m.codificado, m.formato, m.adjunto, m.parametros,
                           m.tags, m.estado, m.error, m.encolado, m.enviado,
                           m.demora, c.nombre AS canal_nombre";

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
        $where[] = '(m.destinatario LIKE :s OR m.destino LIKE :s OR m.asunto LIKE :s
                     OR m.remitente LIKE :s OR m.remite LIKE :s OR m.tags LIKE :s)';
        $params[':s'] = "%{$search}%";
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
        SELECT " . AWS_MSG_COLS_LIST . "
        FROM aws_mensajes m
        LEFT JOIN aws_canales c ON c.id = m.canal
        {$sqlWhere}
        ORDER BY m.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r = decodePayloadRow($r);
    }
    unset($r);

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
    $stmt = $pdo->prepare("SELECT " . AWS_MSG_COLS . " FROM aws_mensajes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);
    jsonOk(decodePayloadRow($row));
}

// Cuando `codificado`='1', asunto/cuerpo/variables se guardan en base64 para no
// tener que escapar el HTML/comillas del cuerpo. Los devolvemos decodificados
// para que el consumidor los reciba en texto plano. Si una fila trae base64
// mal formado dejamos el valor original — no queremos que un registro roto
// tumbe todo el listado.
function decodePayloadRow(array $row): array {
    if (($row['codificado'] ?? null) !== '1') return $row;
    foreach (['asunto', 'cuerpo', 'variables'] as $campo) {
        if (!isset($row[$campo]) || $row[$campo] === '') continue;
        $decoded = base64_decode((string)$row[$campo], true);
        if ($decoded !== false) $row[$campo] = $decoded;
    }
    return $row;
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
        'variables'    => nullableStr($in['variables']         ?? null),
        'codificado'   => nullableStr($in['codificado']        ?? null, 1),
        'formato'      => nullableStr($in['formato']           ?? null, 1),
        'adjunto'      => nullableStr($in['adjunto']           ?? null, 500),
        'parametros'   => nullableStr($in['parametros']        ?? null),
        'tags'         => nullableStr($in['tags']              ?? null, 255),
        'estado'       => nullableStr($in['estado']            ?? null, 1),
        'error'        => nullableStr($in['error']             ?? null, 1000),
        'encolado'     => nullableDateTime($in['encolado']     ?? null),
        'enviado'      => nullableDateTime($in['enviado']      ?? null),
        'demora'       => nullableInt($in['demora']            ?? null),
    ];
}

// Simétrico de decodePayloadRow: si el payload trae codificado='1', el cliente
// nos manda texto plano (posiblemente HTML en `cuerpo`) y lo guardamos en base64.
function encodePayloadIfNeeded(array $p): array {
    if (($p['codificado'] ?? null) !== '1') return $p;
    foreach (['asunto', 'cuerpo', 'variables'] as $campo) {
        if ($p[$campo] !== null && $p[$campo] !== '') {
            $p[$campo] = base64_encode((string)$p[$campo]);
        }
    }
    return $p;
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    $p = encodePayloadIfNeeded($p);

    $sql = "
        INSERT INTO aws_mensajes
            (fecha, proyecto, canal, plantilla, remitente, remite, destinatario,
             destino, prioridad, asunto, cuerpo, variables, codificado, formato,
             adjunto, parametros, tags, estado, error, encolado, enviado, demora)
        VALUES
            (:fecha, :proyecto, :canal, :plantilla, :remitente, :remite, :destinatario,
             :destino, :prioridad, :asunto, :cuerpo, :variables, :codificado, :formato,
             :adjunto, :parametros, :tags, :estado, :error, :encolado, :enviado, :demora)
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
        ':variables'    => $p['variables'],
        ':codificado'   => $p['codificado'],
        ':formato'      => $p['formato'],
        ':adjunto'      => $p['adjunto'],
        ':parametros'   => $p['parametros'],
        ':tags'         => $p['tags'],
        ':estado'       => $p['estado'],
        ':error'        => $p['error'],
        ':encolado'     => $p['encolado'],
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
    $p = encodePayloadIfNeeded($p);

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
            variables    = :variables,
            codificado   = :codificado,
            formato      = :formato,
            adjunto      = :adjunto,
            parametros   = :parametros,
            tags         = :tags,
            estado       = :estado,
            error        = :error,
            encolado     = :encolado,
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
        ':variables'    => $p['variables'],
        ':codificado'   => $p['codificado'],
        ':formato'      => $p['formato'],
        ':adjunto'      => $p['adjunto'],
        ':parametros'   => $p['parametros'],
        ':tags'         => $p['tags'],
        ':estado'       => $p['estado'],
        ':error'        => $p['error'],
        ':encolado'     => $p['encolado'],
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
