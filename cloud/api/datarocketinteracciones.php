<?php
// api/datarocketinteracciones.php
// ABM (solo lectura + baja) de interacciones sobre contactos Datarocket.
// Lee sobre la tabla `datarocket_interacciones` definida en db/schema.sql.
// Las altas las hacen las APIs de envio (aws_mensajes / evolution_mensajes)
// automaticamente, por eso este endpoint NO expone POST/PUT.
//   GET    api/datarocketinteracciones.php          -> listado con filtros (query string)
//   GET    api/datarocketinteracciones.php?id=N     -> registro individual
//   DELETE api/datarocketinteracciones.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Nota sobre `mensaje_id` + `origen`:
//   Asociacion polimorfica. `mensaje_id` guarda el ID del mensaje y
//   `origen` indica en que tabla buscarlo. Los unicos valores validos
//   de `origen` hoy son 'aws_mensajes' y 'evolution_mensajes'.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const DR_INT_COLS = "i.id, i.fecha, i.contacto_id, i.tipo, i.origen,
                     i.mensaje_id, i.descripcion,
                     c.nombre AS contacto_nombre,
                     c.correo AS contacto_correo";

const DR_INT_ORIGENES_VALIDOS = ['aws_mensajes', 'evolution_mensajes'];

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
    $codigo   = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $contacto = isset($q['contacto_id']) && $q['contacto_id'] !== '' ? (int)$q['contacto_id'] : null;
    $mensaje  = isset($q['mensaje_id'])  && $q['mensaje_id']  !== '' ? (int)$q['mensaje_id']  : null;
    $tipo     = trim((string)($q['tipo']   ?? ''));
    $origen   = trim((string)($q['origen'] ?? ''));
    $desde    = trim((string)($q['desde']  ?? ''));
    $hasta    = trim((string)($q['hasta']  ?? ''));
    $search   = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'fecha', 'contacto_id', 'tipo', 'origen', 'mensaje_id'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    $orderCol = 'i.' . $orderBy;

    $where  = [];
    $params = [];

    if ($codigo   !== null) { $where[] = 'i.id = :codigo';                 $params[':codigo']   = $codigo; }
    if ($contacto !== null) { $where[] = 'i.contacto_id = :contacto';      $params[':contacto'] = $contacto; }
    if ($mensaje  !== null) { $where[] = 'i.mensaje_id = :mensaje';        $params[':mensaje']  = $mensaje; }
    if ($tipo     !== '')   { $where[] = 'i.tipo = :tipo';                 $params[':tipo']     = $tipo; }
    if ($origen   !== '')   { $where[] = 'i.origen = :origen';             $params[':origen']   = $origen; }
    if ($desde    !== '')   { $where[] = 'i.fecha >= :desde';              $params[':desde']    = $desde . ' 00:00:00'; }
    if ($hasta    !== '')   { $where[] = 'i.fecha <= :hasta';              $params[':hasta']    = $hasta . ' 23:59:59'; }

    if ($search !== '') {
        $where[] = '(i.descripcion LIKE :s1 OR c.nombre LIKE :s2 OR c.correo LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Stats globales (ignoran filtros — son indicadores del recurso).
    $stats = $pdo->query("
        SELECT
            COUNT(*)                                     AS total,
            COUNT(DISTINCT contacto_id)                  AS contactos,
            SUM(CASE WHEN tipo = 'correo_enviado' THEN 1 ELSE 0 END) AS correos
        FROM datarocket_interacciones
    ")->fetch();

    $sql = "
        SELECT " . DR_INT_COLS . "
        FROM datarocket_interacciones i
        LEFT JOIN datarocket_contactos c ON c.id = i.contacto_id
        {$sqlWhere}
        ORDER BY {$orderCol} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'     => (int)($stats['total']     ?? 0),
            'contactos' => (int)($stats['contactos'] ?? 0),
            'correos'   => (int)($stats['correos']   ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $sql = "
        SELECT " . DR_INT_COLS . "
        FROM datarocket_interacciones i
        LEFT JOIN datarocket_contactos c ON c.id = i.contacto_id
        WHERE i.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Interaccion no encontrada', 404);
    jsonOk($row);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM datarocket_interacciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Interaccion no encontrada', 404);
    jsonOk(['id' => $id]);
}
