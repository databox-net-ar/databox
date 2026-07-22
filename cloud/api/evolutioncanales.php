<?php
// api/evolutioncanales.php
// ABM de canales Evolution API. Lee/escribe sobre la tabla `evolution_canales`
// definida en db/schema.sql.
//   GET    api/evolutioncanales.php          -> listado con filtros (query string)
//   GET    api/evolutioncanales.php?id=N     -> registro individual
//   POST   api/evolutioncanales.php          -> alta (JSON body)
//   PUT    api/evolutioncanales.php?id=N     -> modificacion (JSON body)
//   DELETE api/evolutioncanales.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const EVO_CH_COLS = "id, uuid, proyecto, nombre, prefijo, numero, celular, token,
                     prompt, intervaloCorto, intervaloLargo, ultimo, alerta, limite,
                     enviados, acumulados, webhook, online, habilitado,
                     canalEstado, gruposEstado, actualizado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.evolution.canales');
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
    $codigo     = isset($q['codigo'])   && $q['codigo']   !== '' ? (int)$q['codigo']   : null;
    $proyecto   = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $habilitado = trim((string)($q['habilitado'] ?? ''));
    $online     = trim((string)($q['online']     ?? ''));
    $search     = trim((string)($q['q']          ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'proyecto', 'numero', 'celular',
                     'enviados', 'acumulados', 'habilitado', 'online'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';               $params[':codigo']     = $codigo; }
    if ($proyecto   !== null) { $where[] = 'proyecto = :proyecto';       $params[':proyecto']   = $proyecto; }
    if ($habilitado !== '')   { $where[] = 'habilitado = :habilitado';   $params[':habilitado'] = $habilitado; }
    if ($online     !== '')   { $where[] = 'online = :online';           $params[':online']     = $online; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s OR numero LIKE :s OR celular LIKE :s
                     OR prefijo LIKE :s OR token LIKE :s OR uuid LIKE :s)';
        $params[':s'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                          AS total,
            SUM(CASE WHEN habilitado = '1' THEN 1 ELSE 0 END) AS habilitados,
            SUM(CASE WHEN online     = '1' THEN 1 ELSE 0 END) AS online
        FROM evolution_canales
    ")->fetch();

    $sql = "
        SELECT " . EVO_CH_COLS . "
        FROM evolution_canales
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
            'habilitados' => (int)($stats['habilitados'] ?? 0),
            'online'      => (int)($stats['online']      ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . EVO_CH_COLS . " FROM evolution_canales WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Canal no encontrado', 404);
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
        'proyecto'       => nullableInt($in['proyecto']       ?? null),
        'nombre'         => nullableStr($in['nombre']         ?? null, 255),
        'prefijo'        => nullableStr($in['prefijo']        ?? null, 10),
        'numero'         => nullableStr($in['numero']         ?? null, 15),
        'celular'        => nullableStr($in['celular']        ?? null, 20),
        'token'          => nullableStr($in['token']          ?? null, 50),
        'prompt'         => nullableStr($in['prompt']         ?? null, 100),
        'intervaloCorto' => nullableInt($in['intervaloCorto'] ?? null),
        'intervaloLargo' => nullableInt($in['intervaloLargo'] ?? null),
        'ultimo'         => nullableInt($in['ultimo']         ?? null),
        'alerta'         => nullableInt($in['alerta']         ?? null),
        'limite'         => nullableInt($in['limite']         ?? null),
        'enviados'       => nullableInt($in['enviados']       ?? null),
        'acumulados'     => nullableInt($in['acumulados']     ?? null),
        'webhook'        => nullableStr($in['webhook']        ?? null, 1000),
        'online'         => nullableStr($in['online']         ?? null, 1),
        'habilitado'     => nullableStr($in['habilitado']     ?? null, 1),
        'canalEstado'    => nullableStr($in['canalEstado']    ?? null),
        'gruposEstado'   => nullableStr($in['gruposEstado']   ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    $uuid = nullableStr($in['uuid'] ?? null, 50);
    if ($uuid === null) $uuid = bin2hex(random_bytes(16));

    $sql = "
        INSERT INTO evolution_canales
            (uuid, proyecto, nombre, prefijo, numero, celular, token, prompt,
             intervaloCorto, intervaloLargo, ultimo, alerta, limite, enviados,
             acumulados, webhook, online, habilitado, canalEstado, gruposEstado)
        VALUES
            (:uuid, :proyecto, :nombre, :prefijo, :numero, :celular, :token, :prompt,
             :intervaloCorto, :intervaloLargo, :ultimo, :alerta, :limite, :enviados,
             :acumulados, :webhook, :online, :habilitado, :canalEstado, :gruposEstado)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'           => $uuid,
        ':proyecto'       => $p['proyecto'],
        ':nombre'         => $p['nombre'],
        ':prefijo'        => $p['prefijo'],
        ':numero'         => $p['numero'],
        ':celular'        => $p['celular'],
        ':token'          => $p['token'],
        ':prompt'         => $p['prompt'],
        ':intervaloCorto' => $p['intervaloCorto'],
        ':intervaloLargo' => $p['intervaloLargo'],
        ':ultimo'         => $p['ultimo'],
        ':alerta'         => $p['alerta'],
        ':limite'         => $p['limite'],
        ':enviados'       => $p['enviados'],
        ':acumulados'     => $p['acumulados'],
        ':webhook'        => $p['webhook'],
        ':online'         => $p['online'],
        ':habilitado'     => $p['habilitado'],
        ':canalEstado'    => $p['canalEstado'],
        ':gruposEstado'   => $p['gruposEstado'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM evolution_canales WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Canal no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE evolution_canales SET
            proyecto       = :proyecto,
            nombre         = :nombre,
            prefijo        = :prefijo,
            numero         = :numero,
            celular        = :celular,
            token          = :token,
            prompt         = :prompt,
            intervaloCorto = :intervaloCorto,
            intervaloLargo = :intervaloLargo,
            ultimo         = :ultimo,
            alerta         = :alerta,
            limite         = :limite,
            enviados       = :enviados,
            acumulados     = :acumulados,
            webhook        = :webhook,
            online         = :online,
            habilitado     = :habilitado,
            canalEstado    = :canalEstado,
            gruposEstado   = :gruposEstado
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':proyecto'       => $p['proyecto'],
        ':nombre'         => $p['nombre'],
        ':prefijo'        => $p['prefijo'],
        ':numero'         => $p['numero'],
        ':celular'        => $p['celular'],
        ':token'          => $p['token'],
        ':prompt'         => $p['prompt'],
        ':intervaloCorto' => $p['intervaloCorto'],
        ':intervaloLargo' => $p['intervaloLargo'],
        ':ultimo'         => $p['ultimo'],
        ':alerta'         => $p['alerta'],
        ':limite'         => $p['limite'],
        ':enviados'       => $p['enviados'],
        ':acumulados'     => $p['acumulados'],
        ':webhook'        => $p['webhook'],
        ':online'         => $p['online'],
        ':habilitado'     => $p['habilitado'],
        ':canalEstado'    => $p['canalEstado'],
        ':gruposEstado'   => $p['gruposEstado'],
        ':id'             => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM evolution_canales WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Canal no encontrado', 404);
    jsonOk(['id' => $id]);
}
