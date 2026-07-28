<?php
// api/v4/claro/sims.php
// Microservicio de ingesta de SIMs Claro. UPSERT por ICC (idempotente),
// disenado para reemplazar / superar al endpoint openclaw-legacy
// `cloud/api/claro_sims_sync.php` (que solo recibe CSV y toca un subset de
// columnas). Este endpoint acepta JSON con TODAS las columnas de la tabla
// y hace UPSERT en batch respetando la UNIQUE key `uk_claro_sims_icc`.
//
//   POST /v4/claro/sims           (JSON body) -> UPSERT una o varias SIMs
//                                                devuelve stats + items
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto del stack -- ver cloud/api/lib/apikey_auth.php).
//
// Tabla destino: `claro_sims` (schema en db/schema.sql).
//
// Espejo estructural de api/v4/telegram/mensajes.php y api/v4/aws/mensajes.php.
// Cuando se agreguen mas verbos (GET por id/icc, DELETE, PATCH parcial) se
// enganchan en el dispatcher de mas abajo sin tocar el bloque de auth.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
// db() / jsonOk() / jsonError() / readJsonBody() vienen del panel cloud via
// cloud/api/db.php. Aca solo agregamos los helpers propios del microservicio:
// lectura del Bearer y validacion del apikey contra la tabla `aplicaciones`.

// Apache no siempre propaga Authorization a $_SERVER (depende de mod_rewrite
// y CGIPassAuth). Chequeamos $_SERVER, REDIRECT_HTTP_AUTHORIZATION y como
// ultimo recurso getallheaders().
function readBearer(): string {
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION']
                       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                       ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = trim((string)$v); break; }
        }
    }
    return stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
}

function requireApp(): array {
    $token = readBearer();
    if ($token === '') jsonError('Bearer token ausente', 401);

    $pdo = db();
    $st  = $pdo->prepare("SELECT id, nombre, habilitada FROM aplicaciones WHERE apikey = :k LIMIT 1");
    $st->execute([':k' => $token]);
    $app = $st->fetch();
    if (!$app)                              jsonError('API key desconocida', 401);
    if ((string)$app['habilitada'] !== '1') jsonError('Aplicacion deshabilitada', 401);

    // Contador de uso -- best effort, un fallo aca no debe tumbar el request.
    try {
        $pdo->prepare("UPDATE aplicaciones SET usos = COALESCE(usos,0)+1 WHERE id = :id")
            ->execute([':id' => (int)$app['id']]);
    } catch (Throwable) { /* ignore */ }

    return $app;
}

// Columnas UPSERTables desde el microservicio. `id` es AUTO_INCREMENT y no se
// acepta desde afuera; `actualizado` se autopobla con NOW() si no viene.
// Cada valor: [longitud maxima, ¿es datetime?].
// Fuente de verdad: db/schema.sql, tabla `claro_sims`.
const CLARO_SIMS_COLS = [
    'nombre'        => [255, false],
    'alias'         => [100, false],
    'linea'         => [ 30, false],
    'icc'           => [ 25, false], // clave UNIQUE del UPSERT -- obligatoria
    'estado'        => [ 40, false],
    'estado_gprs'   => [ 40, false],
    'estado_lte'    => [ 40, false],
    'limite_datos'  => [ 40, false],
    'consumo_datos' => [ 40, false],
    'imei'          => [ 30, false],
    'msisdn'        => [ 30, false],
    'en_uso'        => [  2, false], // 'si' | 'no' | null
    'actualizado'   => [  0, true ], // datetime -- si viene NULL, se pone NOW()
    'ultimo_trafico'=> [  0, true ], // datetime -- opcional
    'tags'          => [500, false],
];

// ---------------------------------------------------------------------------
// Dispatcher
// ---------------------------------------------------------------------------

try {
    $app    = requireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        handleUpsert($app, readJsonBody());
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// POST /v4/claro/sims  -> UPSERT batch (o single) por ICC
// ---------------------------------------------------------------------------
//
// Body aceptado (los tres son equivalentes):
//   1) Objeto simple:  {"icc":"89...", "estado":"Activada", ...}
//   2) Wrapper:        {"sims": [ {...}, {...}, ... ]}
//   3) Array plano:    [ {...}, {...}, ... ]
//
// El campo `icc` es obligatorio -- es la clave por la que se hace el UPSERT.
// Filas sin icc se cuentan en `sin_icc` y se saltan (no rompen la corrida).
// El resto de las columnas son opcionales: las que no vengan quedan intactas
// en el UPDATE (o NULL en el INSERT).

function handleUpsert(array $app, array $in): void {
    $rows = extractRows($in);
    if (empty($rows)) jsonError('Body vacio: se esperaba un objeto o un array de SIMs', 400);

    $pdo    = db();
    $lookup = $pdo->prepare('SELECT id FROM claro_sims WHERE icc = :icc LIMIT 1');

    $items        = [];
    $insertados   = 0;
    $actualizados = 0;
    $sinIcc       = 0;

    foreach ($rows as $idx => $raw) {
        if (!is_array($raw)) {
            $items[] = ['index' => $idx, 'accion' => 'skip', 'error' => 'Fila no es objeto'];
            continue;
        }
        $p = sanitizeSim($raw);
        if ($p['icc'] === null) {
            $sinIcc++;
            $items[] = ['index' => $idx, 'accion' => 'skip', 'error' => 'ICC vacio'];
            continue;
        }

        $lookup->execute([':icc' => $p['icc']]);
        $existingId = $lookup->fetchColumn();
        $existe     = $existingId !== false;

        try {
            if ($existe) {
                updateSim($pdo, (int)$existingId, $p);
                $actualizados++;
                $items[] = ['index' => $idx, 'accion' => 'actualizado', 'id' => (int)$existingId, 'icc' => $p['icc']];
            } else {
                $newId = insertSim($pdo, $p);
                $insertados++;
                $items[] = ['index' => $idx, 'accion' => 'insertado', 'id' => $newId, 'icc' => $p['icc']];
            }
        } catch (PDOException $e) {
            // Carrera: alguien insertó la misma ICC entre el SELECT y el INSERT.
            // Reintentamos como UPDATE por ICC.
            if (!$existe && ((int)($e->errorInfo[1] ?? 0)) === 1062) {
                $lookup->execute([':icc' => $p['icc']]);
                $racyId = (int)$lookup->fetchColumn();
                if ($racyId > 0) {
                    updateSim($pdo, $racyId, $p);
                    $actualizados++;
                    $items[] = ['index' => $idx, 'accion' => 'actualizado', 'id' => $racyId, 'icc' => $p['icc']];
                    continue;
                }
            }
            throw $e;
        }
    }

    $status = $insertados > 0 ? 201 : 200;
    jsonOk([
        'stats' => [
            'recibidas'    => count($rows),
            'insertados'   => $insertados,
            'actualizados' => $actualizados,
            'sin_icc'      => $sinIcc,
            'aplicacion'   => ['id' => (int)$app['id'], 'nombre' => (string)$app['nombre']],
            'ejecutado_en' => date('Y-m-d H:i:s'),
        ],
        'items' => $items,
    ], $status);
}

// ---------------------------------------------------------------------------
// Extraccion del body a un array de filas
// ---------------------------------------------------------------------------

function extractRows(array $in): array {
    // Caso 1: wrapper {"sims": [...]}.
    if (isset($in['sims']) && is_array($in['sims'])) {
        return array_values($in['sims']);
    }
    // Caso 2: array plano (list-style). PHP 8+: array_is_list.
    if ($in !== [] && array_is_list($in)) {
        return $in;
    }
    // Caso 3: objeto simple -- lo envolvemos en array de 1.
    if ($in !== []) {
        return [$in];
    }
    return [];
}

// ---------------------------------------------------------------------------
// Sanitizacion y UPSERT
// ---------------------------------------------------------------------------

function nullableStr(mixed $v, int $max): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

// Normaliza un datetime aceptando 'YYYY-MM-DD HH:MM[:SS]' o ISO8601.
// Devuelve string 'YYYY-MM-DD HH:MM:SS' o null.
function nullableDatetime(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function sanitizeSim(array $in): array {
    $out = [];
    foreach (CLARO_SIMS_COLS as $col => [$max, $isDatetime]) {
        $out[$col] = $isDatetime
            ? nullableDatetime($in[$col] ?? null)
            : nullableStr($in[$col] ?? null, $max);
    }

    // `en_uso` acepta unicamente 'si' | 'no' | null. Cualquier otro valor -> null
    // (no lo rechazamos con 400 para no tumbar un batch entero por un typo).
    if ($out['en_uso'] !== null && !in_array($out['en_uso'], ['si', 'no'], true)) {
        $out['en_uso'] = null;
    }

    // El portal de Claro exporta la cadena literal "Sin datos" cuando la SIM
    // no reporta IMEI todavia. Ese string no es un IMEI valido — lo
    // convertimos a NULL para no ensuciar la columna. Comparacion trim +
    // case-insensitive por si el scraping cambia el casing.
    if ($out['imei'] !== null && strcasecmp(trim($out['imei']), 'Sin datos') === 0) {
        $out['imei'] = null;
    }

    // Si no viene `actualizado`, dejamos que el INSERT/UPDATE lo ponga en NOW().
    // Se marca aca con la sentinela 'now' porque insertSim/updateSim distinguen
    // "no vino" de "vino NULL explicito" (que preserva el valor).
    if ($out['actualizado'] === null && !array_key_exists('actualizado', $in)) {
        $out['actualizado'] = '__NOW__';
    }

    return $out;
}

function insertSim(PDO $pdo, array $p): int {
    // Solo insertamos las columnas que el caller efectivamente aporto (o el
    // sentinel __NOW__ para `actualizado`). El resto queda NULL por default.
    $cols   = [];
    $vals   = [];
    $params = [];
    foreach ($p as $col => $val) {
        if ($val === null) continue;
        $cols[] = "`{$col}`";
        if ($col === 'actualizado' && $val === '__NOW__') {
            $vals[] = 'NOW()';
        } else {
            $vals[]           = ":{$col}";
            $params[":{$col}"] = $val;
        }
    }
    if ($cols === []) {
        // Caso limite: solo icc = null y el resto null. No deberia pasar
        // porque icc es requerido antes de llegar aca, pero por las dudas.
        throw new RuntimeException('Nada para insertar');
    }
    $sql = 'INSERT INTO claro_sims (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function updateSim(PDO $pdo, int $id, array $p): void {
    // Update parcial: solo se tocan las columnas presentes con valor != null,
    // asi el UPSERT no borra valores existentes cuando el caller manda payload
    // parcial. `actualizado=__NOW__` se traduce a NOW() en SQL.
    $sets   = [];
    $params = [':id' => $id];
    foreach ($p as $col => $val) {
        if ($col === 'icc') continue; // no reasignamos la clave del UPSERT
        if ($val === null)  continue; // preservar valor existente
        if ($col === 'actualizado' && $val === '__NOW__') {
            $sets[] = "`{$col}` = NOW()";
        } else {
            $sets[]           = "`{$col}` = :{$col}";
            $params[":{$col}"] = $val;
        }
    }
    if ($sets === []) return; // nada para actualizar (solo icc) -- no-op
    $sql  = 'UPDATE claro_sims SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
