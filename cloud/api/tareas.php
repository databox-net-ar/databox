<?php
/**
 * API cloud — Herramientas: Programador de tareas (CRUD del catalogo).
 *
 * Multiplexa GET/POST/PUT/DELETE contra la tabla `tareas`. Ver la skill
 * `crear_programador_de_tareas` §7.1 y el schema en db/schema.sql.
 *
 *   GET    api/tareas.php                -> listado con filtros (q, activo, order_by, dir, limite)
 *   GET    api/tareas.php?id=N           -> registro individual
 *   POST   api/tareas.php                -> alta (JSON body)
 *   PUT    api/tareas.php?id=N           -> modificacion (JSON body)
 *   DELETE api/tareas.php?id=N           -> baja en cascada (fila + historial + archivos .log)
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
requireAuth();

try {
    requirePermCrud('administracion.herramientas.tareas');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($method === 'GET' && $id > 0) {
        handleGetOne($pdo, $id);
    } elseif ($method === 'GET') {
        handleList($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCreate($pdo, readJsonBody());
    } elseif ($method === 'PUT') {
        $body = readJsonBody();
        if ($id <= 0) $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonError('Falta id', 400);
        handleUpdate($pdo, $id, $body);
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
        'id'                 => (int) ($r['id'] ?? 0),
        'nombre'             => (string) ($r['nombre'] ?? ''),
        'descripcion'        => $r['descripcion'] !== null ? (string) $r['descripcion'] : null,
        'script'             => (string) ($r['script'] ?? ''),
        'cron_expr'          => (string) ($r['cron_expr'] ?? ''),
        'activo'             => (int) ($r['activo'] ?? 0) === 1 ? 1 : 0,
        'overlap'            => (string) ($r['overlap'] ?? 'skip'),
        'timeout_seg'        => (int) ($r['timeout_seg'] ?? 300),
        'retencion_dias'     => (int) ($r['retencion_dias'] ?? 7),
        'ultimo_run'         => $r['ultimo_run']    !== null ? (string) $r['ultimo_run']    : null,
        'ultimo_estado'      => $r['ultimo_estado'] !== null ? (string) $r['ultimo_estado'] : null,
        'ultimo_error'       => $r['ultimo_error']  !== null ? (string) $r['ultimo_error']  : null,
        'fecha_creacion'     => (string) ($r['fecha_creacion']     ?? ''),
        'fecha_modificacion' => (string) ($r['fecha_modificacion'] ?? ''),
    ];
}

function sanitizePayload(array $in): array {
    $nombre      = trim((string) ($in['nombre']      ?? ''));
    $descripcion = trim((string) ($in['descripcion'] ?? ''));
    if ($descripcion === '') $descripcion = null;
    $script      = trim((string) ($in['script']      ?? ''));
    $cronExpr    = trim((string) ($in['cron_expr']   ?? ''));
    $overlap     = (string) ($in['overlap'] ?? 'skip');
    $activo      = (int) !empty($in['activo']) ? 1 : 0;

    $timeoutSeg    = (int) ($in['timeout_seg']    ?? 300);
    $retencionDias = (int) ($in['retencion_dias'] ?? 7);

    if ($nombre === '')          jsonError('El nombre es obligatorio.', 400);
    if (strlen($nombre) > 120)   jsonError('El nombre no puede superar los 120 caracteres.', 400);
    if ($descripcion !== null && strlen($descripcion) > 255) {
        jsonError('La descripcion no puede superar los 255 caracteres.', 400);
    }
    if ($script === '')          jsonError('El script es obligatorio.', 400);
    if (strlen($script) > 255)   jsonError('La ruta al script no puede superar los 255 caracteres.', 400);
    if (!preg_match('/^[A-Za-z0-9_\/.\-]+\.php$/', $script)) {
        jsonError('El script debe ser una ruta relativa PHP valida.', 400);
    }
    if ($cronExpr === '')        jsonError('La expresion cron es obligatoria.', 400);
    if (strlen($cronExpr) > 80)  jsonError('La expresion cron no puede superar los 80 caracteres.', 400);
    $partes = preg_split('/\s+/', $cronExpr) ?: [];
    if (count($partes) !== 5)    jsonError('La expresion cron debe tener exactamente 5 campos.', 400);
    foreach ($partes as $p) {
        if (!preg_match('/^[\d*,\/\-]+$/', $p)) {
            jsonError('La expresion cron contiene caracteres invalidos.', 400);
        }
    }
    if (!in_array($overlap, ['skip', 'allow'], true)) $overlap = 'skip';
    if ($timeoutSeg < 5)     $timeoutSeg = 5;
    if ($timeoutSeg > 86400) $timeoutSeg = 86400;
    if ($retencionDias < 1)  $retencionDias = 1;
    if ($retencionDias > 3650) $retencionDias = 3650;

    return [
        'nombre'         => $nombre,
        'descripcion'    => $descripcion,
        'script'         => $script,
        'cron_expr'      => $cronExpr,
        'activo'         => $activo,
        'overlap'        => $overlap,
        'timeout_seg'    => $timeoutSeg,
        'retencion_dias' => $retencionDias,
    ];
}

function nombreYaExiste(PDO $pdo, string $nombre, int $exceptId = 0): bool {
    $sql = 'SELECT id FROM tareas WHERE nombre = :n';
    if ($exceptId > 0) $sql .= ' AND id <> :exc';
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':n', $nombre);
    if ($exceptId > 0) $stmt->bindValue(':exc', $exceptId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetch();
}

// ----------------------------------------------------------------------------
// Listado y stats
// ----------------------------------------------------------------------------

function handleList(PDO $pdo, array $q): void {
    $codigo = isset($q['codigo']) && $q['codigo'] !== '' ? (int) $q['codigo'] : null;
    $search = trim((string) ($q['q'] ?? ''));
    $activo = array_key_exists('activo', $q) ? (string) $q['activo'] : '';

    $orderBy = $q['order_by'] ?? 'id';
    $dir     = strtolower((string) ($q['dir'] ?? 'desc'));
    $limite  = isset($q['limite']) ? (int) $q['limite'] : 100;
    if ($limite < 1 || $limite > 1000) $limite = 100;

    $allowedOrder = ['id', 'nombre', 'ultimo_run', 'fecha_modificacion'];
    if (!in_array($orderBy, $allowedOrder, true)) $orderBy = 'id';
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

    $where  = [];
    $params = [];

    if ($codigo !== null) {
        $where[] = 'id = :codigo';
        $params[':codigo'] = $codigo;
    }
    if ($activo === '0' || $activo === '1') {
        $where[] = 'activo = :activo';
        $params[':activo'] = (int) $activo;
    }
    if ($search !== '') {
        $where[] = '(nombre LIKE :s1 OR script LIKE :s2 OR descripcion LIKE :s3 OR cron_expr LIKE :s4)';
        $like = "%{$search}%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stats = [
        'total'     => (int) $pdo->query('SELECT COUNT(*) FROM tareas')->fetchColumn(),
        'activas'   => (int) $pdo->query('SELECT COUNT(*) FROM tareas WHERE activo = 1')->fetchColumn(),
        'errores'   => (int) $pdo->query("SELECT COUNT(*) FROM tareas WHERE ultimo_estado = 'error'")->fetchColumn(),
        'corriendo' => (int) $pdo->query("SELECT COUNT(*) FROM tareas_ejecuciones WHERE estado = 'corriendo'")->fetchColumn(),
    ];

    $sql = "
        SELECT id, nombre, descripcion, script, cron_expr, activo, overlap,
               timeout_seg, retencion_dias, ultimo_run, ultimo_estado,
               ultimo_error, fecha_creacion, fecha_modificacion
          FROM tareas
          {$sqlWhere}
      ORDER BY {$orderBy} {$dirSql}
         LIMIT {$limite}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizarFila', $stmt->fetchAll());

    jsonOk([
        'stats' => $stats,
        'items' => $rows,
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('
        SELECT id, nombre, descripcion, script, cron_expr, activo, overlap,
               timeout_seg, retencion_dias, ultimo_run, ultimo_estado,
               ultimo_error, fecha_creacion, fecha_modificacion
          FROM tareas WHERE id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Tarea no encontrada', 404);
    jsonOk(normalizarFila($row));
}

// ----------------------------------------------------------------------------
// Alta / Modificacion / Baja
// ----------------------------------------------------------------------------

function handleCreate(PDO $pdo, array $in): void {
    $p = sanitizePayload($in);
    if (nombreYaExiste($pdo, $p['nombre'])) {
        jsonError('nombre_duplicado', 409);
    }
    $stmt = $pdo->prepare('
        INSERT INTO tareas (nombre, descripcion, script, cron_expr, activo,
                            overlap, timeout_seg, retencion_dias)
        VALUES (:nombre, :descripcion, :script, :cron_expr, :activo,
                :overlap, :timeout_seg, :retencion_dias)
    ');
    $stmt->execute([
        ':nombre'         => $p['nombre'],
        ':descripcion'    => $p['descripcion'],
        ':script'         => $p['script'],
        ':cron_expr'      => $p['cron_expr'],
        ':activo'         => $p['activo'],
        ':overlap'        => $p['overlap'],
        ':timeout_seg'    => $p['timeout_seg'],
        ':retencion_dias' => $p['retencion_dias'],
    ]);
    jsonOk(['id' => (int) $pdo->lastInsertId()], 201);
}

function handleUpdate(PDO $pdo, int $id, array $in): void {
    $exists = $pdo->prepare('SELECT id FROM tareas WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetch()) jsonError('Tarea no encontrada', 404);

    $p = sanitizePayload($in);
    if (nombreYaExiste($pdo, $p['nombre'], $id)) {
        jsonError('nombre_duplicado', 409);
    }
    $stmt = $pdo->prepare('
        UPDATE tareas
           SET nombre = :nombre, descripcion = :descripcion, script = :script,
               cron_expr = :cron_expr, activo = :activo, overlap = :overlap,
               timeout_seg = :timeout_seg, retencion_dias = :retencion_dias
         WHERE id = :id
    ');
    $stmt->execute([
        ':nombre'         => $p['nombre'],
        ':descripcion'    => $p['descripcion'],
        ':script'         => $p['script'],
        ':cron_expr'      => $p['cron_expr'],
        ':activo'         => $p['activo'],
        ':overlap'        => $p['overlap'],
        ':timeout_seg'    => $p['timeout_seg'],
        ':retencion_dias' => $p['retencion_dias'],
    ] + [':id' => $id]);
    jsonOk(['id' => $id]);
}

function handleDelete(PDO $pdo, int $id): void {
    $exists = $pdo->prepare('SELECT id, nombre FROM tareas WHERE id = :id');
    $exists->execute([':id' => $id]);
    $t = $exists->fetch();
    if (!$t) jsonError('Tarea no encontrada', 404);

    // 1) Chequear si hay ejecuciones corriendo.
    $chk = $pdo->prepare("SELECT COUNT(*) FROM tareas_ejecuciones WHERE tarea_id = :id AND estado = 'corriendo'");
    $chk->execute([':id' => $id]);
    if ((int) $chk->fetchColumn() > 0) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => false,
            'error'  => 'ejecucion_en_curso',
            'detail' => 'La tarea tiene una ejecucion en curso. Detenela desde el historial antes de borrarla.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) Borrar los archivos .log en disco (antes de borrar las filas,
    //    porque despues del DELETE en cascada no sabriamos donde estan).
    $st = $pdo->prepare('SELECT log_path FROM tareas_ejecuciones WHERE tarea_id = :id AND log_path IS NOT NULL');
    $st->execute([':id' => $id]);
    $archivosBorrados = 0;
    foreach ($st as $row) {
        $p = (string) ($row['log_path'] ?? '');
        if ($p !== '' && is_file($p)) {
            if (@unlink($p)) $archivosBorrados++;
        }
    }

    // 3) Borrar el historial.
    $pdo->prepare('DELETE FROM tareas_ejecuciones WHERE tarea_id = :id')->execute([':id' => $id]);
    // 4) Borrar la tarea.
    $st = $pdo->prepare('DELETE FROM tareas WHERE id = :id');
    $st->execute([':id' => $id]);

    jsonOk(['borrados' => $st->rowCount(), 'archivos_borrados' => $archivosBorrados]);
}
