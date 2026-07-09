<?php
/**
 * cloud/jobs/_cleanup_logs.php
 * Cleanup nocturno del historial de ejecuciones del Programador de tareas.
 *
 * Recorre `tareas_ejecuciones` (join con `tareas`) y borra archivo `.log`
 * + fila cuando la ejecucion supero `retencion_dias` de su tarea. No toca
 * las que estan en estado 'corriendo' (esas son responsabilidad del
 * watchdog del scheduler).
 *
 * Invocado por cron una vez por dia (ver cloud/jobs/crontab).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cleanup_logs: solo por CLI');
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/sucesos.php';

$t0 = microtime(true);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] cleanup_logs: no se pudo conectar a la BD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$stmt = $pdo->query("
    SELECT e.id, e.log_path
      FROM tareas_ejecuciones e
      JOIN tareas t ON t.id = e.tarea_id
     WHERE e.estado != 'corriendo'
       AND TIMESTAMPDIFF(DAY, e.inicio, NOW()) > t.retencion_dias
     ORDER BY e.id
");

$filas    = 0;
$archivos = 0;
$sinArch  = 0;
$errores  = 0;

$del = $pdo->prepare('DELETE FROM tareas_ejecuciones WHERE id = :id');

foreach ($stmt as $row) {
    $eid  = (int) $row['id'];
    $path = (string) ($row['log_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        if (@unlink($path)) {
            $archivos++;
        } else {
            $errores++;
        }
    } else {
        $sinArch++;
    }
    try {
        $del->execute([':id' => $eid]);
        $filas++;
    } catch (Throwable $e) {
        $errores++;
    }
}

$dur = number_format(microtime(true) - $t0, 2);
$msg = "{$filas} filas borradas | {$archivos} archivos borrados | " .
       "{$sinArch} sin archivo | {$errores} errores | {$dur}s";
echo '[' . date('Y-m-d H:i:s') . '] cleanup_logs: ' . $msg . PHP_EOL;

if ($errores > 0) {
    registrarSuceso($pdo, 'cron/cleanup_logs', 'alerta', $msg);
} elseif ($filas > 0 || $archivos > 0) {
    registrarSuceso($pdo, 'cron/cleanup_logs', 'info', $msg);
}
