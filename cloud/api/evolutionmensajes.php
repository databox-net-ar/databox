<?php
// api/evolutionmensajes.php
// ABM de mensajes Evolution API. Lee/escribe sobre la tabla `evolution_mensajes`
// definida en db/schema.sql. Los mensajes son inmutables una vez encolados —
// no hay PUT, solo alta / consulta / eliminacion. Para cancelar un envio
// pendiente sin borrarlo (dejar traza), usar evolutionmensajes_anular.php.
//   GET    api/evolutionmensajes.php          -> listado con filtros (query string)
//   GET    api/evolutionmensajes.php?id=N     -> registro individual
//   POST   api/evolutionmensajes.php          -> alta (JSON body)
//   DELETE api/evolutionmensajes.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/evolution_mensajes.php';

const EVO_MSG_COLS = "id, uuid, fecha, proyecto_id, canal_id, plantilla_id, contacto_id,
                      remitente, remite, destinatario, destino, prioridad,
                      asunto, cuerpo, formato, adjunto, tags, estado, error,
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
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDelete($pdo, $id);
    } else {
        // Los mensajes encolados no se pueden editar (PUT deshabilitado).
        // Solo consultar / crear / anular (via endpoint separado) / eliminar.
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
                     'destino', 'asunto', 'estado', 'enviado', 'demora'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo    !== null) { $where[] = 'em.id = :codigo';               $params[':codigo']    = $codigo; }
    if ($proyecto  !== null) { $where[] = 'em.proyecto_id = :proyecto';    $params[':proyecto']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'em.canal_id = :canal';          $params[':canal']     = $canal; }
    if ($plantilla !== null) { $where[] = 'em.plantilla_id = :plantilla';  $params[':plantilla'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'em.estado = :estado';           $params[':estado']    = $estado; }
    if ($desde     !== '')   { $where[] = 'em.fecha >= :desde';            $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'em.fecha <= :hasta';            $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(em.destinatario LIKE :s1 OR em.destino LIKE :s2 OR em.asunto LIKE :s3
                     OR em.remitente LIKE :s4 OR em.remite LIKE :s5 OR em.tags LIKE :s6)';
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
            SUM(CASE WHEN enviado IS NOT NULL THEN 1 ELSE 0 END)        AS enviados,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM evolution_mensajes
    ")->fetch();

    // Estado del "motor" de envio: bandera runtime en `parametros`
    // (variable='evolution.mensajes.enviar'). '1' = enviando, '0' = esperando.
    // La tarjeta Motor del listado la refleja.
    $motorStmt = $pdo->prepare("SELECT valor FROM parametros WHERE variable = :v LIMIT 1");
    $motorStmt->execute([':v' => 'evolution.mensajes.enviar']);
    $motorValor = $motorStmt->fetchColumn();
    if ($motorValor === false) $motorValor = null;

    // Prefijamos las columnas base con `em.` para poder LEFT JOIN a
    // evolution_canales y exponer canal_nombre en el listado (la tabla del
    // ABM lo usa en la columna Canal).
    $cols = preg_replace('/\s+/', ' ', EVO_MSG_COLS);
    $qualified = implode(', ', array_map(
        fn($c) => 'em.' . trim($c),
        explode(',', $cols)
    ));
    $sql = "
        SELECT {$qualified}, ec.nombre AS canal_nombre
        FROM evolution_mensajes em
        LEFT JOIN evolution_canales ec ON ec.id = em.canal_id
        {$sqlWhere}
        ORDER BY em.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'      => (int)($stats['total']      ?? 0),
            'pendientes' => (int)($stats['pendientes'] ?? 0),
            'enviados'   => (int)($stats['enviados']   ?? 0),
            'con_error'  => (int)($stats['con_error']  ?? 0),
            'motor'      => $motorValor !== null ? (string)$motorValor : null,
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
               pr.nombre  AS proyecto_nombre,
               ec.nombre  AS canal_nombre,
               dp.nombre  AS plantilla_nombre,
               dc.nombre  AS contacto_nombre,
               dc.celular AS contacto_celular
        FROM evolution_mensajes em
        LEFT JOIN proyectos             pr ON pr.id = em.proyecto_id
        LEFT JOIN evolution_canales     ec ON ec.id = em.canal_id
        LEFT JOIN datarocket_plantillas dp ON dp.id = em.plantilla_id
        LEFT JOIN datarocket_contactos  dc ON dc.id = em.contacto_id
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
    // Plantillas: ademas del id/nombre para el select y proyecto_id para el
    // cascadeo, exponemos remitente/remite/asunto/cuerpo/formato/adjunto para
    // que al elegir una plantilla el frontend pueda autocompletar esos
    // campos del form. `formato` viene como letra legacy (T/I/V/A/U) — el
    // frontend lo mapea a los string full-word que usa evolution_mensajes.
    //
    // Filtro `medio = 'W'` (WhatsApp): `datarocket_plantillas` es un catalogo
    // multi-medio compartido con el ABM del sistema Datarocket (correo + WA);
    // aca solo queremos las de WhatsApp porque el sender Evolution es
    // whatsapp-only. `medio` es varchar(1) legacy — otras opciones son
    // 'C' (correo, ~16 filas al 2026-07-25).
    $plantillas = $pdo->query("
        SELECT id, nombre, proyecto_id,
               remitente, remite, asunto, cuerpo, formato, adjunto
          FROM datarocket_plantillas
         WHERE medio = 'W'
         ORDER BY nombre
    ")->fetchAll();
    $canales    = $pdo->query('SELECT id, nombre FROM evolution_canales ORDER BY nombre')->fetchAll();

    $mapNombre = fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)($r['nombre'] ?? '')];
    jsonOk([
        'proyectos'  => array_map($mapNombre, $proyectos),
        'plantillas' => array_map(
            fn($r) => [
                'id'          => (int)$r['id'],
                'nombre'      => (string)($r['nombre'] ?? ''),
                'proyecto_id' => $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null,
                // Contenido para el auto-fill del form al elegir plantilla:
                'remitente'   => $r['remitente'] !== null ? (string)$r['remitente'] : null,
                'remite'      => $r['remite']    !== null ? (string)$r['remite']    : null,
                'asunto'      => $r['asunto']    !== null ? (string)$r['asunto']    : null,
                'cuerpo'      => $r['cuerpo']    !== null ? (string)$r['cuerpo']    : null,
                'formato'     => $r['formato']   !== null ? (string)$r['formato']   : null,
                'adjunto'     => $r['adjunto']   !== null ? (string)$r['adjunto']   : null,
            ],
            $plantillas
        ),
        'canales'    => array_map($mapNombre, $canales),
    ]);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------
//
// El motor de envio (iniciar/detener) NO vive aca — tiene su propio endpoint
// `cloud/api/evolutionmensajes_motor.php` con el permiso separado
// `plataformas.evolution.mensajes.motor`. Encolar mensajes y parar el motor
// son autoridades distintas.
//
// La sanitizacion, validacion de obligatorios, defaults y la logica de
// INSERT viven en cloud/api/lib/evolution_mensajes.php — el mismo lugar que
// usa el microservicio v4 de ingesta. Es el punto UNICO de entrada de
// mensajes; garantiza que ambos callers apliquen las mismas reglas y
// levanten la bandera `evolution.mensajes.enviar='1'` para despertar al
// sender worker.

function handleCreate(PDO $pdo, array $in): void {
    try {
        $id = encolarEvolutionMensaje($pdo, $in);
        jsonOk(['id' => $id], 201);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM evolution_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
