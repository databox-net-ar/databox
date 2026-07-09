<?php
/**
 * cloud/jobs/_scheduler.php
 * Tick minutal del Programador de tareas. Invocado por cron dentro del
 * contenedor databox-apache (ver cloud/jobs/crontab).
 *
 * Hace exactamente 4 cosas y sale en < 1s:
 *   1. Barre ejecuciones huerfanas (watchdog).
 *   2. Lee las tareas activas.
 *   3. Evalua cron_expr contra el minuto actual.
 *   4. Dispara en background las tareas que matchean (respetando overlap).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('scheduler: solo por CLI');
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/sucesos.php';

const SCHED_LOG_DIR = '/var/log/databox/cloud/ejecuciones';
// En databox, /var/www/html ES la carpeta cloud/ (ver docker-compose.yml).
// Los scripts se guardan en tareas.script como ruta relativa a cloud/
// (ej: "jobs/foo.php"), y se resuelven contra este root.
const SCHED_CLOUD_ROOT = '/var/www/html';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] scheduler: no se pudo conectar a la BD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Asegurar el log dir. Idempotente. El Dockerfile lo crea con www-data:www-data.
if (!is_dir(SCHED_LOG_DIR)) {
    @mkdir(SCHED_LOG_DIR, 0755, true);
}

// -----------------------------------------------------------------------------
// 1) Watchdog: barrer ejecuciones que estan en 'corriendo' hace mas de
//    timeout_seg * 2 (el `timeout` de coreutils ya deberia haberlas matado;
//    esto atrapa los casos donde no pudo).
// -----------------------------------------------------------------------------

$orphans = $pdo->query("
    SELECT e.id, e.pid, e.inicio, t.nombre, t.timeout_seg
      FROM tareas_ejecuciones e
      JOIN tareas t ON t.id = e.tarea_id
     WHERE e.estado = 'corriendo'
       AND TIMESTAMPDIFF(SECOND, e.inicio, NOW()) > t.timeout_seg * 2
")->fetchAll();

foreach ($orphans as $o) {
    $pid       = (int) ($o['pid'] ?? 0);
    $elapsed   = time() - strtotime((string) $o['inicio']);
    $timeoutS  = (int) $o['timeout_seg'];
    $matado    = false;
    if ($pid > 0 && function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, 9);
            $matado = true;
        }
    }
    $mensaje = 'watchdog: killed after ' . $elapsed . 's (timeout ' . $timeoutS . 's)';
    $up = $pdo->prepare("
        UPDATE tareas_ejecuciones
           SET fin = NOW(), estado = 'killed', exit_code = 137, mensaje = :m
         WHERE id = :id AND estado = 'corriendo'
    ");
    $up->execute([':m' => $mensaje, ':id' => (int) $o['id']]);
    if ($up->rowCount() > 0) {
        $ut = $pdo->prepare("
            UPDATE tareas t
              JOIN tareas_ejecuciones e ON e.tarea_id = t.id
               SET t.ultimo_estado = 'killed', t.ultimo_error = :m
             WHERE e.id = :id
        ");
        $ut->execute([':m' => $mensaje, ':id' => (int) $o['id']]);
        registrarSuceso($pdo, 'cron/scheduler', 'alerta',
            'Watchdog mato la ejecucion #' . $o['id'] . ' de "' . $o['nombre'] . '" (' .
            ($matado ? 'SIGKILL enviado' : 'pid ya no vivo') . ').');
    }
}

// -----------------------------------------------------------------------------
// 2) Leer tareas activas.
// -----------------------------------------------------------------------------

$tareas = $pdo->query('
    SELECT id, nombre, script, cron_expr, overlap, timeout_seg
      FROM tareas
     WHERE activo = 1
')->fetchAll();

$ahora = new DateTime('now');

foreach ($tareas as $t) {
    if (!cronMatch((string) $t['cron_expr'], $ahora)) continue;

    if ($t['overlap'] === 'skip') {
        $chk = $pdo->prepare("
            SELECT id FROM tareas_ejecuciones
             WHERE tarea_id = :id AND estado = 'corriendo' LIMIT 1
        ");
        $chk->execute([':id' => (int) $t['id']]);
        if ($chk->fetchColumn()) {
            fwrite(STDERR, '[' . $ahora->format('Y-m-d H:i:s') .
                '] skip: "' . $t['nombre'] . '" ya esta corriendo' . PHP_EOL);
            continue;
        }
    }

    dispararTarea($pdo, $t, 'scheduler');
}

exit(0);

// -----------------------------------------------------------------------------
// dispararTarea: inserta la fila, escribe el encabezado y lanza en background.
// -----------------------------------------------------------------------------

function dispararTarea(PDO $pdo, array $t, string $disparo): int {
    $tareaId   = (int) $t['id'];
    $nombre    = (string) $t['nombre'];
    $script    = (string) $t['script']; // ruta relativa a cloud/ (ej: "jobs/foo.php")
    $cronExpr  = (string) $t['cron_expr'];
    $timeoutS  = max(5, (int) $t['timeout_seg']);
    $scriptAbs = realpath(SCHED_CLOUD_ROOT . '/' . ltrim($script, '/'));
    if ($scriptAbs === false || !is_file($scriptAbs)) {
        $err = 'script no encontrado: ' . $script;
        $st = $pdo->prepare('
            INSERT INTO tareas_ejecuciones
                (tarea_id, inicio, fin, estado, exit_code, mensaje, disparo)
            VALUES (:tid, NOW(), NOW(), "error", 127, :m, :d)
        ');
        $st->execute([':tid' => $tareaId, ':m' => $err, ':d' => $disparo]);
        $eid = (int) $pdo->lastInsertId();
        $up = $pdo->prepare('
            UPDATE tareas SET ultimo_run = NOW(),
                              ultimo_estado = "error",
                              ultimo_error = :m
             WHERE id = :id
        ');
        $up->execute([':m' => $err, ':id' => $tareaId]);
        return $eid;
    }

    // 1) Insertar la fila en 'corriendo'.
    $ins = $pdo->prepare('
        INSERT INTO tareas_ejecuciones (tarea_id, inicio, estado, disparo)
        VALUES (:tid, NOW(), "corriendo", :d)
    ');
    $ins->execute([':tid' => $tareaId, ':d' => $disparo]);
    $ejecucionId = (int) $pdo->lastInsertId();

    // 2) Definir el log_path.
    $logPath = SCHED_LOG_DIR . '/' . $ejecucionId . '.log';
    $upLog = $pdo->prepare('UPDATE tareas_ejecuciones SET log_path = :p WHERE id = :id');
    $upLog->execute([':p' => $logPath, ':id' => $ejecucionId]);

    // 3) Refrescar el snapshot de la tarea.
    $upT = $pdo->prepare('
        UPDATE tareas SET ultimo_run = NOW(),
                          ultimo_estado = "corriendo",
                          ultimo_error = NULL
         WHERE id = :id
    ');
    $upT->execute([':id' => $tareaId]);

    // 4) Escribir el encabezado del .log.
    if (!is_dir(SCHED_LOG_DIR)) @mkdir(SCHED_LOG_DIR, 0755, true);
    $ts = date('Y-m-d H:i:s');
    $encab = "-- Ejecucion #{$ejecucionId} de \"{$nombre}\" ({$cronExpr}) --\n"
           . "-- Script: {$script} --\n"
           . "-- Timeout: {$timeoutS}s  |  Disparo: {$disparo}  |  Inicio: {$ts} --\n\n";
    @file_put_contents($logPath, $encab);

    // 5) Ejecutar en background con timeout + stdbuf.
    //    EJECUCION_ID en el env es como el bootstrap del hijo sabe que fila cerrar.
    //    `timeout --signal=TERM --kill-after=10s Xs` manda SIGTERM al llegar a X,
    //    SIGKILL 10s despues si no murio.
    //    `stdbuf -oL -eL` fuerza line-buffering para que el streaming SSE muestre
    //    lineas apenas se emiten.
    //    `& echo $!` devuelve el PID del proceso hijo real (no del wrapper timeout).
    $cmd = sprintf(
        'EJECUCION_ID=%d timeout --signal=TERM --kill-after=10s %ds ' .
        'stdbuf -oL -eL php %s >> %s 2>&1 & echo $!',
        $ejecucionId, $timeoutS,
        escapeshellarg($scriptAbs),
        escapeshellarg($logPath)
    );
    $pid = (int) trim((string) @shell_exec($cmd));
    if ($pid > 0) {
        $upPid = $pdo->prepare('UPDATE tareas_ejecuciones SET pid = :p WHERE id = :id');
        $upPid->execute([':p' => $pid, ':id' => $ejecucionId]);
    }

    return $ejecucionId;
}

// -----------------------------------------------------------------------------
// cronMatch: parser de expresiones cron de 5 campos.
// Soporta: asterisco / N / asterisco-barra-N / N-M / N,M / combinaciones.
// -----------------------------------------------------------------------------

function cronMatch(string $expr, DateTime $ahora): bool {
    $partes = preg_split('/\s+/', trim($expr)) ?: [];
    if (count($partes) !== 5) return false;
    $valores = [
        (int) $ahora->format('i'),  // minuto
        (int) $ahora->format('G'),  // hora (0-23)
        (int) $ahora->format('j'),  // dia del mes (1-31)
        (int) $ahora->format('n'),  // mes (1-12)
        (int) $ahora->format('w'),  // dia semana (0-6, 0=domingo)
    ];
    $rangos = [
        [0, 59], [0, 23], [1, 31], [1, 12], [0, 6],
    ];
    foreach ($partes as $i => $campo) {
        if (!cronCampoMatch($campo, $valores[$i], $rangos[$i][0], $rangos[$i][1])) {
            return false;
        }
    }
    return true;
}

function cronCampoMatch(string $campo, int $valor, int $min, int $max): bool {
    // Coma: cualquiera de los sub-fragmentos.
    if (str_contains($campo, ',')) {
        foreach (explode(',', $campo) as $sub) {
            if (cronCampoMatch(trim($sub), $valor, $min, $max)) return true;
        }
        return false;
    }
    // Step: A/N  donde A puede ser asterisco, un rango o un valor.
    $paso = 1;
    if (str_contains($campo, '/')) {
        [$base, $pasoStr] = explode('/', $campo, 2);
        $paso = max(1, (int) $pasoStr);
        $campo = $base;
    }
    // Asterisco -> todo el rango.
    if ($campo === '*') {
        return (($valor - $min) % $paso) === 0;
    }
    // Rango.
    if (str_contains($campo, '-')) {
        [$a, $b] = explode('-', $campo, 2);
        $a = (int) $a; $b = (int) $b;
        if ($valor < $a || $valor > $b) return false;
        return (($valor - $a) % $paso) === 0;
    }
    // Valor exacto.
    if (!ctype_digit($campo)) return false;
    $n = (int) $campo;
    if ($paso === 1) return $valor === $n;
    // step con valor base: 5/10 -> 5,15,25,...
    if ($valor < $n) return false;
    return (($valor - $n) % $paso) === 0;
}
