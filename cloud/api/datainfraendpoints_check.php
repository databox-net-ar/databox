<?php
// api/datainfraendpoints_check.php
// Endpoint HTTP que corre el health-check on-demand de un endpoint de
// `datainfra_endpoints` y streamea el log al UI. La logica del check
// vive en `lib/datainfraendpoints_check.php` para poder reusarse tambien
// desde el job cron `jobs/datainfra_endpoints_check.php`.
//
// Uso: POST api/datainfraendpoints_check.php  { id: 123 }
//
// Formato de respuesta: text/plain con una linea por evento y una linea
// final `___END___ <json>` con el resumen (ok, estado, codigo, tiempo_ms,
// error, ejecucion_id). La UI lee el stream con fetch().body.getReader()
// y appendea cada linea al <pre> del modal.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/datainfraendpoints_check.php';

requirePermission('datainfra.endpoints.editar');

// -------- Configuracion de streaming --------
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
while (ob_get_level() > 0) @ob_end_flush();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

// Cota superior del script: el timeout del endpoint es <= 600s + margen
// para persistencia + red del cliente. Con 640s cubrimos el peor caso.
@set_time_limit(640);

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

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) {
        $log('Falta el id del endpoint.');
        $endJson(['ok' => false, 'error' => 'missing_id']);
    }

    $pdo = db();
    $r   = diepChequearEndpoint($pdo, $id, $log);
    $endJson($r);

} catch (Throwable $e) {
    try {
        registrarSuceso(db(), basename(__FILE__), 'error',
            $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    } catch (Throwable $_) { /* nada */ }
    $log('X Error inesperado: ' . $e->getMessage());
    $endJson(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()]);
}
