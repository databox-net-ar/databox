<?php
// api/datarocketplantillas.php
// ABM de plantillas Datarocket. Lee/escribe sobre la tabla `datarocket_plantillas`
// definida en db/schema.sql.
//   GET    api/datarocketplantillas.php          -> listado con filtros (query string)
//   GET    api/datarocketplantillas.php?id=N     -> registro individual
//   POST   api/datarocketplantillas.php          -> alta (JSON body)
//   PUT    api/datarocketplantillas.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketplantillas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_PL_COLS = "id, uuid, nombre, proyecto, medio, remitente, remite,
                    asunto, cuerpo, formato, adjunto";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('sistemas.datarocket.plantillas');
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
    $codigo   = isset($q['codigo'])   && $q['codigo']   !== '' ? (int)$q['codigo']   : null;
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $medio    = trim((string)($q['medio']   ?? ''));
    $formato  = trim((string)($q['formato'] ?? ''));
    $search   = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'proyecto', 'medio', 'remitente',
                     'asunto', 'formato'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 'id = :codigo';         $params[':codigo']   = $codigo; }
    if ($proyecto !== null) { $where[] = 'proyecto = :proyecto'; $params[':proyecto'] = $proyecto; }
    if ($medio    !== '')   { $where[] = 'medio = :medio';       $params[':medio']    = $medio; }
    if ($formato  !== '')   { $where[] = 'formato = :formato';   $params[':formato']  = $formato; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR asunto LIKE :s OR remitente LIKE :s
                     OR remite LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total,
            SUM(CASE WHEN adjunto IS NOT NULL AND adjunto <> '' THEN 1 ELSE 0 END) AS con_adjunto
        FROM datarocket_plantillas
    ")->fetch();

    $sql = "
        SELECT " . DR_PL_COLS . "
        FROM datarocket_plantillas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'con_adjunto' => (int)($stats['con_adjunto'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_PL_COLS . " FROM datarocket_plantillas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Plantilla no encontrada', 404);
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

function sanitizePayload(array $in): array {
    return [
        'nombre'    => nullableStr($in['nombre']    ?? null, 100),
        'proyecto'  => nullableInt($in['proyecto']  ?? null),
        'medio'     => nullableStr($in['medio']     ?? null, 1),
        'remitente' => nullableStr($in['remitente'] ?? null, 255),
        'remite'    => nullableStr($in['remite']    ?? null, 255),
        'asunto'    => nullableStr($in['asunto']    ?? null, 255),
        // `cuerpo` es mediumtext: no cortamos por longitud.
        'cuerpo'    => nullableStr($in['cuerpo']    ?? null),
        'formato'   => nullableStr($in['formato']   ?? null, 1),
        'adjunto'   => nullableStr($in['adjunto']   ?? null, 500),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    // uuid de la tabla vieja es varchar(10) — mantenemos ese ancho.
    $p['uuid'] = nullableStr($in['uuid'] ?? null, 10) ?? substr(bin2hex(random_bytes(5)), 0, 10);

    $sql = "
        INSERT INTO datarocket_plantillas
            (uuid, nombre, proyecto, medio, remitente, remite,
             asunto, cuerpo, formato, adjunto)
        VALUES
            (:uuid, :nombre, :proyecto, :medio, :remitente, :remite,
             :asunto, :cuerpo, :formato, :adjunto)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'      => $p['uuid'],
        ':nombre'    => $p['nombre'],
        ':proyecto'  => $p['proyecto'],
        ':medio'     => $p['medio'],
        ':remitente' => $p['remitente'],
        ':remite'    => $p['remite'],
        ':asunto'    => $p['asunto'],
        ':cuerpo'    => $p['cuerpo'],
        ':formato'   => $p['formato'],
        ':adjunto'   => $p['adjunto'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM datarocket_plantillas WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Plantilla no encontrada', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE datarocket_plantillas SET
            nombre    = :nombre,
            proyecto  = :proyecto,
            medio     = :medio,
            remitente = :remitente,
            remite    = :remite,
            asunto    = :asunto,
            cuerpo    = :cuerpo,
            formato   = :formato,
            adjunto   = :adjunto
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'    => $p['nombre'],
        ':proyecto'  => $p['proyecto'],
        ':medio'     => $p['medio'],
        ':remitente' => $p['remitente'],
        ':remite'    => $p['remite'],
        ':asunto'    => $p['asunto'],
        ':cuerpo'    => $p['cuerpo'],
        ':formato'   => $p['formato'],
        ':adjunto'   => $p['adjunto'],
        ':id'        => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_plantillas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Plantilla no encontrada', 404);
    jsonOk(['id' => $id]);
}
