<?php
/**
 * cloud/jobs/_bootstrap.php
 * Runtime comun de los jobs disparados por el Programador de tareas.
 *
 * Uso tipico desde un script CLI de esta carpeta:
 *   require_once __DIR__ . '/_bootstrap.php';
 *   try {
 *       // trabajo real...
 *       marcarEjecucionOk('resumen opcional');
 *   } catch (Throwable $e) {
 *       marcarEjecucionError($e);
 *       throw $e;
 *   }
 *
 * Expone:
 *   ejecucionId(): int
 *   jobNombre(): string
 *   anotarLog(string $linea): void
 *   marcarEjecucionOk(?string $mensaje = null): void
 *   marcarEjecucionError(Throwable $e): void
 *
 * Ademas:
 *   - Fuerza line-buffering del stdout para que el streaming SSE
 *     muestre las lineas apenas se emiten.
 *   - Instala un handler de SIGTERM (via pcntl) para cerrar la fila
 *     como 'timeout' cuando `timeout` de coreutils dispara.
 *   - Instala un shutdown handler que captura fatales PHP y marca
 *     la fila como 'error' — es la ultima linea de defensa contra
 *     ejecuciones huerfanas.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('jobs bootstrap: solo por CLI');
}

// Line-buffering del stdout (crucial para el streaming SSE).
if (function_exists('ob_implicit_flush')) {
    @ob_implicit_flush(true);
}
while (ob_get_level() > 0) {
    @ob_end_flush();
}

// Zona horaria del proyecto.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Conexion a la BD reusando el helper del panel.
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/sucesos.php';

// ID de la ejecucion en la tabla `tareas_ejecuciones`. Lo setea el
// scheduler (o el endpoint tareas_ejecutar) en el env antes de invocar
// al proceso hijo.
$__JOB_EJECUCION_ID = (int) (getenv('EJECUCION_ID') ?: 0);
$__JOB_NOMBRE       = pathinfo(__FILE__, PATHINFO_FILENAME); // sobrescrito abajo por el script real
$__JOB_CERRADO      = false;

// Nombre real del job = basename del script que se esta ejecutando.
// $argv[0] es la ruta al .php que hizo el require_once de este bootstrap.
if (isset($argv[0]) && $argv[0] !== '') {
    $__JOB_NOMBRE = pathinfo($argv[0], PATHINFO_FILENAME);
}

function ejecucionId(): int {
    global $__JOB_EJECUCION_ID;
    return (int) $__JOB_EJECUCION_ID;
}

function jobNombre(): string {
    global $__JOB_NOMBRE;
    return (string) $__JOB_NOMBRE;
}

function anotarLog(string $linea): void {
    echo '[' . date('H:i:s') . '] ' . $linea . PHP_EOL;
}

function marcarEjecucionOk(?string $mensaje = null): void {
    global $__JOB_CERRADO;
    if ($__JOB_CERRADO) return;
    $id = ejecucionId();
    if ($id <= 0) { $__JOB_CERRADO = true; return; }
    try {
        $pdo = db();
        $st = $pdo->prepare('
            UPDATE tareas_ejecuciones
               SET fin = NOW(), estado = "ok", exit_code = 0, mensaje = :m
             WHERE id = :id AND estado = "corriendo"
        ');
        $st->execute([':m' => $mensaje, ':id' => $id]);
        if ($st->rowCount() > 0) {
            $up = $pdo->prepare('
                UPDATE tareas t
                  JOIN tareas_ejecuciones e ON e.tarea_id = t.id
                   SET t.ultimo_estado = "ok", t.ultimo_error = NULL
                 WHERE e.id = :id
            ');
            $up->execute([':id' => $id]);
        }
    } catch (Throwable $e) {
        error_log('marcarEjecucionOk: ' . $e->getMessage());
    }
    $__JOB_CERRADO = true;
}

function marcarEjecucionError(Throwable $e): void {
    global $__JOB_CERRADO;
    if ($__JOB_CERRADO) return;
    $id = ejecucionId();
    if ($id <= 0) { $__JOB_CERRADO = true; return; }
    $msg = substr($e->getMessage(), 0, 1000);
    try {
        $pdo = db();
        $st = $pdo->prepare('
            UPDATE tareas_ejecuciones
               SET fin = NOW(), estado = "error", exit_code = 1, mensaje = :m
             WHERE id = :id AND estado = "corriendo"
        ');
        $st->execute([':m' => $msg, ':id' => $id]);
        if ($st->rowCount() > 0) {
            $up = $pdo->prepare('
                UPDATE tareas t
                  JOIN tareas_ejecuciones e ON e.tarea_id = t.id
                   SET t.ultimo_estado = "error", t.ultimo_error = :m
                 WHERE e.id = :id
            ');
            $up->execute([':m' => $msg, ':id' => $id]);
        }
        try {
            registrarSuceso($pdo, 'cron/' . jobNombre(), 'error', $msg);
        } catch (Throwable $_) { /* no romper el flujo */ }
    } catch (Throwable $ex) {
        error_log('marcarEjecucionError: ' . $ex->getMessage());
    }
    $__JOB_CERRADO = true;
}

// Handler de SIGTERM: cierra la fila como 'timeout' cuando `timeout`
// de coreutils dispara al llegar a timeout_seg. Sin esto la fila queda
// 'corriendo' hasta que el watchdog del scheduler la mate (~1 min).
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () {
        global $__JOB_CERRADO;
        $id = ejecucionId();
        if (!$__JOB_CERRADO && $id > 0) {
            try {
                $pdo = db();
                $st = $pdo->prepare('
                    UPDATE tareas_ejecuciones
                       SET fin = NOW(), estado = "timeout", exit_code = 124,
                           mensaje = "SIGTERM: timeout alcanzado"
                     WHERE id = :id AND estado = "corriendo"
                ');
                $st->execute([':id' => $id]);
                if ($st->rowCount() > 0) {
                    $up = $pdo->prepare('
                        UPDATE tareas t
                          JOIN tareas_ejecuciones e ON e.tarea_id = t.id
                           SET t.ultimo_estado = "timeout",
                               t.ultimo_error  = "timeout alcanzado"
                         WHERE e.id = :id
                    ');
                    $up->execute([':id' => $id]);
                }
            } catch (Throwable $_) { /* nada mas que hacer */ }
            $__JOB_CERRADO = true;
        }
        exit(143);
    });
}

// Ultima linea de defensa: shutdown handler que atrapa fatales PHP
// y garantiza que la fila no quede 'corriendo' para siempre.
register_shutdown_function(function () {
    global $__JOB_CERRADO;
    if ($__JOB_CERRADO) return;
    $err = error_get_last();
    $fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($err && in_array($err['type'], $fatales, true)) {
        try {
            marcarEjecucionError(new Exception('PHP fatal: ' . $err['message']));
        } catch (Throwable $_) { /* nada */ }
    } else {
        // El script termino sin llamar a marcarEjecucionOk/Error — lo
        // registramos como 'error' con exit_code=0 para no dejarlo colgado.
        try {
            marcarEjecucionError(new Exception('El job termino sin cerrar la ejecucion'));
        } catch (Throwable $_) { /* nada */ }
    }
});
