<?php
// api/estados.php
// Editor del catalogo de estados. Lee/escribe sobre la tabla `estados`
// definida en db/schema.sql (id / campo / texto / valor / orden), compartida
// con otras apps del grupo. Cada fila mapea un `valor` crudo guardado en la
// columna `<campo>` (con formato `tabla.columna`) con su `texto` amigable y
// un `orden` opcional para listarlo en combos.
//
// No agrega UNIQUE en DB: la unicidad de (campo, valor) se enforce en codigo
// via SELECT antes de INSERT/UPDATE, igual que `parametros`.
//
//   GET    api/estados.php          -> listado con filtros (query string)
//   GET    api/estados.php?id=N     -> registro individual
//   POST   api/estados.php          -> alta (JSON body)
//   PUT    api/estados.php?id=N     -> modificacion (JSON body)
//   DELETE api/estados.php?id=N     -> baja
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
        'id'    => (int)($r['id'] ?? 0),
        'campo' => (string)($r['campo'] ?? ''),
        'texto' => (string)($r['texto'] ?? ''),
        'valor' => (string)($r['valor'] ?? ''),
        'orden' => $r['orden'] !== null ? (int)$r['orden'] : null,
    ];
}

function sanitizePayload(array $in): array {
    $campo = trim((string)($in['campo'] ?? ''));
    $texto = trim((string)($in['texto'] ?? ''));
    $valor = (string)($in['valor'] ?? ''); // valor NO se trimea: puede ser '0' o ' '
    $ordenRaw = $in['orden'] ?? null;
    $orden = ($ordenRaw === '' || $ordenRaw === null) ? null : (int)$ordenRaw;

    if ($campo === '') {
        jsonError('El campo es obligatorio.', 400);
    }
    if (strlen($campo) > 255) {
        jsonError('El campo no puede superar los 255 caracteres.', 400);
    }
    // Formato `tabla.columna` o similar: letras, numeros, punto, guion, guion bajo.
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $campo)) {
        jsonError('El campo solo admite letras, numeros, punto, guion y guion bajo (ej. tabla.columna).', 400);
    }
    if ($texto === '') {
        jsonError('El texto es obligatorio.', 400);
    }
    if (strlen($texto) > 255) {
        jsonError('El texto no puede superar los 255 caracteres.', 400);
    }
    if (strlen($valor) > 255) {
        jsonError('El valor no puede superar los 255 caracteres.', 400);
    }

    return [
        'campo' => $campo,
        'texto' => $texto,
        'valor' => $valor,
        'orden' => $orden,
    ];
}

// Devuelve true si ya existe otra fila con el mismo (campo, valor). El
// parametro $exceptId permite excluir el propio registro durante una edicion.
function parYaExiste(PDO $pdo, string $campo, string $valor, int $exceptId = 0): bool {
    $sql = 'SELECT id FROM estados WHERE campo = :c AND valor = :v';
    if ($exceptId > 0) $sql .= ' AND id <> :exc';
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':c', $campo);
    $stmt->bindValue(':v', $valor);
    if ($exceptId > 0) $stmt->bindValue(':exc', $exceptId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetch();
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $campo  = trim((string)($q['campo'] ?? ''));
    $search = trim((string)($q['q']     ?? ''));

    $orderBy = $q['order_by'] ?? 'campo_orden';
    $dir     = strtolower((string)($q['dir'] ?? 'asc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 500;
    if ($limite < 1 || $limite > 5000) $limite = 500;

    $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';

    // 'campo_orden' es el orden natural para combos: primero por campo,
    // dentro de cada campo por su `orden` y, en empate, por id.
    $allowedOrder = ['id', 'campo', 'texto', 'valor', 'orden', 'campo_orden'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'campo_orden';

    if ($orderBy === 'campo_orden') {
        $orderSql = "campo {$dirSql}, COALESCE(orden, 0) {$dirSql}, id {$dirSql}";
    } else {
        $orderSql = "{$orderBy} {$dirSql}";
    }

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($campo !== '') {
        $where[] = 'campo = :campoExact';
        $params[':campoExact'] = $campo;
    }
    if ($search !== '') {
        // PDO con emulate_prepares=false no permite reusar el mismo placeholder
        // nombrado en varias posiciones -- hay que duplicar el bind.
        $where[] = '(campo LIKE :s1 OR texto LIKE :s2 OR valor LIKE :s3)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $total = (int)$pdo->query('SELECT COUNT(*) FROM estados')->fetchColumn();

    $sql = "
        SELECT id, campo, texto, valor, orden
        FROM estados
        {$sqlWhere}
        ORDER BY {$orderSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizarFila', $stmt->fetchAll());

    // Lista de `campo` distintos para alimentar el combo del filtro.
    $campos = $pdo->query('SELECT DISTINCT campo FROM estados WHERE campo IS NOT NULL AND campo <> "" ORDER BY campo')
                  ->fetchAll(PDO::FETCH_COLUMN);

    jsonOk([
        'stats'  => ['total' => $total],
        'campos' => $campos,
        'items'  => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, campo, texto, valor, orden FROM estados WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Estado no encontrado', 404);
    jsonOk(normalizarFila($row));
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if (parYaExiste($pdo, $p['campo'], $p['valor'])) {
        jsonError('Ya existe un estado con ese valor para ese campo.', 409);
    }
    $stmt = $pdo->prepare('
        INSERT INTO estados (campo, texto, valor, orden)
        VALUES (:campo, :texto, :valor, :orden)
    ');
    $stmt->bindValue(':campo', $p['campo']);
    $stmt->bindValue(':texto', $p['texto']);
    $stmt->bindValue(':valor', $p['valor']);
    if ($p['orden'] === null) {
        $stmt->bindValue(':orden', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':orden', $p['orden'], PDO::PARAM_INT);
    }
    $stmt->execute();
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM estados WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Estado no encontrado', 404);

    $p = sanitizePayload($in);
    if (parYaExiste($pdo, $p['campo'], $p['valor'], $id)) {
        jsonError('Ya existe un estado con ese valor para ese campo.', 409);
    }
    $stmt = $pdo->prepare('
        UPDATE estados
           SET campo = :campo, texto = :texto, valor = :valor, orden = :orden
         WHERE id = :id
    ');
    $stmt->bindValue(':campo', $p['campo']);
    $stmt->bindValue(':texto', $p['texto']);
    $stmt->bindValue(':valor', $p['valor']);
    if ($p['orden'] === null) {
        $stmt->bindValue(':orden', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':orden', $p['orden'], PDO::PARAM_INT);
    }
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM estados WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Estado no encontrado', 404);
    jsonOk(['id' => $id]);
}
