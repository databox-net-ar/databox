<?php
/**
 * API cloud — Herramientas: Programador de tareas (historial de ejecuciones).
 *
 *   GET  api/tareas_ejecuciones.php?tarea_id=N[&estado=E&limite=M]
 *     -> listado del historial de una tarea (JOIN con tareas para tarea_nombre)
 *   GET  api/tareas_ejecuciones.php?id=N
 *     -> detalle de una ejecucion
 *   POST api/tareas_ejecuciones.php
 *     body: {"id": N, "accion": "detener"}
 *     -> mata el proceso (SIGTERM + SIGKILL) y cierra la fila.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
requireAuth();

try {
    requirePermission('administracion.herramientas.tareas.consultar');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            handleGetOne($pdo, $id);
        } else {
            handleList($pdo, $_GET);
        }
    } elseif ($method === 'POST') {
        $in = readJsonBody();
        $accion = (string) ($in['accion'] ?? '');
        $id     = (int) ($in['id'] ?? 0);
        if ($accion === 'detener') {
            if ($id <= 0) jsonError('Falta id', 400);
            handleDetener($pdo, $id);
        } else {
            jsonError('Accion no soportada', 400);
        }
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function ejNormalizar(array $r): array {
    return [
        'id'            => (int) ($r['id'] ?? 0),
        'tarea_id'      => (int) ($r['tarea_id'] ?? 0),
        'tarea_nombre'  => (string) ($r['tarea_nombre'] ?? ''),
        'pid'           => $r['pid'] !== null ? (int) $r['pid'] : null,
        'inicio'        => (string) ($r['inicio'] ?? ''),
        'fin'           => $r['fin'] !== null ? (string) $r['fin'] : null,
        'estado'        => (string) ($r['estado'] ?? 'corriendo'),
        'exit_code'     => $r['exit_code'] !== null ? (int) $r['exit_code'] : null,
        'mensaje'       => $r['mensaje']   !== null ? (string) $r['mensaje']   : null,
        'log_path'      => $r['log_path']  !== null ? (string) $r['log_path']  : null,
        'disparo'       => (string) ($r['disparo'] ?? 'scheduler'),
    ];
}

function handleList(PDO $pdo, array $q): void {
    $tareaId = isset($q['tarea_id']) ? (int) $q['tarea_id'] : 0;
    if ($tareaId <= 0) jsonError('Falta tarea_id', 400);

    $estado = (string) ($q['estado'] ?? '');
    $limite = isset($q['limite']) ? (int) $q['limite'] : 200;
    if ($limite < 1 || $limite > 1000) $limite = 200;

    $where  = ['e.tarea_id = :tid'];
    $params = [':tid' => $tareaId];
    if (in_array($estado, ['corriendo','ok','error','timeout','killed'], true)) {
        $where[] = 'e.estado = :est';
        $params[':est'] = $estado;
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT e.id, e.tarea_id, t.nombre AS tarea_nombre, e.pid, e.inicio, e.fin,
               e.estado, e.exit_code, e.mensaje, e.log_path, e.disparo
          FROM tareas_ejecuciones e
          JOIN tareas t ON t.id = e.tarea_id
          {$sqlWhere}
      ORDER BY e.id DESC
         LIMIT {$limite}
    ");
    $stmt->execute($params);
    jsonOk([
        'items' => array_map('ejNormalizar', $stmt->fetchAll()),
    ]);
}

function handleGetOne(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('
        SELECT e.id, e.tarea_id, t.nombre AS tarea_nombre, t.script AS tarea_script,
               t.cron_expr AS tarea_cron_expr, t.timeout_seg,
               e.pid, e.inicio, e.fin, e.estado, e.exit_code, e.mensaje,
               e.log_path, e.disparo
          FROM tareas_ejecuciones e
          JOIN tareas t ON t.id = e.tarea_id
         WHERE e.id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Ejecucion no encontrada', 404);
    $data = ejNormalizar($row);
    $data['tarea_script']    = (string) ($row['tarea_script']    ?? '');
    $data['tarea_cron_expr'] = (string) ($row['tarea_cron_expr'] ?? '');
    $data['timeout_seg']     = (int) ($row['timeout_seg'] ?? 0);
    jsonOk($data);
}

function handleDetener(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('
        SELECT e.id, e.pid, e.estado, e.tarea_id, t.nombre AS tarea_nombre
          FROM tareas_ejecuciones e
          JOIN tareas t ON t.id = e.tarea_id
         WHERE e.id = :id
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Ejecucion no encontrada', 404);
    if ($row['estado'] !== 'corriendo') {
        jsonError('not_running', 409);
    }

    // Pre-marcar la fila como 'killed' ANTES de mandar señales.
    // Si no hacemos esto, el handler pcntl_signal(SIGTERM, ...) del bootstrap
    // reacciona primero al SIGTERM que mandamos abajo y marca la fila como
    // 'timeout' — perdemos la semántica de "detenido manualmente" y la UI
    // muestra timeout en lugar de killed. Con la fila ya fuera de 'corriendo',
    // los UPDATEs guardados por `AND estado = 'corriendo'` del bootstrap
    // quedan como no-op.
    $mensajeInicial = 'Detenido manualmente desde el panel.';
    $pre = $pdo->prepare("
        UPDATE tareas_ejecuciones
           SET fin = NOW(), estado = 'killed', exit_code = 143, mensaje = :m
         WHERE id = :id AND estado = 'corriendo'
    ");
    $pre->execute([':m' => $mensajeInicial, ':id' => $id]);
    $preRows = $pre->rowCount();

    $pid    = (int) ($row['pid'] ?? 0);
    $killed = false;

    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 15); // SIGTERM
        $tRet = 0.0;
        while ($tRet < 2.0) {
            if (!@posix_kill($pid, 0)) break; // ya no vive
            usleep(200_000);
            $tRet += 0.2;
        }
        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, 9); // SIGKILL
            $killed = true;
        }
    }

    // Refinar el mensaje ahora que sabemos qué señal terminó bajando el proceso.
    $mensajeFinal = 'Detenido manualmente desde el panel (' . ($killed ? 'SIGKILL' : 'SIGTERM') . ').';
    $pdo->prepare('UPDATE tareas_ejecuciones SET mensaje = :m WHERE id = :id')
        ->execute([':m' => $mensajeFinal, ':id' => $id]);

    if ($preRows > 0) {
        $ut = $pdo->prepare("
            UPDATE tareas SET ultimo_estado = 'killed', ultimo_error = :m
             WHERE id = :tid
        ");
        $ut->execute([':m' => $mensajeFinal, ':tid' => (int) $row['tarea_id']]);
        registrarSuceso($pdo, 'cron/scheduler', 'alerta',
            'Ejecucion #' . $id . ' de "' . $row['tarea_nombre'] . '" detenida manualmente (' .
            ($killed ? 'SIGKILL' : 'SIGTERM') . ').');
    }

    jsonOk(['killed' => $killed]);
}
