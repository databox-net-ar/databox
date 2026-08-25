<?php
// api/claro_sims.php
// ABM del catalogo de SIMs M2M administradas via Autogestion Empresas (Claro).
// Lee/escribe sobre la tabla `claro_sims` definida en db/schema.sql.
//   GET    api/claro_sims.php          -> listado con filtros (query string)
//   GET    api/claro_sims.php?id=N     -> registro individual
//   POST   api/claro_sims.php          -> alta (JSON body)
//   PUT    api/claro_sims.php?id=N     -> modificacion (JSON body)
//   DELETE api/claro_sims.php?id=N     -> baja
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

// Columnas expuestas por la API. Van calificadas con el alias `s` porque tanto
// el listado como el detalle hacen LEFT JOIN contra `proyectos` para resolver
// el nombre del proyecto asignado (`claro_sims.proyecto` guarda solo el id).
// Sin el prefijo, `nombre` y `id` quedarian ambiguos entre las dos tablas.
const CSIM_COLS = "s.id, s.proyecto, p.nombre AS proyecto_nombre, s.nombre, s.alias, s.linea, s.icc, s.estado, s.estado_gprs, s.estado_lte, s.limite_datos, s.consumo_datos, s.imei, s.msisdn, s.en_uso, s.actualizado, s.ultimo_trafico, s.tags";
const CSIM_FROM = "FROM claro_sims s LEFT JOIN proyectos p ON p.id = s.proyecto";

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermCrud('plataformas.claro.sims');
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
    $codigo   = isset($q['codigo'])   && $q['codigo']   !== '' ? (int)$q['codigo']   : null;
    $proyecto = isset($q['proyecto']) && $q['proyecto'] !== '' ? (int)$q['proyecto'] : null;
    $estado = trim((string)($q['estado'] ?? ''));
    $nombre = trim((string)($q['nombre'] ?? ''));
    $linea  = trim((string)($q['linea']  ?? ''));
    $imei   = trim((string)($q['imei']   ?? ''));
    $enUso  = trim((string)($q['en_uso'] ?? ''));
    $search = trim((string)($q['q']      ?? ''));

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string)($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int)$q['limite'] : 100;
    if ($limite < 1)    $limite = 1;
    if ($limite > 2000) $limite = 2000;

    // limite_datos / consumo_datos son VARCHAR con formato "N MB" — ordenar
    // alfabeticamente ("100 MB" < "20 MB") es ilegible. Casteamos los digitos
    // a UNSIGNED para tener sort numerico. REGEXP_REPLACE existe en MySQL 8
    // y MariaDB 10.11 (los dos entornos del proyecto).
    // `proyecto` ordena por el nombre del proyecto (no por el id crudo, que no
    // le dice nada al usuario).
    $orderMap = [
        'id'             => 's.id',
        'proyecto'       => 'p.nombre',
        'nombre'         => 's.nombre',
        'linea'          => 's.linea',
        'icc'            => 's.icc',
        'imei'           => 's.imei',
        'estado'         => 's.estado',
        'msisdn'         => 's.msisdn',
        'actualizado'    => 's.actualizado',
        'ultimo_trafico' => 's.ultimo_trafico',
        'limite_datos'   => "CAST(REGEXP_REPLACE(COALESCE(s.limite_datos,  ''), '[^0-9]', '') AS UNSIGNED)",
        'consumo_datos'  => "CAST(REGEXP_REPLACE(COALESCE(s.consumo_datos, ''), '[^0-9]', '') AS UNSIGNED)",
    ];
    if (!isset($orderMap[$orderBy])) $orderBy = 'id';
    $orderExpr = $orderMap[$orderBy];
    $dirSql    = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) { $where[] = 's.id = :codigo';        $params[':codigo'] = $codigo; }
    if ($estado !== '')   { $where[] = 's.estado = :estado';    $params[':estado'] = $estado; }
    if ($nombre !== '')   { $where[] = 's.nombre LIKE :nombre'; $params[':nombre'] = "%{$nombre}%"; }
    if ($linea  !== '')   { $where[] = 's.linea LIKE :linea';   $params[':linea']  = "%{$linea}%"; }
    if ($imei   !== '')   { $where[] = 's.imei LIKE :imei';     $params[':imei']   = "%{$imei}%"; }

    // proyecto admite: <id> | '0' (sin asignar) | '' (todos).
    if ($proyecto === 0)        { $where[] = 's.proyecto IS NULL'; }
    elseif ($proyecto !== null) { $where[] = 's.proyecto = :proyecto'; $params[':proyecto'] = $proyecto; }

    // en_uso admite: 'si' | 'no' | 'null' (sin definir) | '' (todos).
    if ($enUso === 'null')          { $where[] = "(s.en_uso IS NULL OR s.en_uso = '')"; }
    elseif (in_array($enUso, ['si', 'no'], true)) { $where[] = 's.en_uso = :en_uso'; $params[':en_uso'] = $enUso; }

    if ($search !== '') {
        // PDO con ATTR_EMULATE_PREPARES=false no permite reusar el mismo
        // placeholder para varias columnas — hay que bindear uno por columna.
        // `tags` es un JSON array serializado (["a","b"]); un LIKE sobre el
        // string crudo es suficiente para el buscador rapido.
        $where[] = '(s.nombre LIKE :s_nombre OR s.alias LIKE :s_alias OR s.linea LIKE :s_linea OR s.icc LIKE :s_icc OR s.tags LIKE :s_tags)';
        $like = "%{$search}%";
        $params[':s_nombre'] = $like;
        $params[':s_alias']  = $like;
        $params[':s_linea']  = $like;
        $params[':s_icc']    = $like;
        $params[':s_tags']   = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = $pdo->query("
        SELECT
            COUNT(*)                                                                  AS total,
            SUM(CASE WHEN LOWER(estado) IN ('activada','activa','active') THEN 1 END) AS activas,
            SUM(CASE WHEN estado IS NULL OR estado = '' THEN 1 END)                   AS sin_estado,
            SUM(CASE WHEN en_uso = 'si' THEN 1 END)                                   AS en_uso,
            MAX(actualizado)                                                          AS ultima_sync
        FROM claro_sims
    ")->fetch();

    // Lista de estados distintos que hoy tienen las SIMs, para poblar el
    // <select> del modal de Filtros (asi el usuario solo puede elegir valores
    // que realmente existen en la BD, sin tener que memorizar la nomenclatura
    // exacta que devuelve el portal de Claro).
    $estados = $pdo->query("
        SELECT DISTINCT estado
        FROM claro_sims
        WHERE estado IS NOT NULL AND estado <> ''
        ORDER BY estado
    ")->fetchAll(PDO::FETCH_COLUMN);

    $sql = "
        SELECT " . CSIM_COLS . "
        " . CSIM_FROM . "
        {$sqlWhere}
        ORDER BY {$orderExpr} {$dirSql}
        LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['tags'] = csimDecodeTags($row['tags'] ?? null);
    unset($row);

    jsonOk([
        'stats' => [
            'total'       => (int)($stats['total']      ?? 0),
            'activas'     => (int)($stats['activas']    ?? 0),
            'sin_estado'  => (int)($stats['sin_estado'] ?? 0),
            'en_uso'      => (int)($stats['en_uso']     ?? 0),
            'ultima_sync' => $stats['ultima_sync']      ?? null,
            'estados'     => $estados,
        ],
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("SELECT " . CSIM_COLS . " " . CSIM_FROM . " WHERE s.id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('SIM no encontrada', 404);
    $row['tags'] = csimDecodeTags($row['tags'] ?? null);
    jsonOk($row);
}

// `tags` se persiste como CSV plano en VARCHAR(500): `etiqueta1,etiqueta2,...`,
// sin comillas ni corchetes, para que la columna sea legible desde cualquier
// cliente SQL. La API sigue exponiendo/consumiendo el array de strings (mas
// natural para el frontend, que renderiza pills). Estos helpers traducen entre
// ambas representaciones. La coma es reservada como separador — el editor de
// tags del frontend ya la usa como "confirmar tag actual", y el normalizador
// backend divide por coma como red de seguridad si llegase colada.
function csimDecodeTags(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    // Compat con filas guardadas antes del cambio (JSON array).
    $arr = str_starts_with(ltrim($raw), '[')
        ? (json_decode($raw, true) ?: [])
        : explode(',', $raw);
    $out = [];
    foreach ($arr as $t) {
        if (!is_string($t)) continue;
        $t = trim($t);
        if ($t === '' || in_array($t, $out, true)) continue;
        $out[] = $t;
    }
    return $out;
}

// Normaliza el array de tags recibido en el payload y lo devuelve listo para
// guardar. Limpia (trim + colapso de whitespace), deduplica, splitea internos
// por coma (por si un item trae varios pegados), tope de 20 tags y 50 chars
// por tag. Devuelve false si el tipo es invalido, null si no se mando el
// campo, o el array normalizado (posiblemente vacio).
function csimNormalizeTags(mixed $in): array|false|null {
    if ($in === null) return null;
    if (!is_array($in)) return false;
    $out = [];
    foreach ($in as $raw) {
        if (!is_string($raw)) continue;
        foreach (explode(',', $raw) as $t) {
            $t = preg_replace('/\s+/u', ' ', trim($t));
            if ($t === '') continue;
            if (mb_strlen($t) > 50) $t = mb_substr($t, 0, 50);
            if (in_array($t, $out, true)) continue;
            $out[] = $t;
            if (count($out) >= 20) break 2;
        }
    }
    return $out;
}
function csimEncodeTags(array $tags): ?string {
    if (!$tags) return null;
    return implode(',', $tags);
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function nullableStr(mixed $v, int $max): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

// `proyecto` guarda un `proyectos.id`. El frontend manda '' cuando el combo
// esta en "sin proyecto"; cualquier valor no positivo se normaliza a NULL.
function nullableId(mixed $v): ?int {
    if ($v === null) return null;
    if (is_string($v) && trim($v) === '') return null;
    $n = (int)$v;
    return $n > 0 ? $n : null;
}

function sanitizePayload(array $in): array {
    return [
        'proyecto'     => nullableId($in['proyecto']     ?? null),
        'nombre'       => nullableStr($in['nombre']       ?? null, 255),
        'linea'        => nullableStr($in['linea']        ?? null, 30),
        'icc'          => nullableStr($in['icc']          ?? null, 25),
        'estado'       => nullableStr($in['estado']       ?? null, 40),
        'estado_gprs'  => nullableStr($in['estado_gprs']  ?? null, 40),
        'estado_lte'   => nullableStr($in['estado_lte']   ?? null, 40),
        'limite_datos' => nullableStr($in['limite_datos'] ?? null, 40),
        'imei'         => nullableStr($in['imei']         ?? null, 30),
        'msisdn'       => nullableStr($in['msisdn']       ?? null, 30),
    ];
}

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);

    $tagsNorm = csimNormalizeTags($in['tags'] ?? null);
    if ($tagsNorm === false) jsonError("'tags' debe ser un array de strings", 422);
    $tagsJson = $tagsNorm !== null ? csimEncodeTags($tagsNorm) : null;

    try {
        $sql = "
            INSERT INTO claro_sims
                (proyecto, nombre, linea, icc, estado, estado_gprs, estado_lte, limite_datos, imei, msisdn, tags)
            VALUES
                (:proyecto, :nombre, :linea, :icc, :estado, :estado_gprs, :estado_lte, :limite_datos, :imei, :msisdn, :tags)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':proyecto'     => $p['proyecto'],
            ':nombre'       => $p['nombre'],
            ':linea'        => $p['linea'],
            ':icc'          => $p['icc'],
            ':estado'       => $p['estado'],
            ':estado_gprs'  => $p['estado_gprs'],
            ':estado_lte'   => $p['estado_lte'],
            ':limite_datos' => $p['limite_datos'],
            ':imei'         => $p['imei'],
            ':msisdn'       => $p['msisdn'],
            ':tags'         => $tagsJson,
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) jsonError('Ya existe una SIM con ese ICC', 409);
        throw $e;
    }

    jsonOk(['id' => (int)$pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM claro_sims WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('SIM no encontrada', 404);

    // Update parcial: solo se tocan las columnas presentes en el payload.
    // Campos editables desde el ABM:
    //   - `proyecto` -> modal Editar (combo de proyectos internos, tipo = 'I')
    //   - `nombre`  -> modal Editar
    //   - `tags`    -> modal Editar (input tipo pills, se serializa como JSON)
    //   - `en_uso`  -> menu contextual del listado ('si' | 'no' | null)
    // El resto (alias, linea, icc, estado*, limite_datos, consumo_datos, imei,
    // msisdn) lo sobreescribe el sync de openclaw y no se acepta aca.
    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('proyecto', $in)) {
        $sets[] = 'proyecto = :proyecto';
        $params[':proyecto'] = nullableId($in['proyecto']);
    }
    if (array_key_exists('nombre', $in)) {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = nullableStr($in['nombre'], 255);
    }
    if (array_key_exists('tags', $in)) {
        $tagsNorm = csimNormalizeTags($in['tags']);
        if ($tagsNorm === false) jsonError("'tags' debe ser un array de strings", 422);
        $sets[] = 'tags = :tags';
        $params[':tags'] = $tagsNorm !== null ? csimEncodeTags($tagsNorm) : null;
    }
    if (array_key_exists('en_uso', $in)) {
        $v = $in['en_uso'];
        if ($v !== null && !in_array($v, ['si', 'no'], true)) {
            jsonError("Valor invalido para 'en_uso' (esperado 'si', 'no' o null)", 422);
        }
        $sets[] = 'en_uso = :en_uso';
        $params[':en_uso'] = $v;
    }

    if ($sets) {
        $sql  = 'UPDATE claro_sims SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('DELETE FROM claro_sims WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('SIM no encontrada', 404);
    jsonOk(['id' => $id]);
}
