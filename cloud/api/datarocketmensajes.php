<?php
// api/datarocketmensajes.php
// ABM de mensajes Datarocket. Lee/escribe sobre la tabla `datarocketmensajes`
// definida en db/schema.sql.
//   GET    api/datarocketmensajes.php          -> listado con filtros (query string)
//   GET    api/datarocketmensajes.php?id=N     -> registro individual
//   POST   api/datarocketmensajes.php          -> alta (JSON body)
//   PUT    api/datarocketmensajes.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketmensajes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';

const DR_MSG_COLS = "id, uuid, fecha, campana, plantilla, suscripcion, contacto,
                     proyecto, medio, servicio, canal, remitente, remite,
                     destinatario, destino, prioridad, asunto, cuerpo, formato,
                     media, estado, resultado, error, transmitido, enviado, demora";

header('Content-Type: application/json; charset=utf-8');

try {
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
    $medio    = trim((string)($q['medio']    ?? ''));
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $canal    = isset($q['canal'])    && $q['canal']    !== '' ? (int)$q['canal']    : null;
    $campana  = isset($q['campana'])  && $q['campana']  !== '' ? (int)$q['campana']  : null;
    $contacto = isset($q['contacto']) && $q['contacto'] !== '' ? (int)$q['contacto'] : null;
    $estado    = trim((string)($q['estado']    ?? ''));
    $resultado = trim((string)($q['resultado'] ?? ''));
    $desde     = trim((string)($q['desde']     ?? ''));
    $hasta     = trim((string)($q['hasta']     ?? ''));
    $search    = trim((string)($q['q']         ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'medio', 'proyecto', 'canal', 'campana',
                     'destinatario', 'destino', 'asunto', 'estado', 'resultado',
                     'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'id = :codigo';               $params[':codigo']    = $codigo; }
    if ($medio     !== '')   { $where[] = 'medio = :medio';             $params[':medio']     = $medio; }
    if ($proyecto  !== null) { $where[] = 'proyecto = :proyecto';       $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'canal = :canal';             $params[':canal']     = $canal; }
    if ($campana   !== null) { $where[] = 'campana = :campana';         $params[':campana']   = $campana; }
    if ($contacto  !== null) { $where[] = 'contacto = :contacto';       $params[':contacto']  = $contacto; }
    if ($estado    !== '')   { $where[] = 'estado = :estado';           $params[':estado']    = $estado; }
    if ($resultado !== '')   { $where[] = 'resultado = :resultado';     $params[':resultado'] = $resultado; }
    if ($desde     !== '')   { $where[] = 'fecha >= :desde';            $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'fecha <= :hasta';            $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(destinatario LIKE :s OR destino LIKE :s OR asunto LIKE :s
                     OR remitente LIKE :s OR remite LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                AS total,
            SUM(CASE WHEN enviado IS NOT NULL THEN 1 ELSE 0 END)    AS enviados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM datarocketmensajes
    ")->fetch();

    $sql = "
        SELECT " . DR_MSG_COLS . "
        FROM datarocketmensajes
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
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
    $stmt = $pdo->prepare("SELECT " . DR_MSG_COLS . " FROM datarocketmensajes WHERE id = :id");
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
    // Normaliza 'YYYY-MM-DDTHH:MM' (input datetime-local) a 'YYYY-MM-DD HH:MM:SS'.
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function sanitizePayload(array $in): array {
    return [
        'fecha'        => nullableDateTime($in['fecha']        ?? null),
        'campana'      => nullableInt($in['campana']           ?? null),
        'plantilla'    => nullableInt($in['plantilla']         ?? null),
        'suscripcion'  => nullableInt($in['suscripcion']       ?? null),
        'contacto'     => nullableInt($in['contacto']          ?? null),
        'proyecto'     => nullableInt($in['proyecto']          ?? null),
        'medio'        => nullableStr($in['medio']             ?? null, 1),
        'servicio'     => nullableInt($in['servicio']          ?? null),
        'canal'        => nullableInt($in['canal']             ?? null),
        'remitente'    => nullableStr($in['remitente']         ?? null, 255),
        'remite'       => nullableStr($in['remite']            ?? null, 255),
        'destinatario' => nullableStr($in['destinatario']      ?? null, 255),
        'destino'      => nullableStr($in['destino']           ?? null, 255),
        'prioridad'    => nullableStr($in['prioridad']         ?? null, 1),
        'asunto'       => nullableStr($in['asunto']            ?? null, 500),
        'cuerpo'       => nullableStr($in['cuerpo']            ?? null),
        'formato'      => nullableStr($in['formato']           ?? null, 1),
        'media'        => nullableStr($in['media']             ?? null, 1000),
        'estado'       => nullableStr($in['estado']            ?? null, 1),
        'resultado'    => nullableStr($in['resultado']         ?? null, 1),
        'error'        => nullableStr($in['error']             ?? null, 1000),
        'transmitido'  => nullableDateTime($in['transmitido']  ?? null),
        'enviado'      => nullableDateTime($in['enviado']      ?? null),
        'demora'       => nullableInt($in['demora']            ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $p['uuid'] = $in['uuid'] ?? bin2hex(random_bytes(16));
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }

    $sql = "
        INSERT INTO datarocketmensajes
            (uuid, fecha, campana, plantilla, suscripcion, contacto, proyecto,
             medio, servicio, canal, remitente, remite, destinatario, destino,
             prioridad, asunto, cuerpo, formato, media, estado, resultado, error,
             transmitido, enviado, demora)
        VALUES
            (:uuid, :fecha, :campana, :plantilla, :suscripcion, :contacto, :proyecto,
             :medio, :servicio, :canal, :remitente, :remite, :destinatario, :destino,
             :prioridad, :asunto, :cuerpo, :formato, :media, :estado, :resultado, :error,
             :transmitido, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'         => $p['uuid'],
        ':fecha'        => $p['fecha'],
        ':campana'      => $p['campana'],
        ':plantilla'    => $p['plantilla'],
        ':suscripcion'  => $p['suscripcion'],
        ':contacto'     => $p['contacto'],
        ':proyecto'     => $p['proyecto'],
        ':medio'        => $p['medio'],
        ':servicio'     => $p['servicio'],
        ':canal'        => $p['canal'],
        ':remitente'    => $p['remitente'],
        ':remite'       => $p['remite'],
        ':destinatario' => $p['destinatario'],
        ':destino'      => $p['destino'],
        ':prioridad'    => $p['prioridad'],
        ':asunto'       => $p['asunto'],
        ':cuerpo'       => $p['cuerpo'],
        ':formato'      => $p['formato'],
        ':media'        => $p['media'],
        ':estado'       => $p['estado'],
        ':resultado'    => $p['resultado'],
        ':error'        => $p['error'],
        ':transmitido'  => $p['transmitido'],
        ':enviado'      => $p['enviado'],
        ':demora'       => $p['demora'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocketmensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Mensaje no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE datarocketmensajes SET
            fecha        = :fecha,
            campana      = :campana,
            plantilla    = :plantilla,
            suscripcion  = :suscripcion,
            contacto     = :contacto,
            proyecto     = :proyecto,
            medio        = :medio,
            servicio     = :servicio,
            canal        = :canal,
            remitente    = :remitente,
            remite       = :remite,
            destinatario = :destinatario,
            destino      = :destino,
            prioridad    = :prioridad,
            asunto       = :asunto,
            cuerpo       = :cuerpo,
            formato      = :formato,
            media        = :media,
            estado       = :estado,
            resultado    = :resultado,
            error        = :error,
            transmitido  = :transmitido,
            enviado      = :enviado,
            demora       = :demora
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
        ':campana'      => $p['campana'],
        ':plantilla'    => $p['plantilla'],
        ':suscripcion'  => $p['suscripcion'],
        ':contacto'     => $p['contacto'],
        ':proyecto'     => $p['proyecto'],
        ':medio'        => $p['medio'],
        ':servicio'     => $p['servicio'],
        ':canal'        => $p['canal'],
        ':remitente'    => $p['remitente'],
        ':remite'       => $p['remite'],
        ':destinatario' => $p['destinatario'],
        ':destino'      => $p['destino'],
        ':prioridad'    => $p['prioridad'],
        ':asunto'       => $p['asunto'],
        ':cuerpo'       => $p['cuerpo'],
        ':formato'      => $p['formato'],
        ':media'        => $p['media'],
        ':estado'       => $p['estado'],
        ':resultado'    => $p['resultado'],
        ':error'        => $p['error'],
        ':transmitido'  => $p['transmitido'],
        ':enviado'      => $p['enviado'],
        ':demora'       => $p['demora'],
        ':id'           => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocketmensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
