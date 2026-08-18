<?php
// api/datarocketinteracciones.php
// ABM (lectura + marcar respondida + baja) de interacciones sobre prospectos
// Datarocket. Lee sobre la tabla `datarocket_interacciones` definida en
// db/schema.sql. Las altas las hacen las APIs de envio (aws_mensajes /
// evolution_mensajes) automaticamente, por eso este endpoint NO expone POST.
//   GET    api/datarocketinteracciones.php          -> listado con filtros (query string)
//   GET    api/datarocketinteracciones.php?id=N     -> registro individual
//   PUT    api/datarocketinteracciones.php?id=N     -> marca/desmarca respondida
//   DELETE api/datarocketinteracciones.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Nota sobre `asunto` + `mensaje`:
//   `asunto` es la etiqueta corta del listado (VARCHAR(500), NULL en los
//   canales que no tienen asunto) y `mensaje` el cuerpo completo (MEDIUMTEXT).
//   Son el contenido en si: la interaccion NO apunta a la fila de la cola de
//   envio que la origino. `mensaje_id`, `origen` y `usuario_id` se dropearon
//   en la migracion 20260817_1200 — el timeline guarda que se dijo, no de que
//   despacho salio.
//
// Nota sobre `sentido` + `canal`:
//   Reemplazan al viejo `tipo`, que mezclaba los dos ejes en un solo slug
//   ("correo_enviado" = saliente + correo) y obligaba a un LIKE sobre el
//   sufijo para poder filtrar por direccion (migracion 20260817_1000, drop de
//   `tipo` en la 20260817_1100).
//     `sentido` -> entrante | saliente | interna
//     `canal`   -> correo | whatsapp | telegram | sms | web | telefono |
//                  presencial, o NULL cuando no hubo comunicacion (las notas
//                  internas) o no se sabe por donde entro.
//   Los valores validos viven en el catalogo `estados`, campos
//   `datarocket_interaccion_sentido` y `datarocket_interaccion_canal`.
//
// Nota sobre `respondida` (migracion 20260817_1300):
//   Datetime NULL-able. NULL = la consulta sigue pendiente. Solo tiene sentido
//   sobre las entrantes — el PUT rechaza con 400 cualquier otro sentido.
//   El listado expone ademas dos calculadas: `respuesta_minutos` (cuanto tardo
//   la respuesta) y `espera_minutos` (cuanto lleva esperando la que sigue
//   pendiente). Filtros: `?pendiente=1` y `?respondida=1`.
//
// Nota sobre `oportunidad_id`:
//   FK NULL-able a `datarocket_oportunidades`. Filtrable por query string
//   (`?oportunidad_id=N`) — lo usa la pestaña "Interacciones" del modal de
//   Consultar oportunidad. El SELECT devuelve ademas `oportunidad_producto` para
//   poder etiquetar el link de vuelta a la oportunidad (era `oportunidad_asunto`
//   hasta que la migracion 20260817_1700 dropeo `datarocket_oportunidades.asunto`).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// `respuesta_minutos` / `espera_minutos` son columnas calculadas: el tiempo que
// tardo la respuesta, o el que lleva esperando si sigue pendiente. Se resuelven
// en SQL y no en el cliente para que el reloj sea el del servidor (la BD y el
// panel comparten zona horaria — ver la herramienta Editor de zona horaria).
const DR_INT_COLS = "i.id, i.fecha, i.prospecto_id, i.oportunidad_id, i.sentido, i.canal,
                     i.respondida, i.asunto, i.mensaje,
                     CASE WHEN i.respondida IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, i.fecha, i.respondida) END AS respuesta_minutos,
                     CASE WHEN i.sentido = 'entrante' AND i.respondida IS NULL
                          THEN TIMESTAMPDIFF(MINUTE, i.fecha, NOW())        END AS espera_minutos,
                     p.nombre AS prospecto_nombre,
                     p.correo AS prospecto_correo,
                     o.producto AS oportunidad_producto,
                     o.asignado AS asignado_id,
                     u.nombre   AS asignado_nombre";

// JOINs comunes al listado y al GET por id. `datarocket_oportunidades` entra para
// poder mostrar el producto de la oportunidad relacionada como etiqueta del link en
// el modal de Consultar (la FK `oportunidad_id` es NULL-able, por eso LEFT).
//
// `usuarios` cuelga de la oportunidad, no de la interaccion: el responsable de un
// evento es quien tiene asignado el negocio. La interaccion no guarda autor
// propio — la columna `usuario_id` existio pero nunca se escribio y se dropeo
// en la 20260817_1200.
const DR_INT_JOINS = "LEFT JOIN datarocket_prospectos    p ON p.id = i.prospecto_id
                      LEFT JOIN datarocket_oportunidades o ON o.id = i.oportunidad_id
                      LEFT JOIN usuarios                 u ON u.id = o.asignado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.interacciones');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleResponder($pdo, $id, readJsonBody());
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
    $codigo      = isset($q['codigo'])         && $q['codigo']         !== '' ? (int)$q['codigo']         : null;
    $prospecto   = isset($q['prospecto_id'])   && $q['prospecto_id']   !== '' ? (int)$q['prospecto_id']   : null;
    $oportunidad = isset($q['oportunidad_id']) && $q['oportunidad_id'] !== '' ? (int)$q['oportunidad_id'] : null;
    $sentido  = trim((string)($q['sentido'] ?? ''));
    $canal    = trim((string)($q['canal']   ?? ''));
    // Estado de respuesta. Viajan como flags separados y no como un solo
    // parametro tri-estado porque asi el "sin filtro" sigue siendo la ausencia
    // de la clave, igual que el resto de los filtros del ABM.
    $pendiente  = trim((string)($q['pendiente']  ?? '')) === '1';
    $respondida = trim((string)($q['respondida'] ?? '')) === '1';
    $desde    = trim((string)($q['desde']   ?? ''));
    $hasta    = trim((string)($q['hasta']   ?? ''));
    $search   = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'prospecto_id', 'oportunidad_id', 'sentido', 'canal',
                     'respondida'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    $orderCol = 'i.' . $orderBy;

    $where  = [];
    $params = [];

    if ($codigo      !== null) { $where[] = 'i.id = :codigo';                   $params[':codigo']      = $codigo; }
    if ($prospecto   !== null) { $where[] = 'i.prospecto_id = :prospecto';      $params[':prospecto']   = $prospecto; }
    if ($oportunidad !== null) { $where[] = 'i.oportunidad_id = :oportunidad';  $params[':oportunidad'] = $oportunidad; }
    if ($sentido     !== '')   { $where[] = 'i.sentido = :sentido';             $params[':sentido']     = $sentido; }
    // `_null` como centinela de "sin canal": es la marca de las notas internas,
    // y `?canal=` vacio ya significa "sin filtro". Mismo patron que el `_null`
    // de `tipo` en el ABM de prospectos.
    if ($canal === '_null')    { $where[] = 'i.canal IS NULL'; }
    elseif ($canal !== '')     { $where[] = 'i.canal = :canal';                 $params[':canal']       = $canal; }
    // "Pendiente" es solo de las entrantes: una saliente sin `respondida` no
    // esta esperando nada.
    if ($pendiente)            { $where[] = "i.sentido = 'entrante' AND i.respondida IS NULL"; }
    if ($respondida)           { $where[] = 'i.respondida IS NOT NULL'; }
    if ($desde       !== '')   { $where[] = 'i.fecha >= :desde';                $params[':desde']       = $desde . ' 00:00:00'; }
    if ($hasta       !== '')   { $where[] = 'i.fecha <= :hasta';                $params[':hasta']       = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(i.asunto LIKE :s1 OR i.mensaje LIKE :s2
                     OR p.nombre LIKE :s3 OR p.correo LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso). Antes la
    // tercera tarjeta contaba `tipo = 'correo_enviado'`; con el eje de
    // direccion separado, el corte util es entrante vs saliente.
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                 AS total,
            COUNT(DISTINCT prospecto_id)                              AS prospectos,
            SUM(CASE WHEN sentido = 'entrante' THEN 1 ELSE 0 END)    AS entrantes,
            SUM(CASE WHEN sentido = 'entrante' AND respondida IS NULL
                     THEN 1 ELSE 0 END)                              AS pendientes,
            -- Promedio de demora sobre lo ya contestado, en minutos. NULL
            -- mientras no haya ninguna respondida.
            AVG(CASE WHEN respondida IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, fecha, respondida) END) AS respuesta_promedio
        FROM datarocket_interacciones
    ")->fetch();

    $sql = "
        SELECT " . DR_INT_COLS . "
        FROM datarocket_interacciones i
        " . DR_INT_JOINS . "
        {$sqlWhere}
        ORDER BY {$orderCol} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'              => (int)($stats['total']      ?? 0),
            'prospectos'          => (int)($stats['prospectos']  ?? 0),
            'entrantes'          => (int)($stats['entrantes']  ?? 0),
            'pendientes'         => (int)($stats['pendientes'] ?? 0),
            'respuesta_promedio' => $stats['respuesta_promedio'] !== null
                                    ? (int)round((float)$stats['respuesta_promedio'])
                                    : null,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $sql = "
        SELECT " . DR_INT_COLS . "
        FROM datarocket_interacciones i
        " . DR_INT_JOINS . "
        WHERE i.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Interaccion no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Marcar / desmarcar respondida
// ----------------------------------------------------------------------------

// PUT api/datarocketinteracciones.php?id=N
//   body {"respondida": true}                    -> sella con la hora actual
//   body {"respondida": "2026-08-17 09:30:00"}   -> sella con esa hora
//   body {"respondida": false|null}              -> vuelve a pendiente
//
// Es lo unico editable de la tabla: el resto de las columnas las escriben los
// canalizadores y no tiene sentido corregirlas a mano.
function handleResponder(PDO $pdo, int $id, array $in): void {
    $st = $pdo->prepare('SELECT sentido, fecha FROM datarocket_interacciones WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Interaccion no encontrada', 404);

    // Solo las entrantes esperan respuesta. Marcar una saliente o una nota
    // interna no significa nada, asi que se rechaza en vez de guardar un dato
    // que despues ensucia el promedio de demora.
    if ((string)$row['sentido'] !== 'entrante') {
        jsonError('Solo las interacciones entrantes se pueden marcar como respondidas.', 400);
    }

    $raw = $in['respondida'] ?? null;

    if ($raw === false || $raw === null || $raw === '' || $raw === 0 || $raw === '0') {
        $respondida = null;                       // vuelve a pendiente
    } elseif ($raw === true || $raw === 1 || $raw === '1') {
        $respondida = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    } else {
        $respondida = drIntNormalizarFecha($raw);
        if ($respondida === null) {
            jsonError('La fecha de respuesta no es válida (se espera AAAA-MM-DD HH:MM).', 400);
        }
        // Una respuesta anterior a la consulta daria una demora negativa.
        if ($respondida < (string)$row['fecha']) {
            jsonError('La respuesta no puede ser anterior a la consulta.', 400);
        }
    }

    $upd = $pdo->prepare('UPDATE datarocket_interacciones SET respondida = :r WHERE id = :id');
    $upd->execute([':r' => $respondida, ':id' => $id]);

    jsonOk([
        'id'                => $id,
        'respondida'        => $respondida,
        'respuesta_minutos' => $respondida !== null
            ? (int)round((strtotime($respondida) - strtotime((string)$row['fecha'])) / 60)
            : null,
    ]);
}

// Acepta 'AAAA-MM-DDTHH:MM' (input datetime-local), 'AAAA-MM-DD HH:MM' y
// 'AAAA-MM-DD HH:MM:SS'. Cualquier otra cosa -> null. Mismo criterio que
// nullableDateTime() en el ABM de prospectos.
function drIntNormalizarFecha(mixed $v): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_interacciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Interaccion no encontrada', 404);
    jsonOk(['id' => $id]);
}
