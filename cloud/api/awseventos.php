<?php
// api/awseventos.php
// Endpoint read-only del ABM cloud > Plataformas > AWS > Eventos. Los
// eventos son inmutables — los produce AWS SES via SNS al webhook
// `api/v4/aws/eventos.php`. Este endpoint solo sirve para listarlos y
// consultarlos.
//
//   GET api/awseventos.php          -> listado con filtros (query string)
//   GET api/awseventos.php?id=N     -> registro individual (incluye raw JSON)
//
// Filtros del listado (query string, todos opcionales):
//   uuid=<SES MessageId>       exacto
//   tipo=delivery|bounce|...   exacto
//   destino=<email>            LIKE
//   desde=YYYY-MM-DD           recibido >=
//   hasta=YYYY-MM-DD           recibido <=
//   q=<texto>                  LIKE contra uuid/destino/subtipo
//   order_by=id|recibido|tipo  (default: id)
//   dir=asc|desc               (default: desc)
//   limite=1..1000             (default: 100)
//
// Respuesta: {ok:true, data:{stats:{...}, items:[...]}} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const AWS_EVT_COLS = 'id, uuid, sns_message_id, tipo, subtipo, destino, recibido';
const AWS_EVT_COLS_DETAIL = 'id, uuid, sns_message_id, tipo, subtipo, destino, raw, recibido';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('plataformas.aws.eventos.consultar');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    if ($id > 0) {
        handleGetOne($pdo, $id);
    } else {
        handleList($pdo, $_GET);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function handleList(PDO $pdo, array $q): void {
    $uuid    = trim((string)($q['uuid']    ?? ''));
    $tipo    = trim((string)($q['tipo']    ?? ''));
    $destino = trim((string)($q['destino'] ?? ''));
    $desde   = trim((string)($q['desde']   ?? ''));
    $hasta   = trim((string)($q['hasta']   ?? ''));
    $search  = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'recibido', 'tipo', 'destino', 'uuid'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];
    if ($uuid    !== '') { $where[] = 'uuid = :uuid';                 $params[':uuid']    = $uuid; }
    if ($tipo    !== '') { $where[] = 'tipo = :tipo';                 $params[':tipo']    = $tipo; }
    if ($destino !== '') { $where[] = 'destino LIKE :destino';        $params[':destino'] = "%{$destino}%"; }
    if ($desde   !== '') { $where[] = 'recibido >= :desde';           $params[':desde']   = $desde . ' 00:00:00'; }
    if ($hasta   !== '') { $where[] = 'recibido <= :hasta';           $params[':hasta']   = $hasta . ' 23:59:59'; }
    if ($search  !== '') {
        $where[] = '(uuid LIKE :s1 OR destino LIKE :s2 OR subtipo LIKE :s3)';
        $like    = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                          AS total,
            SUM(CASE WHEN tipo = 'delivery'  THEN 1 ELSE 0 END)               AS entregados,
            SUM(CASE WHEN tipo = 'bounce'    THEN 1 ELSE 0 END)               AS rebotados,
            SUM(CASE WHEN tipo = 'complaint' THEN 1 ELSE 0 END)               AS quejas,
            SUM(CASE WHEN tipo = 'open'      THEN 1 ELSE 0 END)               AS aperturas
        FROM aws_eventos
    ")->fetch();

    $sql = 'SELECT ' . AWS_EVT_COLS
         . ' FROM aws_eventos '
         . $sqlWhere
         . ' ORDER BY ' . $orderBy . ' ' . $dirSql
         . ' LIMIT ' . $limite;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'entregados'  => (int)($stats['entregados']  ?? 0),
            'rebotados'   => (int)($stats['rebotados']   ?? 0),
            'quejas'      => (int)($stats['quejas']      ?? 0),
            'aperturas'   => (int)($stats['aperturas']   ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT ' . AWS_EVT_COLS_DETAIL . ' FROM aws_eventos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Evento no encontrado', 404);
    jsonOk($row);
}
