<?php
// api/datarocketplantillas.php
// ABM de plantillas Datarocket. Lee/escribe sobre la tabla `datarocket_plantillas`
// definida en db/schema.sql.
//   GET    api/datarocketplantillas.php          -> listado con filtros (query string)
//   GET    api/datarocketplantillas.php?id=N     -> registro individual
//   POST   api/datarocketplantillas.php          -> alta (JSON body)
//   PUT    api/datarocketplantillas.php?id=N     -> modificacion (JSON body)
//   DELETE api/datarocketplantillas.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';

const DR_PL_COLS = "id, slug, nombre, proyecto_id, medio, remitente, remite,
                    asunto, cuerpo, formato, adjunto, adjunto_origen";

// Prefijo bajo el cual se almacenan los archivos adjuntos subidos desde el ABM.
// Cualquier `adjunto` cuya URL apunte a este prefijo dentro del bucket se
// considera "propiedad" de la plantilla, y se elimina cuando la plantilla se
// elimina o el usuario reemplaza el archivo por otro (o por una URL externa).
const DR_PL_ADJUNTO_PREFIX = 'datarocket/plantillas/';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('sistemas.datarocket.plantillas');
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
    $codigo     = isset($q['codigo'])      && $q['codigo']      !== '' ? (int)$q['codigo']      : null;
    $proyectoId = isset($q['proyecto_id']) && $q['proyecto_id'] !== '' ? (int)$q['proyecto_id'] : null;
    $medio      = trim((string)($q['medio']   ?? ''));
    $formato    = trim((string)($q['formato'] ?? ''));
    $search     = trim((string)($q['q']       ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 1000) $limite = 1000;

    $allowedOrder = ['id', 'nombre', 'proyecto_id', 'medio', 'remitente',
                     'asunto', 'formato'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo     !== null) { $where[] = 'id = :codigo';                 $params[':codigo']      = $codigo; }
    if ($proyectoId !== null) { $where[] = 'proyecto_id = :proyecto_id';   $params[':proyecto_id'] = $proyectoId; }
    if ($medio      !== '')   { $where[] = 'medio = :medio';               $params[':medio']       = $medio; }
    if ($formato    !== '')   { $where[] = 'formato = :formato';           $params[':formato']     = $formato; }

    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR asunto LIKE :s2 OR remitente LIKE :s3
                     OR remite LIKE :s4 OR slug LIKE :s5)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
        $params[':s5'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total,
            SUM(CASE WHEN adjunto IS NOT NULL AND adjunto <> '' THEN 1 ELSE 0 END) AS con_adjunto
        FROM datarocket_plantillas
    ")->fetch();

    $sql = "
        SELECT " . DR_PL_COLS . "
        FROM datarocket_plantillas
        {$sqlWhere}
        ORDER BY {$orderBy} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']       ?? 0),
            'con_adjunto' => (int)($stats['con_adjunto'] ?? 0),
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . DR_PL_COLS . " FROM datarocket_plantillas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Plantilla no encontrada', 404);
    jsonOk($row);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function nullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function sanitizePayload(array $in): array {
    $adjunto = nullableStr($in['adjunto'] ?? null, 500);
    // `adjunto_origen` se deriva del propio adjunto: si la URL cae bajo el
    // prefijo del bucket administrado por este ABM => 'archivo', si tiene
    // cualquier otro valor no vacio => 'url', si esta vacio => NULL.
    // No se confia en lo que manda el frontend para este flag: la fuente de
    // verdad es la forma de la URL.
    $origen = null;
    if ($adjunto !== null && $adjunto !== '') {
        $origen = drPlOwnedS3Key($adjunto) !== null ? 'archivo' : 'url';
    }
    return [
        'nombre'         => nullableStr($in['nombre']      ?? null, 100),
        'proyecto_id'    => nullableInt($in['proyecto_id'] ?? null),
        'medio'          => nullableStr($in['medio']       ?? null, 1),
        'remitente'      => nullableStr($in['remitente']   ?? null, 255),
        'remite'         => nullableStr($in['remite']      ?? null, 255),
        'asunto'         => nullableStr($in['asunto']      ?? null, 255),
        // `cuerpo` es mediumtext: no cortamos por longitud.
        'cuerpo'         => nullableStr($in['cuerpo']      ?? null),
        'formato'        => nullableStr($in['formato']     ?? null, 20),
        'adjunto'        => $adjunto,
        'adjunto_origen' => $origen,
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    // `slug` es varchar(10) (heredado del `uuid` de la tabla vieja).
    $p['slug'] = nullableStr($in['slug'] ?? null, 10) ?? substr(bin2hex(random_bytes(5)), 0, 10);

    $sql = "
        INSERT INTO datarocket_plantillas
            (slug, nombre, proyecto_id, medio, remitente, remite,
             asunto, cuerpo, formato, adjunto, adjunto_origen)
        VALUES
            (:slug, :nombre, :proyecto_id, :medio, :remitente, :remite,
             :asunto, :cuerpo, :formato, :adjunto, :adjunto_origen)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':slug'           => $p['slug'],
        ':nombre'         => $p['nombre'],
        ':proyecto_id'    => $p['proyecto_id'],
        ':medio'          => $p['medio'],
        ':remitente'      => $p['remitente'],
        ':remite'         => $p['remite'],
        ':asunto'         => $p['asunto'],
        ':cuerpo'         => $p['cuerpo'],
        ':formato'        => $p['formato'],
        ':adjunto'        => $p['adjunto'],
        ':adjunto_origen' => $p['adjunto_origen'],
    ]);
    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT adjunto FROM datarocket_plantillas WHERE id = :id');
    $exists->execute([':id' => $id]);
    $prev = $exists->fetch();
    if (!$prev) jsonError('Plantilla no encontrada', 404);

    $p = sanitizePayload($in);

    $sql = "
        UPDATE datarocket_plantillas SET
            nombre         = :nombre,
            proyecto_id    = :proyecto_id,
            medio          = :medio,
            remitente      = :remitente,
            remite         = :remite,
            asunto         = :asunto,
            cuerpo         = :cuerpo,
            formato        = :formato,
            adjunto        = :adjunto,
            adjunto_origen = :adjunto_origen
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'         => $p['nombre'],
        ':proyecto_id'    => $p['proyecto_id'],
        ':medio'          => $p['medio'],
        ':remitente'      => $p['remitente'],
        ':remite'         => $p['remite'],
        ':asunto'         => $p['asunto'],
        ':cuerpo'         => $p['cuerpo'],
        ':formato'        => $p['formato'],
        ':adjunto'        => $p['adjunto'],
        ':adjunto_origen' => $p['adjunto_origen'],
        ':id'             => $id,
    ]);
    // Si la plantilla tenia un archivo propio (subido a S3 bajo el prefijo
    // datarocket/plantillas/) y el usuario lo reemplazo por otro valor
    // (otra URL, otro archivo, o vacio), borrar el archivo huerfano.
    $prevKey = drPlOwnedS3Key($prev['adjunto'] ?? null);
    if ($prevKey !== null && $prevKey !== drPlOwnedS3Key($p['adjunto'])) {
        drPlBorrarAdjuntoS3($prevKey);
    }
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    // Leer el adjunto ANTES de borrar la fila: si apunta a un archivo propio
    // (subido a S3 bajo datarocket/plantillas/) hay que eliminarlo del bucket.
    $sel = $pdo->prepare('SELECT adjunto FROM datarocket_plantillas WHERE id = :id');
    $sel->execute([':id' => $id]);
    $row = $sel->fetch();

    $stmt = $pdo->prepare('DELETE FROM datarocket_plantillas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Plantilla no encontrada', 404);

    if ($row) {
        $key = drPlOwnedS3Key($row['adjunto'] ?? null);
        if ($key !== null) drPlBorrarAdjuntoS3($key);
    }
    jsonOk(['id' => $id]);
}

// Si $url apunta a un objeto del bucket bajo DR_PL_ADJUNTO_PREFIX, devuelve la
// key S3 (sin bucket). Si es una URL externa, o vacio, o no coincide, devuelve
// null. Usado para decidir cuando el ABM "es dueño" del archivo y debe
// eliminarlo del bucket al borrar/reemplazar la plantilla.
function drPlOwnedS3Key(?string $url): ?string {
    if ($url === null || $url === '') return null;
    $bucket = s3_bucket_name();
    // Formato path-style: https://s3.<region>.amazonaws.com/<bucket>/<key>
    $marker = '/' . $bucket . '/' . DR_PL_ADJUNTO_PREFIX;
    $pos = strpos($url, $marker);
    if ($pos === false) return null;
    $encoded = substr($url, $pos + strlen('/' . $bucket . '/'));
    // Quitar query string / fragment por si el link vino con parametros.
    $encoded = strtok($encoded, '?#');
    if ($encoded === false || $encoded === '') return null;
    // Los segmentos vienen rawurlencoded (ver s3_uri_encode); decodificarlos.
    $key = implode('/', array_map('rawurldecode', explode('/', $encoded)));
    // Guardia: solo devolver keys que efectivamente caen bajo el prefijo del ABM.
    return strpos($key, DR_PL_ADJUNTO_PREFIX) === 0 ? $key : null;
}

// Intenta borrar $key del bucket. Errores se silencian a proposito: el borrado
// del archivo es best-effort, no debe romper la respuesta OK del DELETE/PUT
// de la plantilla (el usuario ya vio la accion como aplicada). Si el archivo
// quedo huerfano se puede limpiar despues desde Herramientas > Explorador S3.
function drPlBorrarAdjuntoS3(string $key): void {
    try { s3_delete_object($key); } catch (Throwable $e) { /* best-effort */ }
}
