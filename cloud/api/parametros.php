<?php
// api/parametros.php
// Editor de parametros runtime. Lee/escribe sobre la tabla `parametros`
// definida en db/schema.sql (id / variable / valor / comentario), compartida
// con otras apps del grupo. No agrega UNIQUE en DB: la unicidad de `variable`
// se enforce en codigo via SELECT antes de INSERT/UPDATE.
//
//   GET    api/parametros.php          -> listado con filtros (query string)
//   GET    api/parametros.php?id=N     -> registro individual
//   POST   api/parametros.php          -> alta (JSON body)
//   PUT    api/parametros.php?id=N     -> modificacion (JSON body)
//   DELETE api/parametros.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('administracion.herramientas.parametros');
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
// Helpers
// ----------------------------------------------------------------------------

function normalizarFila(array $r): array {
    return [
        'id'         => (int)($r['id'] ?? 0),
        'variable'   => (string)($r['variable']   ?? ''),
        'valor'      => (string)($r['valor']      ?? ''),
        'comentario' => $r['comentario'] !== null ? (string)$r['comentario'] : null,
    ];
}

function sanitizePayload(array $in): array {
    $variable   = trim((string)($in['variable']   ?? ''));
    $valor      = (string)($in['valor']           ?? ''); // valor NO se trimea
    $comentario = trim((string)($in['comentario'] ?? ''));
    if ($comentario === '') $comentario = null;

    if ($variable === '') {
        jsonError('La variable es obligatoria.', 400);
    }
    if (strlen($variable) > 255) {
        jsonError('La variable no puede superar los 255 caracteres.', 400);
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $variable)) {
        jsonError('La variable solo admite letras, numeros, punto, guion y guion bajo.', 400);
    }
    if (strlen($valor) > 255) {
        jsonError('El valor no puede superar los 255 caracteres.', 400);
    }
    if ($comentario !== null && strlen($comentario) > 1024) {
        jsonError('El comentario no puede superar los 1024 caracteres.', 400);
    }

    return [
        'variable'   => $variable,
        'valor'      => $valor,
        'comentario' => $comentario,
    ];
}

// Devuelve true si ya existe otra fila con la misma `variable`. El parametro
// $exceptId permite excluir el propio registro durante una edicion.
function variableYaExiste(PDO $pdo, string $variable, int $exceptId = 0): bool {
    $sql = 'SELECT id FROM parametros WHERE variable = :v';
    if ($exceptId > 0) $sql .= ' AND id <> :exc';
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':v', $variable);
    if ($exceptId > 0) $stmt->bindValue(':exc', $exceptId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetch();
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $search = trim((string)($q['q'] ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1 || $limite > 1000) $limite = 100;

    $allowedOrder = ['id', 'variable'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($search !== '') {
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // nombrado en varias posiciones — hay que duplicar el bind.
        $where[] = '(variable LIKE :s1 OR valor LIKE :s2 OR comentario LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $total = (int)$pdo->query('SELECT COUNT(*) FROM parametros')->fetchColumn();

    $sql = "
        SELECT id, variable, valor, comentario
        FROM parametros
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizarFila', $stmt->fetchAll());

    jsonOk([
        'stats' => ['total' => $total],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, variable, valor, comentario FROM parametros WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Parametro no encontrado', 404);
    jsonOk(normalizarFila($row));
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if (variableYaExiste($pdo, $p['variable'])) {
        jsonError('Ya existe un parametro con esa variable.', 409);
    }
    $stmt = $pdo->prepare('
        INSERT INTO parametros (variable, valor, comentario)
        VALUES (:variable, :valor, :comentario)
    ');
    $stmt->execute([
        ':variable'   => $p['variable'],
        ':valor'      => $p['valor'],
        ':comentario' => $p['comentario'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM parametros WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Parametro no encontrado', 404);

    $p = sanitizePayload($in);
    if (variableYaExiste($pdo, $p['variable'], $id)) {
        jsonError('Ya existe un parametro con esa variable.', 409);
    }
    $stmt = $pdo->prepare('
        UPDATE parametros
           SET variable = :variable, valor = :valor, comentario = :comentario
         WHERE id = :id
    ');
    $stmt->execute([
        ':variable'   => $p['variable'],
        ':valor'      => $p['valor'],
        ':comentario' => $p['comentario'],
        ':id'         => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM parametros WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Parametro no encontrado', 404);
    jsonOk(['id' => $id]);
}
