<?php
/**
 * API cloud — Herramientas: Programador de tareas (streaming SSE del log).
 *
 *   GET api/tareas_ejecucion_stream.php?id=N
 *
 * Emite el contenido del archivo `.log` de la ejecucion, linea a linea,
 * en formato Server-Sent Events. Al detectar que la fila salio de
 * 'corriendo', envia `event: end\ndata: <estado>` y cierra.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
requirePermission('administracion.herramientas.tareas.consultar');

// Headers SSE.
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // por si hay reverse proxy
header('Connection: keep-alive');

set_time_limit(0);
ignore_user_abort(false);
@ob_implicit_flush(true);
while (ob_get_level() > 0) @ob_end_flush();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    echo "event: end\ndata: error\n\n";
    flush();
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, estado, log_path FROM tareas_ejecuciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo "data: (ejecucion no encontrada)\n\n";
        echo "event: end\ndata: error\n\n";
        flush();
        exit;
    }

    $logPath = (string) ($row['log_path'] ?? '');
    if ($logPath === '' || !is_file($logPath)) {
        echo "data: (log no disponible: archivo rotado o aun no creado)\n\n";
        echo "event: end\ndata: " . $row['estado'] . "\n\n";
        flush();
        exit;
    }

    $fp = @fopen($logPath, 'r');
    if (!$fp) {
        echo "data: (no se pudo abrir el log)\n\n";
        echo "event: end\ndata: " . $row['estado'] . "\n\n";
        flush();
        exit;
    }

    $buffer         = '';
    $ultimoChequeo  = 0;
    $chequeoInterv  = 2.0; // segundos
    $keepAliveEvery = 2.0;
    $ultimoKA       = microtime(true);

    while (!connection_aborted()) {
        $chunk = fread($fp, 8192);
        if ($chunk !== false && $chunk !== '') {
            $buffer .= $chunk;
            // Emitir cada linea completa.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $linea = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                // Cap defensivo: limitar linea a 8 KB.
                if (strlen($linea) > 8192) $linea = substr($linea, 0, 8192) . ' [...cortada]';
                echo 'data: ' . $linea . "\n\n";
            }
            flush();
            continue;
        }

        // No hay mas datos: verificar si la ejecucion sigue corriendo.
        $ahora = microtime(true);
        if ($ahora - $ultimoChequeo > $chequeoInterv) {
            $ultimoChequeo = $ahora;
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch();
            $estadoActual = $r ? (string) $r['estado'] : 'error';
            if ($estadoActual !== 'corriendo') {
                // Vaciar buffer si quedo linea parcial.
                if ($buffer !== '') {
                    echo 'data: ' . $buffer . "\n\n";
                    $buffer = '';
                }
                echo "event: end\ndata: {$estadoActual}\n\n";
                flush();
                fclose($fp);
                exit;
            }
        }

        if ($ahora - $ultimoKA > $keepAliveEvery) {
            $ultimoKA = $ahora;
            echo ": keepalive\n\n";
            flush();
        }
        usleep(500_000); // 0.5s
    }
    fclose($fp);
} catch (Throwable $e) {
    echo 'data: (error interno: ' . $e->getMessage() . ")\n\n";
    echo "event: end\ndata: error\n\n";
    flush();
}
