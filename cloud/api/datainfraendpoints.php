<?php
// api/datainfraendpoints.php
// Endpoints Datainfra (CRUD). Lee/escribe sobre la tabla `datainfra_endpoints`
// definida en db/schema.sql -- catalogo de endpoints HTTP/HTTPS con los que
// Databox integra (propios y de terceros) y sobre los que un job cron
// aparte hace testing periodico de salud (health-check).
//
// Los campos `ultimo_*` (ultimo_check / ultimo_estado / ultimo_codigo /
// ultimo_tiempo_ms / ultimo_error) son READ-ONLY desde este endpoint --
// los actualiza el job cron cuando corre. La UI del ABM los muestra pero
// no los pisa.
//
//   GET    api/datainfraendpoints.php[?q=...&metodo=...&estado=...&activo=si|no&limite=100&orden=id&dir=desc]
//                                      -> listado + stats por estado
//   GET    api/datainfraendpoints.php?id=N
//                                      -> registro individual
//   POST   api/datainfraendpoints.php     -> alta (JSON body)
//   PUT    api/datainfraendpoints.php?id=N
//                                      -> modificacion (JSON body)
//   DELETE api/datainfraendpoints.php?id=N
//                                      -> baja
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const DIEP_METODOS = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'PATCH', 'OPTIONS'];
const DIEP_ESTADOS = ['ok', 'error', 'timeout', 'nunca'];
const DIEP_ORDENES = ['id', 'nombre', 'url', 'metodo', 'codigo_esperado',
                      'timeout_seg', 'activo', 'ultimo_check', 'ultimo_estado',
                      'ultimo_codigo', 'ultimo_tiempo_ms', 'fecha_creacion'];
const DIEP_COLS    = 'id, nombre, descripcion, url, metodo, headers, body, '
                   . 'codigo_esperado, patron_respuesta, timeout_seg, activo, '
                   . 'ultimo_check, ultimo_estado, ultimo_codigo, ultimo_tiempo_ms, '
                   . 'ultimo_error, fecha_creacion, fecha_modificacion';

try {
    requirePermCrud('datainfra.endpoints');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOneEndpoint($pdo, $id);
    } elseif ($method === 'GET') {
        handleListEndpoints($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreateEndpoint($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdateEndpoint($pdo, $id, readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteEndpoint($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function normalizarFilaEndpoint(array $r): array {
    return [
        'id'                 => (int)($r['id'] ?? 0),
        'nombre'             => (string)($r['nombre'] ?? ''),
        'descripcion'        => $r['descripcion']      !== null ? (string)$r['descripcion']      : null,
        'url'                => (string)($r['url']    ?? ''),
        'metodo'             => (string)($r['metodo'] ?? 'GET'),
        'headers'            => $r['headers']          !== null ? (string)$r['headers']          : null,
        'body'               => $r['body']             !== null ? (string)$r['body']             : null,
        'codigo_esperado'    => (int)($r['codigo_esperado'] ?? 200),
        'patron_respuesta'   => $r['patron_respuesta'] !== null ? (string)$r['patron_respuesta'] : null,
        'timeout_seg'        => (int)($r['timeout_seg']     ?? 15),
        'activo'             => (int)($r['activo']          ?? 1),
        'ultimo_check'       => $r['ultimo_check']     ?? null,
        'ultimo_estado'      => (string)($r['ultimo_estado'] ?? 'nunca'),
        'ultimo_codigo'      => $r['ultimo_codigo']    !== null ? (int)$r['ultimo_codigo']       : null,
        'ultimo_tiempo_ms'   => $r['ultimo_tiempo_ms'] !== null ? (int)$r['ultimo_tiempo_ms']    : null,
        'ultimo_error'       => $r['ultimo_error']     !== null ? (string)$r['ultimo_error']     : null,
        'fecha_creacion'     => $r['fecha_creacion']     ?? null,
        'fecha_modificacion' => $r['fecha_modificacion'] ?? null,
    ];
}

function sanitizePayloadEndpoint(array $in, bool $esAlta): array {
    $nombre           = trim((string)($in['nombre']           ?? ''));
    $descripcion      = trim((string)($in['descripcion']      ?? ''));
    $url              = trim((string)($in['url']              ?? ''));
    $metodo           = strtoupper(trim((string)($in['metodo'] ?? '')));
    $headers          = trim((string)($in['headers']          ?? ''));
    $body             = (string)($in['body']                  ?? '');
    $patronRespuesta  = (string)($in['patron_respuesta']      ?? '');

    $codigoRaw  = $in['codigo_esperado'] ?? null;
    $timeoutRaw = $in['timeout_seg']     ?? null;
    $activoRaw  = $in['activo']          ?? null;

    if ($esAlta) {
        if ($nombre === '') jsonError('El nombre es obligatorio.', 400);
        if ($url    === '') jsonError('La URL es obligatoria.', 400);
        if ($metodo === '') $metodo = 'GET';
        if ($codigoRaw  === null || $codigoRaw  === '') $codigoRaw  = 200;
        if ($timeoutRaw === null || $timeoutRaw === '') $timeoutRaw = 15;
        if ($activoRaw  === null || $activoRaw  === '') $activoRaw  = 1;
    }

    if ($nombre !== '' && mb_strlen($nombre) > 120) {
        jsonError('El nombre no puede superar los 120 caracteres.', 400);
    }
    if ($descripcion !== '' && mb_strlen($descripcion) > 500) {
        jsonError('La descripcion no puede superar los 500 caracteres.', 400);
    }
    if ($url !== '') {
        if (mb_strlen($url) > 500) {
            jsonError('La URL no puede superar los 500 caracteres.', 400);
        }
        if (!preg_match('#^https?://#i', $url)) {
            jsonError('La URL debe empezar con http:// o https://.', 400);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            jsonError('La URL no es valida.', 400);
        }
    }
    if ($metodo !== '' && !in_array($metodo, DIEP_METODOS, true)) {
        jsonError('Metodo HTTP invalido (' . implode(', ', DIEP_METODOS) . ').', 400);
    }
    if ($headers !== '') {
        $decoded = json_decode($headers, true);
        if (!is_array($decoded)) {
            jsonError('Los headers deben ser un JSON valido tipo objeto (ej. {"Authorization":"Bearer ..."}).', 400);
        }
        // Recanonizar el JSON para guardar la forma minima consistente.
        $headers = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($patronRespuesta !== '' && mb_strlen($patronRespuesta) > 255) {
        jsonError('El patron de respuesta no puede superar los 255 caracteres.', 400);
    }

    $codigo = null;
    if ($codigoRaw !== null && $codigoRaw !== '') {
        $codigo = (int)$codigoRaw;
        if ($codigo < 100 || $codigo > 599) {
            jsonError('El codigo esperado debe estar entre 100 y 599.', 400);
        }
    }

    $timeout = null;
    if ($timeoutRaw !== null && $timeoutRaw !== '') {
        $timeout = (int)$timeoutRaw;
        if ($timeout < 1 || $timeout > 600) {
            jsonError('El timeout debe estar entre 1 y 600 segundos.', 400);
        }
    }

    $activo = null;
    if ($activoRaw !== null && $activoRaw !== '') {
        $activo = ((int)$activoRaw) === 1 ? 1 : 0;
    }

    return [
        'nombre'           => $nombre,
        'descripcion'      => $descripcion     === '' ? null : $descripcion,
        'url'              => $url,
        'metodo'           => $metodo,
        'headers'          => $headers         === '' ? null : $headers,
        'body'             => $body            === '' ? null : $body,
        'codigo_esperado'  => $codigo,
        'patron_respuesta' => $patronRespuesta === '' ? null : $patronRespuesta,
        'timeout_seg'      => $timeout,
        'activo'           => $activo,
    ];
}

// ----------------------------------------------------------------------------
// Handlers
// ----------------------------------------------------------------------------

function handleListEndpoints(PDO $pdo, array $q): void {
    $search = trim((string)($q['q']      ?? ''));
    $metodo = strtoupper(trim((string)($q['metodo'] ?? '')));
    $estado = trim((string)($q['estado'] ?? ''));
    $activo = trim((string)($q['activo'] ?? ''));
    $limite = max(1, min(1000, (int)($q['limite'] ?? 100)));
    $orden  = in_array(($q['orden'] ?? ''), DIEP_ORDENES, true) ? $q['orden'] : 'id';
    $dir    = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(nombre LIKE :s_nom OR url LIKE :s_url OR descripcion LIKE :s_desc)';
        $params[':s_nom']  = "%{$search}%";
        $params[':s_url']  = "%{$search}%";
        $params[':s_desc'] = "%{$search}%";
    }
    if ($metodo !== '' && in_array($metodo, DIEP_METODOS, true)) {
        $where[] = 'metodo = :metodo';
        $params[':metodo'] = $metodo;
    }
    if ($estado !== '' && in_array($estado, DIEP_ESTADOS, true)) {
        $where[] = 'ultimo_estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($activo === 'si') {
        $where[] = 'activo = 1';
    } elseif ($activo === 'no') {
        $where[] = 'activo = 0';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT ' . DIEP_COLS . " FROM datainfra_endpoints {$sqlWhere} ORDER BY {$orden} {$dir} LIMIT {$limite}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = array_map('normalizarFilaEndpoint', $st->fetchAll());

    $stats = [
        'total'      => (int)$pdo->query('SELECT COUNT(*) FROM datainfra_endpoints')->fetchColumn(),
        'activos'    => (int)$pdo->query('SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 1')->fetchColumn(),
        'inactivos'  => (int)$pdo->query('SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 0')->fetchColumn(),
        'ok'         => (int)$pdo->query("SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 1 AND ultimo_estado = 'ok'")->fetchColumn(),
        'error'      => (int)$pdo->query("SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 1 AND ultimo_estado IN ('error','timeout')")->fetchColumn(),
        'nunca'      => (int)$pdo->query("SELECT COUNT(*) FROM datainfra_endpoints WHERE activo = 1 AND ultimo_estado = 'nunca'")->fetchColumn(),
    ];

    jsonOk(['items' => $rows, 'stats' => $stats]);
}

function handleGetOneEndpoint(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT ' . DIEP_COLS . ' FROM datainfra_endpoints WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) jsonError('Endpoint no encontrado', 404);
    jsonOk(normalizarFilaEndpoint($row));
}

function handleCreateEndpoint(PDO $pdo, array $body): void {
    $p = sanitizePayloadEndpoint($body, true);

    $st = $pdo->prepare(
        'INSERT INTO datainfra_endpoints
            (nombre, descripcion, url, metodo, headers, body,
             codigo_esperado, patron_respuesta, timeout_seg, activo)
         VALUES
            (:nombre, :descripcion, :url, :metodo, :headers, :body,
             :codigo_esperado, :patron_respuesta, :timeout_seg, :activo)'
    );
    $st->execute([
        ':nombre'           => $p['nombre'],
        ':descripcion'      => $p['descripcion'],
        ':url'              => $p['url'],
        ':metodo'           => $p['metodo'],
        ':headers'          => $p['headers'],
        ':body'             => $p['body'],
        ':codigo_esperado'  => $p['codigo_esperado'],
        ':patron_respuesta' => $p['patron_respuesta'],
        ':timeout_seg'      => $p['timeout_seg'],
        ':activo'           => $p['activo'],
    ]);

    $id = (int)$pdo->lastInsertId();
    registrarSuceso($pdo, 'datainfraendpoints', 'info',
        "Alta endpoint #{$id} - \"{$p['nombre']}\" ({$p['metodo']} {$p['url']})");

    handleGetOneEndpoint($pdo, $id);
}

function handleUpdateEndpoint(PDO $pdo, int $id, array $body): void {
    $st = $pdo->prepare('SELECT ' . DIEP_COLS . ' FROM datainfra_endpoints WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Endpoint no encontrado', 404);

    $p = sanitizePayloadEndpoint($body, false);

    $sets   = [];
    $params = [':id' => $id];

    // Cada campo se actualiza solo si vino en el body -- asi se puede
    // pegar un PUT con `{"activo": 0}` para toggles rapidos sin tener
    // que reenviar todos los campos.
    if (array_key_exists('nombre', $body) && $p['nombre'] !== '') {
        $sets[] = 'nombre = :nombre';
        $params[':nombre'] = $p['nombre'];
    }
    if (array_key_exists('descripcion', $body)) {
        $sets[] = 'descripcion = :descripcion';
        $params[':descripcion'] = $p['descripcion'];
    }
    if (array_key_exists('url', $body) && $p['url'] !== '') {
        $sets[] = 'url = :url';
        $params[':url'] = $p['url'];
    }
    if (array_key_exists('metodo', $body) && $p['metodo'] !== '') {
        $sets[] = 'metodo = :metodo';
        $params[':metodo'] = $p['metodo'];
    }
    if (array_key_exists('headers', $body)) {
        $sets[] = 'headers = :headers';
        $params[':headers'] = $p['headers'];
    }
    if (array_key_exists('body', $body)) {
        $sets[] = 'body = :body';
        $params[':body'] = $p['body'];
    }
    if (array_key_exists('codigo_esperado', $body) && $p['codigo_esperado'] !== null) {
        $sets[] = 'codigo_esperado = :codigo_esperado';
        $params[':codigo_esperado'] = $p['codigo_esperado'];
    }
    if (array_key_exists('patron_respuesta', $body)) {
        $sets[] = 'patron_respuesta = :patron_respuesta';
        $params[':patron_respuesta'] = $p['patron_respuesta'];
    }
    if (array_key_exists('timeout_seg', $body) && $p['timeout_seg'] !== null) {
        $sets[] = 'timeout_seg = :timeout_seg';
        $params[':timeout_seg'] = $p['timeout_seg'];
    }
    if (array_key_exists('activo', $body) && $p['activo'] !== null) {
        $sets[] = 'activo = :activo';
        $params[':activo'] = $p['activo'];
    }

    if (empty($sets)) jsonError('No hay campos para actualizar.', 400);

    $sql = 'UPDATE datainfra_endpoints SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);

    registrarSuceso($pdo, 'datainfraendpoints', 'info',
        "Modificacion endpoint #{$id} - \"{$prev['nombre']}\"");

    handleGetOneEndpoint($pdo, $id);
}

function handleDeleteEndpoint(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT nombre FROM datainfra_endpoints WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $prev = $st->fetch();
    if (!$prev) jsonError('Endpoint no encontrado', 404);

    // El FK de `datainfra_endpoints_ejecuciones` tiene ON DELETE CASCADE,
    // asi que las corridas historicas se borran solas.
    $sd = $pdo->prepare('DELETE FROM datainfra_endpoints WHERE id = :id');
    $sd->execute([':id' => $id]);

    registrarSuceso($pdo, 'datainfraendpoints', 'info',
        "Baja endpoint #{$id} - \"{$prev['nombre']}\"");

    jsonOk(['id' => $id]);
}
