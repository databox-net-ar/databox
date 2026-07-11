<?php
/**
 * API cloud — Herramientas: Programador de tareas
 * (Scripts disponibles para el desplegable del form Alta/Edicion).
 *
 * Escanea cloud/jobs/*.php descartando los que empiezan con "_"
 * (infraestructura: _scheduler.php, _bootstrap.php, _cleanup_logs.php).
 *
 *   GET api/tareas_scripts_disponibles.php
 *     -> {ok:true, data:['jobs/foo.php', 'jobs/bar.php', ...]}
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
requireAuth();

try {
    requirePermission('administracion.herramientas.tareas.consultar');
    // /var/www/html/jobs dentro del contenedor.
    $jobsDir = realpath(__DIR__ . '/../jobs');
    if ($jobsDir === false || !is_dir($jobsDir)) {
        jsonOk([]);
    }
    $files = scandir($jobsDir) ?: [];
    $out   = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        if ($f[0] === '_') continue;
        if (!str_ends_with($f, '.php')) continue;
        $full = $jobsDir . '/' . $f;
        if (!is_file($full)) continue;
        // Guardamos la ruta relativa a cloud/ para que sea lo que se
        // persiste en tareas.script y lo que el scheduler concatena.
        $out[] = 'jobs/' . $f;
    }
    sort($out, SORT_NATURAL);
    jsonOk($out);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
