<?php
// api/sucesos.php
// Visor read-only de la tabla `sucesos` (log de actividad de los distintos
// modulos del panel). Columnas: id / fecha / origen / detalle (text).
// Otros modulos escriben aqui; el panel solo lee.
//
//   GET api/sucesos.php          -> listado con filtros (query string)
//   GET api/sucesos.php?id=N     -> registro individual (detalle completo)
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function normalizarFila(array $r): array {
    return [
        'id'      => (int)($r['id'] ?? 0),
        'fecha'   => $r['fecha']   !== null ? (string)$r['fecha']   : null,
        'origen'  => $r['origen']  !== null ? (string)$r['origen']  : null,
        'detalle' => $r['detalle'] !== null ? (string)$r['detalle'] : null,
    ];
}

function handleList(PDO $pdo, array $q): void {
    $search = trim((string)($q['q']     ?? ''));
    $desde  = trim((string)($q['desde'] ?? ''));
    $hasta  = trim((string)($q['hasta'] ?? ''));
    $limite = isset($q['limite']) ? (int)$q['limite'] : 200;
    if ($limite < 1 || $limite > 2000) $limite = 200;

    $where  = [];
    $params = [];

    if ($search !== '') {
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // nombrado en varias posiciones -- hay que duplicar el bind.
        $where[] = '(origen LIKE :s1 OR detalle LIKE :s2)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
    }
    if ($desde !== '') {
        $where[] = 'fecha >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $where[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $total = (int)$pdo->query('SELECT COUNT(*) FROM sucesos')->fetchColumn();

    $sql = "
        SELECT id, fecha, origen, detalle
        FROM sucesos
        {$sqlWhere}
        ORDER BY id DESC
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizarFila', $stmt->fetchAll());

    jsonOk([
        'stats' => [
            'total'     => $total,
            'mostrados' => count($rows),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, fecha, origen, detalle FROM sucesos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Suceso no encontrado', 404);
    jsonOk(normalizarFila($row));
}
