<?php
// api/evolutionmensajes.php
// ABM de mensajes Evolution API. Lee/escribe sobre la tabla `evolution_mensajes`
// definida en db/schema.sql.
//   GET    api/evolutionmensajes.php          -> listado con filtros (query string)
//   GET    api/evolutionmensajes.php?id=N     -> registro individual
//   POST   api/evolutionmensajes.php          -> alta (JSON body)
//   PUT    api/evolutionmensajes.php?id=N     -> modificacion (JSON body)
//   DELETE api/evolutionmensajes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const EVO_MSG_COLS = "id, fecha, proyecto_id, canal_id, plantilla_id, remitente, remite,
                      destinatario, destino, prioridad, asunto, cuerpo,
                      formato, adjunto, tags, estado, error,
                      encolado, programado, enviado, demora";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.evolution.mensajes');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && isset($_GET['lookups'])) {
        handleLookups($pdo);
    } elseif ($method === 'GET' && $id > 0) {
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
    // Los filtros aceptan tanto `proyecto_id` (nombre nuevo, alineado con la
    // columna) como el alias corto `proyecto` — asi los bookmarks / integraciones
    // viejas siguen funcionando durante la transicion.
    $codigo    = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $proyecto  = isset($q['proyecto_id'])  && $q['proyecto_id']  !== '' ? (int)$q['proyecto_id']
                : (isset($q['proyecto'])  && $q['proyecto']  !== '' ? (int)$q['proyecto']  : null);
    $canal     = isset($q['canal_id'])     && $q['canal_id']     !== '' ? (int)$q['canal_id']
                : (isset($q['canal'])     && $q['canal']     !== '' ? (int)$q['canal']     : null);
    $plantilla = isset($q['plantilla_id']) && $q['plantilla_id'] !== '' ? (int)$q['plantilla_id']
                : (isset($q['plantilla']) && $q['plantilla'] !== '' ? (int)$q['plantilla'] : null);
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

    if ($codigo    !== null) { $where[] = 'id = :codigo';                       $params[':codigo']    = $codigo; }
    if ($proyecto  !== null) { $where[] = 'proyecto_id = :proyecto';            $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'canal_id = :canal';                  $params[':canal']     = $canal; }
    if ($plantilla !== null) { $where[] = 'plantilla_id = :plantilla';          $params[':plantilla'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'estado = :estado';                   $params[':estado']    = $estado; }
    if ($desde     !== '')   { $where[] = 'fecha >= :desde';            $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'fecha <= :hasta';            $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(destinatario LIKE :s1 OR destino LIKE :s2 OR asunto LIKE :s3
                     OR remitente LIKE :s4 OR remite LIKE :s5 OR tags LIKE :s6)';
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
        FROM evolution_mensajes
    ")->fetch();

    $sql = "
        SELECT " . EVO_MSG_COLS . "
        FROM evolution_mensajes
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
    // JOINs a las 3 tablas maestras para exponer los nombres humanos que el
    // modal Consultar muestra en las tarjetas Proyecto / Canal / Plantilla.
    // LEFT JOIN: si la FK apunta a un id inexistente devolvemos NULL en el
    // *_nombre y el frontend cae al "Sin dato" habitual.
    $cols = preg_replace('/\s+/', ' ', EVO_MSG_COLS);
    $qualified = implode(', ', array_map(
        fn($c) => 'em.' . trim($c),
        explode(',', $cols)
    ));
    $stmt = $pdo->prepare("
        SELECT {$qualified},
               pr.nombre AS proyecto_nombre,
               ec.nombre AS canal_nombre,
               dp.nombre AS plantilla_nombre
        FROM evolution_mensajes em
        LEFT JOIN proyectos             pr ON pr.id = em.proyecto_id
        LEFT JOIN evolution_canales     ec ON ec.id = em.canal_id
        LEFT JOIN datarocket_plantillas dp ON dp.id = em.plantilla_id
        WHERE em.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Lookups: alimenta los selects del form (Proyecto / Plantilla / Canal).
// ----------------------------------------------------------------------------

function handleLookups(PDO $pdo): void {
    // Proyectos: solo los "internos" (tipo='I') — decision de producto.
    $proyectos = $pdo->query("
        SELECT id, nombre FROM proyectos
        WHERE tipo = 'I' ORDER BY nombre
    ")->fetchAll();
    // Plantillas incluyen `proyecto_id` para que el frontend pueda cascadear
    // el select: al elegir un proyecto, filtramos las plantillas de ese
    // proyecto. La columna se llama `proyecto_id` desde la migration
    // 20260724_1500 (antes era `proyecto`).
    $plantillas = $pdo->query('SELECT id, nombre, proyecto_id FROM datarocket_plantillas ORDER BY nombre')->fetchAll();
    $canales    = $pdo->query('SELECT id, nombre FROM evolution_canales ORDER BY nombre')->fetchAll();

    $mapNombre = fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?? '')];
    jsonOk([
        'proyectos'  => array_map($mapNombre, $proyectos),
        'plantillas' => array_map(
            fn($r) => [
                'id'          => (int)$r['id'],
                'nombre'      => (string)($r['nombre'] ?? ''),
                'proyecto_id' => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
            ],
            $plantillas
        ),
        'canales'    => array_map($mapNombre, $canales),
    ]);
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
    // Aceptamos el alias corto (`proyecto`, `canal`, `plantilla`) ademas del
    // nombre canonico *_id — safety net para clientes viejos durante la
    // transicion. `?? null` cae al valor nuevo si no vino el alias.
    return [
        'fecha'        => nullableDateTime($in['fecha']        ?? null),
        'proyecto_id'  => nullableInt($in['proyecto_id']       ?? $in['proyecto']  ?? null),
        'canal_id'     => nullableInt($in['canal_id']          ?? $in['canal']     ?? null),
        'plantilla_id' => nullableInt($in['plantilla_id']      ?? $in['plantilla'] ?? null),
        'remitente'    => nullableStr($in['remitente']         ?? null, 255),
        'remite'       => nullableStr($in['remite']            ?? null, 255),
        'destinatario' => nullableStr($in['destinatario']      ?? null, 255),
        'destino'      => nullableStr($in['destino']           ?? null, 255),
        'prioridad'    => nullableInt($in['prioridad']         ?? null),
        'asunto'       => nullableStr($in['asunto']            ?? null, 255),
        'cuerpo'       => nullableStr($in['cuerpo']            ?? null),
        'formato'      => nullableStr($in['formato']           ?? null, 20),
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

// Chequea los 5 campos obligatorios (proyecto_id, canal_id, remite, destino,
// cuerpo). Se aplica tanto en Alta como en Edicion — no queremos que una
// edicion pueda dejar un mensaje sin FK, sin remitente/destino tecnicos ni
// sin contenido; se cortaria el sender worker.
function validarObligatorios(array $p): void {
    $faltantes = [];
    if ($p['proyecto_id'] === null) $faltantes[] = 'Proyecto';
    if ($p['canal_id']    === null) $faltantes[] = 'Canal';
    if ($p['remite']      === null) $faltantes[] = 'Remite';
    if ($p['destino']     === null) $faltantes[] = 'Destino';
    if ($p['cuerpo']      === null) $faltantes[] = 'Cuerpo';
    if ($faltantes) {
        jsonError('Faltan campos obligatorios: ' . implode(', ', $faltantes) . '.', 400);
    }
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    validarObligatorios($p);
    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->format('Y-m-d H:i:s');
    // Defaults en profundidad: el frontend precarga estos valores en Alta pero
    // los aplicamos igual aca por si un cliente externo POSTea sin ellos.
    if ($p['fecha']       === null) $p['fecha']       = $ahora;
    if ($p['encolado']    === null) $p['encolado']    = $ahora;
    if ($p['programado']  === null) $p['programado']  = $ahora;
    if ($p['estado']      === null) $p['estado']      = 'pendiente';
    if ($p['formato']     === null) $p['formato']     = 'texto';
    if ($p['prioridad']   === null) $p['prioridad']   = 3;   // media

    $sql = "
        INSERT INTO evolution_mensajes
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
    $exists = $pdo->prepare('SELECT id FROM evolution_mensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Mensaje no encontrado', 404);

    $p = sanitizePayload($in);
    validarObligatorios($p);

    $sql = "
        UPDATE evolution_mensajes SET
            fecha        = :fecha,
            proyecto_id  = :proyecto_id,
            canal_id     = :canal_id,
            plantilla_id = :plantilla_id,
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
        ':id'           => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM evolution_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
