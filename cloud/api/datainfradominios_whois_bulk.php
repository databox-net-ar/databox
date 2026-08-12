<?php
// api/datainfradominios_whois_bulk.php
// Endpoint HTTP que refresca los datos WHOIS de TODOS los dominios de
// `datainfra_dominios` y streamea el log al UI. Reusa la funcion
// `didoActualizarWhois()` del scraper (misma que el job diario
// `jobs/datainfra_dominios_actualizar.php`) para mantener la logica
// en un solo lugar.
//
// Uso: POST api/datainfradominios_whois_bulk.php
//
// Formato de respuesta: text/plain con una linea por evento y una linea
// final `___END___ <json>` con el resumen (ok, total, ok_count, err_count,
// cambios_totales). La UI lee el stream con fetch().body.getReader() y
// appendea cada linea al <pre> del modal.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/datainfradominios_whois.php';

requirePermission('datainfra.dominios.editar');

// -------- Configuracion de streaming --------
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
while (ob_get_level() > 0) @ob_end_flush();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

// El job diario tarda varios minutos si hay muchos .ar (nic.ar hace 2
// requests HTTP por dominio). Streaming, sin limite.
@set_time_limit(0);
ignore_user_abort(false);

$ORIGEN_SUCESO = 'ui/datainfradominios_whois_bulk';

$log = function (string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    @flush();
};

$endJson = function (array $payload): void {
    echo "___END___ " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
    exit;
};

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $log('Metodo HTTP no permitido.');
        $endJson(['ok' => false, 'error' => 'method_not_allowed']);
    }

    $pdo = db();

    $stmt = $pdo->query('
        SELECT id, dominio
          FROM datainfra_dominios
         ORDER BY id
    ');
    $dominios = $stmt->fetchAll();
    $total    = count($dominios);

    $log("Dominios a actualizar: {$total}");
    if ($total === 0) {
        $endJson(['ok' => true, 'total' => 0, 'ok_count' => 0,
                  'err_count' => 0, 'cambios_totales' => 0]);
    }

    $okCount    = 0;
    $errCount   = 0;
    $cambiosTot = 0;

    foreach ($dominios as $i => $d) {
        $prefix = sprintf('[%d/%d] #%d %s',
            $i + 1, $total, (int)$d['id'], $d['dominio'] ?? '');

        try {
            // Silenciamos el log fino del scraper: el bulk resume el resultado
            // en una linea por dominio y evita saturar el modal con decenas
            // de lineas por cada consulta HTTP.
            $noop = static fn (string $_m) => null;
            $r = didoActualizarWhois($pdo, (int)$d['id'], $noop);

            if ($r['ok']) {
                $cambios = (int)($r['cambios'] ?? 0);
                $fuente  = $r['fuente'] ?? '?';
                if ($cambios > 0) {
                    $log("{$prefix} - OK via {$fuente} ({$cambios} campo/s)");
                } else {
                    $log("{$prefix} - OK via {$fuente} (sin cambios)");
                }
                $okCount++;
                $cambiosTot += $cambios;
            } else {
                $detail = $r['detail'] ?? ($r['error'] ?? 'error desconocido');
                $log("{$prefix} - falla: {$detail}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "Dominio #{$d['id']} ({$d['dominio']}) - WHOIS fallo: {$detail}"
                );
                $errCount++;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $log("{$prefix} - excepcion: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "Dominio #{$d['id']} ({$d['dominio']}) - excepcion: {$msg}"
            );
            $errCount++;
        }
    }

    $resumen = "{$okCount} OK ({$cambiosTot} cambios) | {$errCount} con error | {$total} total";
    $log("Finalizado: {$resumen}");
    registrarSuceso($pdo, $ORIGEN_SUCESO, 'info',
        "Bulk WHOIS desde UI - {$resumen}");

    $endJson([
        'ok'              => true,
        'total'           => $total,
        'ok_count'        => $okCount,
        'err_count'       => $errCount,
        'cambios_totales' => $cambiosTot,
    ]);

} catch (Throwable $e) {
    try {
        registrarSuceso(db(), basename(__FILE__), 'error',
            $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    } catch (Throwable $_) { /* nada */ }
    $log('X Error inesperado: ' . $e->getMessage());
    $endJson(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()]);
}
