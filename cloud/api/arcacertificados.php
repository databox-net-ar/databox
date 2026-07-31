<?php
// api/arcacertificados.php
// ABM de certificados fiscales de Arca/AFIP. Lee/escribe sobre la tabla
// `arca_certificados` definida en db/schema.sql. Cada fila agrupa el
// certificado X.509 (PEM), la clave privada RSA y el token de acceso de un
// contribuyente/CUIT contra los webservices de AFIP.
//
//   GET    api/arcacertificados.php          -> listado con filtros (query string)
//   GET    api/arcacertificados.php?id=N     -> registro individual
//   POST   api/arcacertificados.php          -> alta (JSON body)
//   PUT    api/arcacertificados.php?id=N     -> modificacion (JSON body)
//   DELETE api/arcacertificados.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const ARCA_CERT_COLS = "id, nombre, llave, certificado, token, actualizado";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.arca.certificados');
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
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = isset($q['codigo']) && $q['codigo'] !== '' ? (int)$q['codigo'] : null;
    $search = trim((string)($q['q'] ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'actualizado'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 'id = :codigo'; $params[':codigo'] = $codigo; }

    if ($search !== '') {
        $where[] = 'nombre LIKE :s1';
        $params[':s1'] = "%{$search}%";
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT COUNT(*) AS total
        FROM arca_certificados
    ")->fetch();

    $sql = "
        SELECT " . ARCA_CERT_COLS . "
        FROM arca_certificados
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizarFilaArcaCert', $stmt->fetchAll());

    jsonOk([
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . ARCA_CERT_COLS . " FROM arca_certificados WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Certificado no encontrado', 404);
    jsonOk(normalizarFilaArcaCert($row));
}

function normalizarFilaArcaCert(array $r): array {
    return [
        'id'          => (int)($r['id'] ?? 0),
        'nombre'      => $r['nombre']      !== null ? (string)$r['nombre']      : null,
        'llave'       => $r['llave']       !== null ? (string)$r['llave']       : null,
        'certificado' => $r['certificado'] !== null ? (string)$r['certificado'] : null,
        'token'       => $r['token']       !== null ? (string)$r['token']       : null,
        'actualizado' => $r['actualizado'] ?? null,
    ];
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = mb_substr($s, 0, $max);
    return $s;
}

function nullableText(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function sanitizePayload(array $in): array {
    return [
        'nombre'      => nullableStr($in['nombre'] ?? null, 255),
        'llave'       => nullableText($in['llave']       ?? null),
        'certificado' => nullableText($in['certificado'] ?? null),
        'token'       => nullableText($in['token']       ?? null),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    $sql = "
        INSERT INTO arca_certificados
            (nombre, llave, certificado, token, actualizado)
        VALUES
            (:nombre, :llave, :certificado, :token, NOW())
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'      => $p['nombre'],
        ':llave'       => $p['llave'],
        ':certificado' => $p['certificado'],
        ':token'       => $p['token'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM arca_certificados WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Certificado no encontrado', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE arca_certificados SET
            nombre      = :nombre,
            llave       = :llave,
            certificado = :certificado,
            token       = :token,
            actualizado = NOW()
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'      => $p['nombre'],
        ':llave'       => $p['llave'],
        ':certificado' => $p['certificado'],
        ':token'       => $p['token'],
        ':id'          => $id,
    ]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM arca_certificados WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Certificado no encontrado', 404);
    jsonOk(['id' => $id]);
}
