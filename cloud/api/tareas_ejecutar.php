<?php
/**
 * API cloud — Herramientas: Programador de tareas ("Ejecutar ahora").
 *
 *   POST api/tareas_ejecutar.php
 *     body: {"tarea_id": N}
 *     -> {ok:true, ejecucion_id: M, pid: P}
 *
 * Comparte tecnica con dispararTarea() del scheduler pero con
 * disparo='manual' y sin evaluar cron_expr.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
requireAuth();

const EJEC_LOG_DIR    = '/var/log/databox/cloud/ejecuciones';
const EJEC_CLOUD_ROOT = '/var/www/html';

try {
    requirePermission('administracion.herramientas.tareas.ejecutar');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    $in       = readJsonBody();
    $tareaId  = (int) ($in['tarea_id'] ?? 0);
    if ($tareaId <= 0) jsonError('Falta tarea_id', 400);

    $pdo = db();

    $st = $pdo->prepare('
        SELECT id, nombre, tipo, script, url, cron_expr, overlap, timeout_seg
          FROM tareas WHERE id = :id
    ');
    $st->execute([':id' => $tareaId]);
    $t = $st->fetch();
    if (!$t) jsonError('Tarea no encontrada', 404);

    if ($t['overlap'] === 'skip') {
        $chk = $pdo->prepare("SELECT id FROM tareas_ejecuciones WHERE tarea_id = :id AND estado = 'corriendo' LIMIT 1");
        $chk->execute([':id' => $tareaId]);
        if ($chk->fetchColumn()) jsonError('ya_esta_corriendo', 409);
    }

    if (!is_dir(EJEC_LOG_DIR)) @mkdir(EJEC_LOG_DIR, 0755, true);

    $tipo = (string) ($t['tipo'] ?? 'php');
    $url  = (string) ($t['url']  ?? '');
    $timeoutS = max(5, (int) $t['timeout_seg']);

    if ($tipo === 'url') {
        if ($url === '') jsonError('url_vacia', 400);
        $scriptAbs = realpath(EJEC_CLOUD_ROOT . '/jobs/_curl.php');
        if ($scriptAbs === false || !is_file($scriptAbs)) jsonError('wrapper_no_encontrado', 500);
        $sourceLbl = 'URL: ' . $url;
    } elseif ($tipo === 'php') {
        $scriptRel = (string) ($t['script'] ?? '');
        if ($scriptRel === '') jsonError('script_vacio', 400);
        $scriptAbs = realpath(EJEC_CLOUD_ROOT . '/' . ltrim($scriptRel, '/'));
        if ($scriptAbs === false || !is_file($scriptAbs)) jsonError('script_no_encontrado', 400);
        $sourceLbl = 'Script: ' . $scriptRel;
    } else {
        jsonError('tipo_desconocido', 400);
    }

    $ins = $pdo->prepare('
        INSERT INTO tareas_ejecuciones (tarea_id, inicio, estado, disparo)
        VALUES (:tid, NOW(), "corriendo", "manual")
    ');
    $ins->execute([':tid' => $tareaId]);
    $ejecucionId = (int) $pdo->lastInsertId();

    $logPath = EJEC_LOG_DIR . '/' . $ejecucionId . '.log';
    $pdo->prepare('UPDATE tareas_ejecuciones SET log_path = :p WHERE id = :id')
        ->execute([':p' => $logPath, ':id' => $ejecucionId]);

    $pdo->prepare('
        UPDATE tareas SET ultimo_run = NOW(),
                          ultimo_estado = "corriendo",
                          ultimo_error = NULL
         WHERE id = :id
    ')->execute([':id' => $tareaId]);

    $ts = date('Y-m-d H:i:s');
    $encab = "-- Ejecucion #{$ejecucionId} de \"{$t['nombre']}\" ({$t['cron_expr']}) --\n"
           . "-- {$sourceLbl} --\n"
           . "-- Timeout: {$timeoutS}s  |  Disparo: manual  |  Inicio: {$ts} --\n\n";
    @file_put_contents($logPath, $encab);

    $envExtra = '';
    if ($tipo === 'url') {
        $envExtra = 'TAREA_URL=' . escapeshellarg($url) . ' '
                  . 'TAREA_TIMEOUT=' . max(5, $timeoutS - 5) . ' ';
    }
    $cmd = sprintf(
        '%sEJECUCION_ID=%d timeout --signal=TERM --kill-after=10s %ds ' .
        'stdbuf -oL -eL php %s >> %s 2>&1 & echo $!',
        $envExtra, $ejecucionId, $timeoutS,
        escapeshellarg($scriptAbs),
        escapeshellarg($logPath)
    );
    $pid = (int) trim((string) @shell_exec($cmd));
    if ($pid > 0) {
        $pdo->prepare('UPDATE tareas_ejecuciones SET pid = :p WHERE id = :id')
            ->execute([':p' => $pid, ':id' => $ejecucionId]);
    }

    registrarSuceso($pdo, 'cron/scheduler', 'info',
        'Ejecucion manual #' . $ejecucionId . ' de "' . $t['nombre'] . '" disparada desde el panel.');

    jsonOk(['ejecucion_id' => $ejecucionId, 'pid' => $pid], 201);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
