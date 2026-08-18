<?php
// api/telegrammensajes.php
// ABM de mensajes de Telegram. Lee/escribe sobre la tabla `telegram_mensajes`
// definida en db/schema.sql. Envio ASINCRONO estilo Evolution: el POST solo
// encola con estado='pendiente', y el cron worker
// cloud/jobs/telegram_mensajes_enviar.php despacha por lotes contra el
// endpoint MTProto /v4/telegram/mensajes.
//
//   GET    api/telegrammensajes.php             -> listado con filtros (query string)
//   GET    api/telegrammensajes.php?id=N        -> registro individual
//   GET    api/telegrammensajes.php?lookups=1   -> catalogos para el form (proyectos/canales/plantillas)
//   POST   api/telegrammensajes.php             -> alta (encola con estado='pendiente')
//   DELETE api/telegrammensajes.php?id=N        -> baja del historial
//
// NO hay PUT (mensajes en cola/enviados no se editan; para cambiar algo
// se anula y se encola de nuevo).
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/telegram_mensajes.php';

const TG_MSG_COLS = "id, uuid, fecha, proyecto_id, canal_id, plantilla_id, prospecto_id,
                     remitente, remite, destinatario, destino, prioridad,
                     asunto, cuerpo, formato, adjunto, tags, estado, error,
                     encolado, programado, enviado, demora";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.telegram.mensajes');
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
                     'destino', 'asunto', 'estado', 'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'tm.id = :codigo';               $params[':codigo']    = $codigo; }
    if ($proyecto  !== null) { $where[] = 'tm.proyecto_id = :proyecto';    $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'tm.canal_id = :canal';          $params[':canal']     = $canal; }
    if ($plantilla !== null) { $where[] = 'tm.plantilla_id = :plantilla';  $params[':plantilla'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'tm.estado = :estado';           $params[':estado']    = $estado; }
    if ($desde     !== '')   { $where[] = 'tm.fecha >= :desde';            $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'tm.fecha <= :hasta';            $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(tm.destinatario LIKE :s1 OR tm.destino LIKE :s2 OR tm.asunto LIKE :s3
                     OR tm.remitente LIKE :s4 OR tm.remite LIKE :s5 OR tm.tags LIKE :s6)';
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
            COUNT(*)                                                    AS total,
            SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END)       AS pendientes,
            SUM(CASE WHEN estado = 'enviando' THEN 1 ELSE 0 END)        AS enviando,
            SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END)         AS enviados,
            SUM(CASE WHEN estado = 'anulado' THEN 1 ELSE 0 END)         AS anulados,
            SUM(CASE WHEN estado = 'error' THEN 1 ELSE 0 END)           AS con_error
        FROM telegram_mensajes
    ")->fetch();

    // Prefijamos las columnas base con `tm.` para poder LEFT JOIN a
    // telegram_canales y exponer canal_nombre en el listado (la tabla del
    // ABM lo usa en la columna Canal).
    $cols = preg_replace('/\s+/', ' ', TG_MSG_COLS);
    $qualified = implode(', ', array_map(
        fn($c) => 'tm.' . trim($c),
        explode(',', $cols)
    ));
    $sql = "
        SELECT {$qualified}, tc.nombre AS canal_nombre, tc.telefono AS canal_telefono, tc.slug AS canal_slug
        FROM telegram_mensajes tm
        LEFT JOIN telegram_canales tc ON tc.id = tm.canal_id
        {$sqlWhere}
        ORDER BY tm.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'      => (int)($stats['total']      ?? 0),
            'pendientes' => (int)($stats['pendientes'] ?? 0),
            'enviando'   => (int)($stats['enviando']   ?? 0),
            'enviados'   => (int)($stats['enviados']   ?? 0),
            'anulados'   => (int)($stats['anulados']   ?? 0),
            'con_error'  => (int)($stats['con_error']  ?? 0),
            // Estado actual del motor -- lo consume el UI para pintar la
            // tarjeta "Motor" y el menu "Iniciar motor" / "Detener motor"
            // de la toolbar. Mismo shape que Evolution (stats.motor).
            'motor'      => getParametro('telegram.mensajes.enviar', '1'),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    // JOINs a las tablas maestras para exponer los nombres humanos que el
    // modal Consultar muestra en las tarjetas Proyecto / Canal / Plantilla /
    // Prospecto. LEFT JOIN: si la FK apunta a un id inexistente devolvemos
    // NULL en el *_nombre y el frontend cae al "Sin dato" habitual.
    $cols = preg_replace('/\s+/', ' ', TG_MSG_COLS);
    $qualified = implode(', ', array_map(
        fn($c) => 'tm.' . trim($c),
        explode(',', $cols)
    ));
    $stmt = $pdo->prepare("
        SELECT {$qualified},
               pr.nombre    AS proyecto_nombre,
               tc.nombre    AS canal_nombre,
               tc.telefono  AS canal_telefono,
               tc.slug      AS canal_slug,
               dp.nombre    AS plantilla_nombre,
               dpr.nombre   AS prospecto_nombre,
               dpr.celular  AS prospecto_celular
        FROM telegram_mensajes tm
        LEFT JOIN proyectos             pr ON pr.id = tm.proyecto_id
        LEFT JOIN telegram_canales      tc ON tc.id = tm.canal_id
        LEFT JOIN datarocket_plantillas dp ON dp.id = tm.plantilla_id
        LEFT JOIN datarocket_prospectos dpr ON dpr.id = tm.prospecto_id
        WHERE tm.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Lookups: alimenta los selects del form (Proyecto / Canal / Plantilla).
// ----------------------------------------------------------------------------

function handleLookups(PDO $pdo): void {
    // Proyectos: solo los "internos" (tipo='I') -- decision de producto,
    // consistente con evolutionmensajes.
    $proyectos = $pdo->query("
        SELECT id, nombre FROM proyectos
        WHERE tipo = 'I' ORDER BY nombre
    ")->fetchAll();

    // Canales: cuentas USUARIO MTProto habilitadas. Exponemos telefono para
    // que el UI pueda mostrar "Databox (+541163219578)" en el dropdown.
    $canales = $pdo->query("
        SELECT id, slug, nombre, telefono
          FROM telegram_canales
         WHERE (habilitado = '1' OR habilitado IS NULL)
           AND slug IS NOT NULL AND slug <> ''
         ORDER BY nombre
    ")->fetchAll();

    // Plantillas: catalogo compartido con evolution/aws. No filtramos por
    // `medio`; el catalogo aun no tiene una convencion para Telegram.
    $plantillas = $pdo->query("
        SELECT id, nombre, proyecto_id,
               remitente, remite, asunto, cuerpo, formato, adjunto
          FROM datarocket_plantillas
         ORDER BY nombre
    ")->fetchAll();

    $mapNombre = fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?? '')];
    jsonOk([
        'proyectos'  => array_map($mapNombre, $proyectos),
        'canales'    => array_map(
            fn($r) => [
                'id'       => (int)$r['id'],
                'slug'     => (string)($r['slug'] ?? ''),
                'nombre'   => (string)($r['nombre'] ?? ''),
                'telefono' => $r['telefono'] !== null ? (string)$r['telefono'] : null,
            ],
            $canales
        ),
        'plantillas' => array_map(
            fn($r) => [
                'id'          => (int)$r['id'],
                'nombre'      => (string)($r['nombre'] ?? ''),
                'proyecto_id' => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
                'remitente'   => $r['remitente'] !== null ? (string)$r['remitente'] : null,
                'remite'      => $r['remite']    !== null ? (string)$r['remite']    : null,
                'asunto'      => $r['asunto']    !== null ? (string)$r['asunto']    : null,
                'cuerpo'      => $r['cuerpo']    !== null ? (string)$r['cuerpo']    : null,
                'formato'     => $r['formato']   !== null ? (string)$r['formato']   : null,
                'adjunto'     => $r['adjunto']   !== null ? (string)$r['adjunto']   : null,
            ],
            $plantillas
        ),
    ]);
}

// ----------------------------------------------------------------------------
// Alta (encola con estado='pendiente') / Baja
// ----------------------------------------------------------------------------
//
// La logica de encolar (sanitizacion, validacion, INSERT, wake motor)
// vive en cloud/api/lib/telegram_mensajes.php::encolarTelegramMensaje().
// El endpoint solo mapea la respuesta HTTP y traduce las excepciones.

function handleCreate(PDO $pdo, array $in): void {
    try {
        $id = encolarTelegramMensaje($pdo, $in);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
        return;
    }
    // Releemos las columnas system-managed (fecha/encolado/programado que
    // el encolador puede haber defaulteado a NOW) para que la respuesta
    // sea fiel a lo persistido.
    $st = $pdo->prepare("SELECT id, estado, fecha, encolado, programado
                           FROM telegram_mensajes WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch() ?: ['id' => $id];
    jsonOk($r, 201);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM telegram_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
