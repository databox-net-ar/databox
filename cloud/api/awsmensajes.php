<?php
// api/awsmensajes.php
// ABM de mensajes AWS. Lee/escribe sobre la tabla `aws_mensajes`
// definida en db/schema.sql.
//   GET    api/awsmensajes.php          -> listado con filtros (query string)
//   GET    api/awsmensajes.php?id=N     -> registro individual
//   POST   api/awsmensajes.php          -> alta (JSON body)
//   DELETE api/awsmensajes.php?id=N     -> baja
// Los mensajes encolados NO son editables: cambiar el asunto/cuerpo/destino
// despues de que el mensaje entra a la cola no tiene semantica logica
// (podria estar en pleno envio). Las unicas acciones post-encolado son
// DELETE (borrar del historial) y anular (via api/awsmensajes_anular.php,
// setea estado='anulado' y detiene el envio si aun no salio).
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/aws_mensajes.php';   // encolarAwsMensaje() + sanitizadores

// SET de columnas de `aws_mensajes` (alias `m.`) + nombres traidos por LEFT
// JOIN a proyectos / aws_canales / datarocket_plantillas. Reusado por listado
// y consulta individual — el modal Consultar los muestra en la pestana
// Detalles.
const AWS_MSG_COLS = "m.id, m.fecha, m.proyecto_id, m.canal_id, m.plantilla_id,
                      m.remitente, m.remite, m.destinatario, m.destino,
                      m.prioridad, m.asunto, m.cuerpo, m.formato,
                      m.adjunto, m.tags, m.estado, m.error,
                      m.encolado, m.programado, m.enviado, m.demora,
                      p.nombre AS proyecto_nombre,
                      c.nombre AS canal_nombre,
                      t.nombre AS plantilla_nombre";

const AWS_MSG_JOINS = "LEFT JOIN proyectos             p ON p.id = m.proyecto_id
                       LEFT JOIN aws_canales           c ON c.id = m.canal_id
                       LEFT JOIN datarocket_plantillas t ON t.id = m.plantilla_id";

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
    $proyecto  = isset($q['proyecto_id'])  && $q['proyecto_id']  !== '' ? (int)$q['proyecto_id']  : null;
    $canal     = isset($q['canal_id'])     && $q['canal_id']     !== '' ? (int)$q['canal_id']     : null;
    $plantilla = isset($q['plantilla_id']) && $q['plantilla_id'] !== '' ? (int)$q['plantilla_id'] : null;
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

    if ($codigo    !== null) { $where[] = 'm.id = :codigo';                     $params[':codigo']       = $codigo; }
    if ($proyecto  !== null) { $where[] = 'm.proyecto_id = :proyecto_id';       $params[':proyecto_id']  = $proyecto; }
    if ($canal     !== null) { $where[] = 'm.canal_id = :canal_id';             $params[':canal_id']     = $canal; }
    if ($plantilla !== null) { $where[] = 'm.plantilla_id = :plantilla_id';     $params[':plantilla_id'] = $plantilla; }
    if ($estado    !== '')   { $where[] = 'm.estado = :estado';                 $params[':estado']       = $estado; }
    if ($desde     !== '')   { $where[] = 'm.fecha >= :desde';          $params[':desde']     = $desde . ' 00:00:00'; }
    if ($hasta     !== '')   { $where[] = 'm.fecha <= :hasta';          $params[':hasta']     = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(m.destinatario LIKE :s1 OR m.destino LIKE :s2 OR m.asunto LIKE :s3
                     OR m.remitente LIKE :s4 OR m.remite LIKE :s5 OR m.tags LIKE :s6)';
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
            SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END)     AS pendientes,
            SUM(CASE WHEN error IS NOT NULL AND error <> '' THEN 1 ELSE 0 END) AS con_error
        FROM aws_mensajes
    ")->fetch();

    $sql = "
        SELECT " . AWS_MSG_COLS . "
        FROM aws_mensajes m
        " . AWS_MSG_JOINS . "
        {$sqlWhere}
        ORDER BY m.{$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            // Estado del motor del cron: '1' = enviando, '0' o ausente =
            // esperando. Se lee del `parametros` en cada corrida del listado
            // asi la tarjeta refleja cambios sin recarga del panel.
            'motor'      => getParametro('aws.mensajes.enviar', '0'),
            'total'      => (int)($stats['total']      ?? 0),
            'pendientes' => (int)($stats['pendientes'] ?? 0),
            'enviados'   => (int)($stats['enviados']   ?? 0),
            'con_error'  => (int)($stats['con_error']  ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $sql = "
        SELECT " . AWS_MSG_COLS . "
        FROM aws_mensajes m
        " . AWS_MSG_JOINS . "
        WHERE m.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Mensaje no encontrado', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Baja
// ----------------------------------------------------------------------------
// Toda la logica de ingreso a `aws_mensajes` (sanitizacion, obligatorios,
// defaults, wake-on-demand) vive en cloud/api/lib/aws_mensajes.php. Este
// endpoint es un wrapper HTTP: convierte el body JSON en argumentos, mapea
// InvalidArgumentException -> 400, y devuelve el id nuevo.
//
// La modificacion (PUT) NO existe adrede: un mensaje encolado no es editable.
// La unica mutacion de estado post-encolado es "anular", que tiene su propio
// endpoint dedicado (cloud/api/awsmensajes_anular.php).

function handleCreate(PDO $pdo, array $in): void {
    try {
        $id = encolarAwsMensaje($pdo, $in);
        jsonOk(['id' => $id], 201);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM aws_mensajes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Mensaje no encontrado', 404);
    jsonOk(['id' => $id]);
}
