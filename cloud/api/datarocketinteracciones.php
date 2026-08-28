<?php
// api/datarocketinteracciones.php
// ABM de interacciones sobre prospectos Datarocket. Lee y escribe sobre la
// tabla `datarocket_interacciones` definida en db/schema.sql.
//
// Las altas AUTOMATICAS las siguen haciendo las APIs de envio (aws_mensajes /
// evolution_mensajes) escribiendo directo en la tabla. El POST de aca es para
// las MANUALES — la nota de un llamado, una reunion, una consulta que entro por
// un canal sin canalizador — y se usa desde "Registrar interaccion" del menu
// contextual de Oportunidades.
//
//   GET    api/datarocketinteracciones.php          -> listado con filtros (query string)
//   GET    api/datarocketinteracciones.php?id=N     -> registro individual
//   POST   api/datarocketinteracciones.php          -> alta manual (JSON body)
//   PUT    api/datarocketinteracciones.php?id=N     -> modificacion (JSON body)
//   PUT    api/datarocketinteracciones.php?id=N&action=responder
//                                                   -> marca/desmarca respondida
//   PUT    api/datarocketinteracciones.php?id=N&action=descartar
//                                                   -> marca/desmarca descartada
//   DELETE api/datarocketinteracciones.php?id=N     -> baja
//
// `prospecto_id` y `oportunidad_id` se fijan en el alta y NO se pueden cambiar
// despues: el PUT los ignora aunque vengan en el payload. Una interaccion es un
// hecho sobre una persona y un negocio concretos; si se cargo sobre el
// equivocado se borra y se registra de nuevo. Misma regla que
// `datarocket_oportunidades.prospecto_id`.
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
// Nota sobre `descartada` (migracion 20260828_1900):
//   Tercer estado de una entrante, tambien datetime NULL-able: la consulta que
//   NO hay que contestar (spam, formulario en blanco, mensaje sin pregunta).
//   Excluyente con `respondida` — las dos acciones se rechazan mutuamente con
//   409 — y como ella, solo aplica a las entrantes.
//
//   Los tres estados quedan:
//     pendiente  -> sentido='entrante' AND respondida IS NULL AND descartada IS NULL
//     respondida -> respondida IS NOT NULL
//     descartada -> descartada IS NOT NULL
//
//   La descartada sale de las pendientes (`?pendiente=1`), de la tarjeta "Sin
//   responder" del landing y de la cola del aviso por WhatsApp, pero NO entra en
//   `respuesta_promedio`: nunca se contesto, y contarla ahi arruinaria la metrica
//   con lo que justamente no era trabajo. Filtro propio: `?descartada=1`.
//
// Nota sobre `?asignado=`:
//   Filtra por el responsable de la OPORTUNIDAD relacionada (`o.asignado`, via
//   el LEFT JOIN de DR_INT_JOINS): la interaccion no tiene dueño propio.
//   `?asignado=N` acota a ese usuario y `?asignado=_null` a las que no tienen
//   responsable — incluidas las interacciones sueltas, que al no colgar de
//   ninguna oportunidad tampoco tienen a quien asignarles. Ese `_null` es el
//   que usa la tarjeta "Sin asignar" del landing de Datarocket.
//
// Nota sobre `oportunidad_id`:
//   FK NULL-able a `datarocket_oportunidades`. Filtrable por query string
//   (`?oportunidad_id=N`) — lo usa la pestaña "Interacciones" del modal de
//   Consultar oportunidad. El SELECT devuelve ademas `oportunidad_producto` para
//   poder etiquetar el link de vuelta a la oportunidad (era `oportunidad_asunto`
//   hasta que la migracion 20260817_1700 dropeo `datarocket_oportunidades.asunto`).
//
// Nota sobre `embudo_id`:
//   La interaccion NO guarda embudo propio: se filtra por el de la oportunidad
//   relacionada (`o.embudo_id`, via el LEFT JOIN de DR_INT_JOINS). Por eso
//   `?embudo_id=N` deja afuera las interacciones sueltas del prospecto — las que
//   tienen `oportunidad_id` NULL no pertenecen a ningun embudo. Lo usa la
//   pestaña "Interacciones" del modal de Consultar embudo.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// `respuesta_minutos` / `espera_minutos` son columnas calculadas: el tiempo que
// tardo la respuesta, o el que lleva esperando si sigue pendiente. Se resuelven
// en SQL y no en el cliente para que el reloj sea el del servidor (la BD y el
// panel comparten zona horaria — ver la herramienta Editor de zona horaria).
const DR_INT_COLS = "i.id, i.fecha, i.prospecto_id, i.oportunidad_id, i.sentido, i.canal,
                     i.respondida, i.descartada, i.asunto, i.mensaje,
                     CASE WHEN i.respondida IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, i.fecha, i.respondida) END AS respuesta_minutos,
                     CASE WHEN i.sentido = 'entrante' AND i.respondida IS NULL
                                                     AND i.descartada IS NULL
                          THEN TIMESTAMPDIFF(MINUTE, i.fecha, NOW())        END AS espera_minutos,
                     p.nombre AS prospecto_nombre,
                     p.correo AS prospecto_correo,
                     o.producto AS oportunidad_producto,
                     o.proyecto_id AS proyecto_id,
                     pr.nombre     AS proyecto_nombre,
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
//
// `proyectos` cuelga tambien de la oportunidad (`o.proyecto_id`): ni la
// interaccion ni el prospecto tienen proyecto propio, asi que las interacciones
// sueltas (sin `oportunidad_id`) vienen con `proyecto_nombre` NULL y el listado
// las muestra con guion.
const DR_INT_JOINS = "LEFT JOIN datarocket_prospectos    p  ON p.id  = i.prospecto_id
                      LEFT JOIN datarocket_oportunidades o  ON o.id  = i.oportunidad_id
                      LEFT JOIN proyectos                pr ON pr.id = o.proyecto_id
                      LEFT JOIN usuarios                 u  ON u.id  = o.asignado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('datarocket.interacciones');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT' && $action === 'responder') {
        // Marcado de respondida. Vive en su propia accion desde que el PUT
        // plano pasa a editar el contenido: son dos operaciones con reglas
        // distintas (esta solo aplica a entrantes y valida contra la fecha).
        if ($id <= 0) jsonError('Falta id', 400);
        handleResponder($pdo, $id, readJsonBody());
    } elseif ($method === 'PUT' && $action === 'descartar') {
        // Tercer estado (20260828_1900). Vive en su propia accion por la misma
        // razon que `responder`: reglas propias, y el PUT plano edita contenido.
        if ($id <= 0) jsonError('Falta id', 400);
        handleDescartar($pdo, $id, readJsonBody());
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
    $codigo      = isset($q['codigo'])         && $q['codigo']         !== '' ? (int)$q['codigo']         : null;
    $prospecto   = isset($q['prospecto_id'])   && $q['prospecto_id']   !== '' ? (int)$q['prospecto_id']   : null;
    $oportunidad = isset($q['oportunidad_id']) && $q['oportunidad_id'] !== '' ? (int)$q['oportunidad_id'] : null;
    $embudo      = isset($q['embudo_id'])      && $q['embudo_id']      !== '' ? (int)$q['embudo_id']      : null;
    $sentido  = trim((string)($q['sentido'] ?? ''));
    $canal    = trim((string)($q['canal']   ?? ''));
    // Responsable. Cuelga de la oportunidad (`o.asignado`), no de la
    // interaccion. `_null` es el centinela de "sin asignar" — mismo patron que
    // el `_null` de `canal` — e incluye las interacciones sueltas: sin
    // oportunidad no hay a quien asignarle nada.
    $asignado = trim((string)($q['asignado'] ?? ''));
    // Estado de respuesta. Viajan como flags separados y no como un solo
    // parametro tri-estado porque asi el "sin filtro" sigue siendo la ausencia
    // de la clave, igual que el resto de los filtros del ABM.
    $pendiente  = trim((string)($q['pendiente']  ?? '')) === '1';
    $respondida = trim((string)($q['respondida'] ?? '')) === '1';
    $descartada = trim((string)($q['descartada'] ?? '')) === '1';
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
    // Filtro por embudo: cuelga de la oportunidad, no de la interaccion. El
    // `o.embudo_id` ya deja afuera las que no tienen oportunidad (NULL nunca
    // matchea), asi que no hace falta un IS NOT NULL extra.
    if ($embudo      !== null) { $where[] = 'o.embudo_id = :embudo';            $params[':embudo']      = $embudo; }
    if ($sentido     !== '')   { $where[] = 'i.sentido = :sentido';             $params[':sentido']     = $sentido; }
    // `_null` como centinela de "sin canal": es la marca de las notas internas,
    // y `?canal=` vacio ya significa "sin filtro". Mismo patron que el `_null`
    // de `tipo` en el ABM de prospectos.
    if ($canal === '_null')    { $where[] = 'i.canal IS NULL'; }
    elseif ($canal !== '')     { $where[] = 'i.canal = :canal';                 $params[':canal']       = $canal; }
    // El `0` entra en "sin asignar" junto al NULL: es como quedan las filas
    // viejas a las que nunca se les cargo responsable (mismo criterio que la
    // stat `sin_atender` del ABM de oportunidades).
    if ($asignado === '_null') { $where[] = '(o.asignado IS NULL OR o.asignado = 0)'; }
    elseif ($asignado !== '')  { $where[] = 'o.asignado = :asignado';           $params[':asignado']    = (int)$asignado; }
    // "Pendiente" es solo de las entrantes: una saliente sin `respondida` no
    // esta esperando nada. Y desde la 20260828_1900 tampoco esta pendiente la
    // descartada — spam o mensaje sin pregunta: nadie la va a contestar, asi que
    // dejarla en la cola era ruido permanente (y un recordatorio por hora al
    // vendedor, desde que existe el aviso por WhatsApp).
    if ($pendiente)            { $where[] = "i.sentido = 'entrante' AND i.respondida IS NULL AND i.descartada IS NULL"; }
    if ($respondida)           { $where[] = 'i.respondida IS NOT NULL'; }
    if ($descartada)           { $where[] = 'i.descartada IS NOT NULL'; }
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
                          AND descartada IS NULL
                     THEN 1 ELSE 0 END)                              AS pendientes,
            SUM(CASE WHEN descartada IS NOT NULL THEN 1 ELSE 0 END)  AS descartadas,
            -- Promedio de demora sobre lo ya contestado, en minutos. NULL
            -- mientras no haya ninguna respondida. Las descartadas no entran:
            -- nunca se contestaron, y contarlas como demora enorme arruinaria
            -- la metrica justo con lo que no era trabajo real.
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
            'descartadas'        => (int)($stats['descartadas'] ?? 0),
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
// Alta manual / Modificacion
// ----------------------------------------------------------------------------

// Valores validos de `sentido` y `canal`. Se leen del catalogo `estados` (una
// sola consulta, cacheada por request) en vez de hardcodearlos, para que sumar
// un canal sea agregar una fila y no tocar este archivo. Si el catalogo
// estuviera vacio se devuelve lista vacia y la validacion rechaza todo — es
// preferible a aceptar cualquier string en una columna que despues alimenta
// filtros y estadisticas.
function drIntOpcionesCatalogo(PDO $pdo, string $campo): array {
    static $cache = [];
    if (isset($cache[$campo])) return $cache[$campo];
    $stmt = $pdo->prepare('SELECT valor FROM estados WHERE campo = :campo');
    $stmt->execute([':campo' => 'datarocket_interaccion_' . $campo]);
    $cache[$campo] = array_map(static fn($r) => (string)$r['valor'], $stmt->fetchAll());
    return $cache[$campo];
}

function drIntNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = mb_substr($s, 0, $max);
    return $s;
}

// Normaliza y valida los campos de contenido que comparten alta y modificacion.
// `prospecto_id` / `oportunidad_id` NO salen de aca: son del alta y despues
// inmutables.
function drIntSanitizeContenido(PDO $pdo, array $in): array {
    $fecha = drIntNormalizarFecha($in['fecha'] ?? '');
    if ($fecha === null) {
        jsonError('La fecha no es válida (se espera AAAA-MM-DD HH:MM).', 400);
    }

    $sentido = drIntNullableStr($in['sentido'] ?? null, 10);
    if ($sentido === null) jsonError('El sentido es obligatorio.', 400);
    if (!in_array($sentido, drIntOpcionesCatalogo($pdo, 'sentido'), true)) {
        jsonError('El sentido indicado no es válido.', 400);
    }

    // `canal` es NULL-able a proposito: las notas internas no salieron por
    // ningun lado. Si viene, tiene que estar en el catalogo.
    $canal = drIntNullableStr($in['canal'] ?? null, 20);
    if ($canal !== null && !in_array($canal, drIntOpcionesCatalogo($pdo, 'canal'), true)) {
        jsonError('El canal indicado no es válido.', 400);
    }

    return [
        'fecha'   => $fecha,
        'sentido' => $sentido,
        'canal'   => $canal,
        'asunto'  => drIntNullableStr($in['asunto']  ?? null, 500),
        'mensaje' => drIntNullableStr($in['mensaje'] ?? null),
    ];
}

// POST api/datarocketinteracciones.php
//   body {fecha, prospecto_id, oportunidad_id, sentido, canal, asunto, mensaje}
//
// `respondida` no se acepta en el alta: una interaccion nace pendiente (si es
// entrante) y se sella despues con ?action=responder.
function handleCreate(PDO $pdo, array $in): void {
    $p = drIntSanitizeContenido($pdo, $in);

    $prospectoId   = isset($in['prospecto_id'])   && $in['prospecto_id']   !== '' ? (int)$in['prospecto_id']   : null;
    $oportunidadId = isset($in['oportunidad_id']) && $in['oportunidad_id'] !== '' ? (int)$in['oportunidad_id'] : null;

    // Invariante de la tabla (documentada en db/schema.sql): al menos uno de los
    // dos. No hay CHECK constraint en este schema, asi que se valida aca.
    if (!$prospectoId && !$oportunidadId) {
        jsonError('La interacción tiene que colgar de un prospecto o de una oportunidad.', 400);
    }

    // La FK de oportunidad existe y abortaria el INSERT con un error crudo de
    // MySQL; se chequea antes para devolver un mensaje entendible. `prospecto_id`
    // todavia no lleva FK (ver el comentario de la tabla en db/schema.sql), asi
    // que su chequeo es la unica barrera contra un huerfano nuevo.
    if ($oportunidadId) {
        $st = $pdo->prepare('SELECT prospecto_id FROM datarocket_oportunidades WHERE id = :id');
        $st->execute([':id' => $oportunidadId]);
        $opo = $st->fetch();
        if (!$opo) jsonError('La oportunidad indicada no existe.', 400);
        // Si no vino prospecto, se hereda el de la oportunidad: son la misma
        // persona y deja la fila consultable desde la ficha del prospecto.
        if (!$prospectoId && !empty($opo['prospecto_id'])) {
            $prospectoId = (int)$opo['prospecto_id'];
        }
    }
    if ($prospectoId) {
        $st = $pdo->prepare('SELECT id FROM datarocket_prospectos WHERE id = :id');
        $st->execute([':id' => $prospectoId]);
        if (!$st->fetch()) jsonError('El prospecto indicado no existe.', 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO datarocket_interacciones
            (fecha, prospecto_id, oportunidad_id, sentido, canal, asunto, mensaje)
         VALUES
            (:fecha, :prospecto_id, :oportunidad_id, :sentido, :canal, :asunto, :mensaje)'
    );
    $stmt->execute([
        ':fecha'          => $p['fecha'],
        ':prospecto_id'   => $prospectoId,
        ':oportunidad_id' => $oportunidadId,
        ':sentido'        => $p['sentido'],
        ':canal'          => $p['canal'],
        ':asunto'         => $p['asunto'],
        ':mensaje'        => $p['mensaje'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

// PUT api/datarocketinteracciones.php?id=N
//   body {fecha, sentido, canal, asunto, mensaje}
//
// NO toca `prospecto_id` / `oportunidad_id` (fijos desde el alta) ni
// `respondida` (tiene su propia accion, con reglas propias).
function handleUpdate(PDO $pdo, int $id, array $in): void {
    $st = $pdo->prepare('SELECT id, sentido, respondida FROM datarocket_interacciones WHERE id = :id');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Interaccion no encontrada', 404);

    $p = drIntSanitizeContenido($pdo, $in);

    // Coherencia con `respondida`: solo las entrantes pueden tenerla sellada,
    // asi que sacarle "entrante" a una que ya fue respondida dejaria un dato
    // imposible. Se frena en vez de limpiar `respondida` en silencio.
    if ($prev['respondida'] !== null && $p['sentido'] !== 'entrante') {
        jsonError('Esta interacción ya figura respondida: solo las entrantes pueden estarlo. Volvela a pendiente antes de cambiarle el sentido.', 409);
    }

    $stmt = $pdo->prepare(
        'UPDATE datarocket_interacciones SET
            fecha   = :fecha,
            sentido = :sentido,
            canal   = :canal,
            asunto  = :asunto,
            mensaje = :mensaje
          WHERE id = :id'
    );
    $stmt->execute([
        ':fecha'   => $p['fecha'],
        ':sentido' => $p['sentido'],
        ':canal'   => $p['canal'],
        ':asunto'  => $p['asunto'],
        ':mensaje' => $p['mensaje'],
        ':id'      => $id,
    ]);
    jsonOk(['id' => $id]);
}

// ----------------------------------------------------------------------------
// Marcar / desmarcar respondida
// ----------------------------------------------------------------------------

// PUT api/datarocketinteracciones.php?id=N&action=responder
//   body {"respondida": true}                    -> sella con la hora actual
//   body {"respondida": "2026-08-17 09:30:00"}   -> sella con esa hora
//   body {"respondida": false|null}              -> vuelve a pendiente
function handleResponder(PDO $pdo, int $id, array $in): void {
    $st = $pdo->prepare('SELECT sentido, fecha, descartada FROM datarocket_interacciones WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Interaccion no encontrada', 404);

    // Solo las entrantes esperan respuesta. Marcar una saliente o una nota
    // interna no significa nada, asi que se rechaza en vez de guardar un dato
    // que despues ensucia el promedio de demora.
    if ((string)$row['sentido'] !== 'entrante') {
        jsonError('Solo las interacciones entrantes se pueden marcar como respondidas.', 400);
    }

    // Respondida y descartada son excluyentes: una consulta se contesto o se
    // decidio que no habia nada que contestar, nunca las dos. Se frena en vez de
    // limpiar `descartada` en silencio — que el operador diga cual de las dos
    // vale (misma politica que el choque sentido/respondida del PUT).
    $quiereSellar = !in_array($in['respondida'] ?? null, [false, null, '', 0, '0'], true);
    if ($quiereSellar && $row['descartada'] !== null) {
        jsonError('Esta interacción figura descartada: quitale el descarte antes de marcarla como respondida.', 409);
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

// ----------------------------------------------------------------------------
// Descartar / recuperar
// ----------------------------------------------------------------------------

// PUT api/datarocketinteracciones.php?id=N&action=descartar
//   body {"descartada": true}                    -> sella con la hora actual
//   body {"descartada": "2026-08-28 09:30:00"}   -> sella con esa hora
//   body {"descartada": false|null}              -> vuelve a pendiente
//
// Descartar es "esto no hay que contestarlo": spam, un formulario mandado en
// blanco, un mensaje sin ninguna pregunta. Saca la consulta de las pendientes
// SIN mentir que alguien la contesto — que es lo que pasaba cuando el unico
// escape era marcarla respondida: inflaba el trabajo hecho y metia en el
// promedio de demora consultas que nadie atendio.
//
// Es reversible: `false` la devuelve a pendiente. Si el descarte estuvo mal, no
// hay que borrar y volver a crear la interaccion.
function handleDescartar(PDO $pdo, int $id, array $in): void {
    $st = $pdo->prepare('SELECT sentido, fecha, respondida FROM datarocket_interacciones WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Interaccion no encontrada', 404);

    // Misma regla que `responder`: solo las entrantes tienen algo que descartar.
    // Una saliente o una nota interna no esperan nada de nadie.
    if ((string)$row['sentido'] !== 'entrante') {
        jsonError('Solo las interacciones entrantes se pueden descartar.', 400);
    }

    $raw = $in['descartada'] ?? null;

    if ($raw === false || $raw === null || $raw === '' || $raw === 0 || $raw === '0') {
        $descartada = null;                       // vuelve a pendiente
    } elseif ($raw === true || $raw === 1 || $raw === '1') {
        $descartada = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    } else {
        $descartada = drIntNormalizarFecha($raw);
        if ($descartada === null) {
            jsonError('La fecha de descarte no es válida (se espera AAAA-MM-DD HH:MM).', 400);
        }
        if ($descartada < (string)$row['fecha']) {
            jsonError('El descarte no puede ser anterior a la consulta.', 400);
        }
    }

    // Excluyente con `respondida`, igual que del otro lado.
    if ($descartada !== null && $row['respondida'] !== null) {
        jsonError('Esta interacción ya figura respondida: volvela a pendiente antes de descartarla.', 409);
    }

    $upd = $pdo->prepare('UPDATE datarocket_interacciones SET descartada = :d WHERE id = :id');
    $upd->execute([':d' => $descartada, ':id' => $id]);

    jsonOk(['id' => $id, 'descartada' => $descartada]);
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
