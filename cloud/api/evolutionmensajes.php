<?php
// api/evolutionmensajes.php
// ABM de mensajes Evolution API. Lee/escribe sobre la tabla `evolutionmensajes`
// definida en db/schema.sql.
//   GET    api/evolutionmensajes.php          -> listado con filtros (query string)
//   GET    api/evolutionmensajes.php?id=N     -> registro individual
//   POST   api/evolutionmensajes.php          -> alta (JSON body)
//   PUT    api/evolutionmensajes.php?id=N     -> modificacion (JSON body)
//   DELETE api/evolutionmensajes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const EVO_MSG_COLS = "id, fecha, proyecto, canal, plantilla, remitente, remite,
                      destinatario, destino, prioridad, asunto, cuerpo, variables,
                      codificado, formato, adjunto, parametros, tags, estado, error,
                      encolado, enviado, demora";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.evolution.mensajes');
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
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $proyecto  = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $canal     = isset($q['canal'])    && $q['canal']    !== '' ? (int)$q['canal']    : null;
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

    if ($codigo    !== null) { $where[] = 'id = :codigo';               $params[':codigo']    = $codigo; }
    if ($proyecto  !== null) { $where[] = 'proyecto = :proyecto';       $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'canal = :canal';             $params[':canal']     = $canal; }
    if ($plantilla !== null) { $where[] = 'plantilla = :plantilla';     $params[':plantilla'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'estado = :estado';           $params[':estado']    = $estado; }
    if ($desde     !== '')   { $where[] = 'fecha >= :desde';            $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'fecha <= :hasta';            $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(destinatario LIKE :s OR destino LIKE :s OR asunto LIKE :s
                     OR remitente LIKE :s OR remite LIKE :s OR tags LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total,
            SUM(CASE WHEN enviado IS NOT NULL THEN 1 ELSE 0 END)      AS enviados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM evolutionmensajes
    ")->fetch();

    $sql = "
        SELECT " . EVO_MSG_COLS . "
        FROM evolutionmensajes
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
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
    $stmt = $pdo->prepare("SELECT " . EVO_MSG_COLS . " FROM evolutionmensajes WHERE id = :id");
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
        INSERT INTO evolutionmensajes
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
    $exists = $pdo->prepare('SELECT id FROM evolutionmensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Mensaje no encontrado', 404);

    $p = sanitizePayload($in);
    $p = encodePayloadIfNeeded($p);

    $sql = "
        UPDATE evolutionmensajes SET
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
    $stmt = $pdo->prepare('DELETE FROM evolutionmensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
