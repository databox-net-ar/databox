<?php
// api/arcacertificados.php
// ABM de certificados fiscales de Arca/AFIP. Lee/escribe sobre la tabla
// `arca_certificados` definida en db/schema.sql. Cada fila agrupa el
// certificado X.509 (PEM) y la clave privada RSA de un contribuyente para
// autenticar contra los webservices de AFIP (WSAA/WSFEv1).
//
// El listado enriquece cada fila con `vence_en` (fecha de vencimiento del
// X.509 parseada al vuelo con openssl_x509_parse) para que el operador vea
// desde el listado a que cert le queda poco y renovarlo antes de que
// rompa la facturacion.
//
//   GET    api/arcacertificados.php          -> listado con filtros (query string)
//   GET    api/arcacertificados.php?id=N     -> registro individual
//   POST   api/arcacertificados.php          -> alta (JSON body)
//   PUT    api/arcacertificados.php?id=N     -> modificacion (JSON body)
//   DELETE api/arcacertificados.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

const ARCA_CERT_COLS = "id, nombre, llave, certificado, actualizado";

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $action = (string)($_GET['action'] ?? '');

    // La accion `invalidar_ta_cache` NO usa el permiso CRUD (agregar) -- purgar
    // los tickets WSAA de un certificado es un verbo propio, distinto de dar de
    // alta un certificado nuevo. Permiso: `plataformas.arca.certificados.invalidar_ta_cache`.
    if ($method === 'POST' && $action === 'invalidar_ta_cache') {
        requirePermission('plataformas.arca.certificados.invalidar_ta_cache');
        if ($id <= 0) jsonError('Falta id', 400);
        handleInvalidarTaCache($pdo, $id);
        exit;
    }

    requirePermCrud('plataformas.arca.certificados');

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
    $cert = $r['certificado'] !== null ? (string)$r['certificado'] : null;
    return [
        'id'          => (int)($r['id'] ?? 0),
        'nombre'      => $r['nombre'] !== null ? (string)$r['nombre'] : null,
        'llave'       => $r['llave']  !== null ? (string)$r['llave']  : null,
        'certificado' => $cert,
        'vence_en'    => arcaCertVenceEn($cert),
        'actualizado' => $r['actualizado'] ?? null,
    ];
}

// Parsea el PEM y devuelve la fecha de vencimiento del X.509 en formato
// 'YYYY-MM-DD HH:MM:SS' (UTC). Si el cert no es parseable, retorna null y
// el frontend lo trata como "sin dato" (no rompe el listado).
function arcaCertVenceEn(?string $pem): ?string {
    if ($pem === null || $pem === '') return null;
    if (!function_exists('openssl_x509_parse')) return null;
    $info = @openssl_x509_parse($pem);
    if (!is_array($info) || !isset($info['validTo_time_t'])) return null;
    $ts = (int)$info['validTo_time_t'];
    if ($ts <= 0) return null;
    return gmdate('Y-m-d H:i:s', $ts);
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
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    $sql = "
        INSERT INTO arca_certificados
            (nombre, llave, certificado, actualizado)
        VALUES
            (:nombre, :llave, :certificado, NOW())
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'      => $p['nombre'],
        ':llave'       => $p['llave'],
        ':certificado' => $p['certificado'],
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
            actualizado = NOW()
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'      => $p['nombre'],
        ':llave'       => $p['llave'],
        ':certificado' => $p['certificado'],
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

// ----------------------------------------------------------------------------
// Invalidar cache de tickets de acceso (WSAA)
// ----------------------------------------------------------------------------

// Borra todas las filas de arca_ta_cache asociadas al certificado. Uso tipico:
// el operador rota o renueva el .crt y quiere forzar que la proxima llamada a
// AFIP negocie un TA nuevo en vez de reutilizar el cacheado (que quedo firmado
// con la llave anterior).
//
// Puede borrar N filas: un certificado puede tener 1 TA por cada `service`
// AFIP (wsfe, wsmtxca, ws_sr_padron_a4, etc.). No es error borrar 0 filas
// (puede no haber TA cacheado todavia); devolvemos el conteo para feedback.
function handleInvalidarTaCache(PDO $pdo, int $id): void {
    $exists = $pdo->prepare('SELECT id FROM arca_certificados WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Certificado no encontrado', 404);

    $stmt = $pdo->prepare('DELETE FROM arca_ta_cache WHERE certificado_id = :id');
    $stmt->execute([':id' => $id]);
    jsonOk(['id' => $id, 'borrados' => $stmt->rowCount()]);
}
