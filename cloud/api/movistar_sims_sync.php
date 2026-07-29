<?php
// api/movistar_sims_sync.php
// Sincroniza el inventario de SIMs Movistar desde Kite Platform.
// Pagina GET /Inventory/v13/r12/sim (maxBatchSize=1000) respetando el limite
// de 4 TPS documentado por Kite para esa operacion, y hace UPSERT sobre la
// tabla `movistar_sims` por ICCID (UNIQUE KEY uk_movistar_sims_icc). Idempotente.
//
// Consume:
//   POST api/movistar_sims_sync.php               -> JSON compacto (modo legacy)
//   POST api/movistar_sims_sync.php?stream=1      -> text/plain linea por linea
//
// Modo streaming (?stream=1):
//   Cada linea es un JSON: {"tipo":"info|ok|warn|error","mensaje":"..."}.
//   La ultima linea es: ___END___ {resumenJson} (con contadores + errores).
//   Permite mostrar el progreso en un modal con log en vivo (ver sincronizarMsim
//   en assets/js/app.js).
//
// Respuesta modo JSON:
//   200 {ok:true, data:{fetched, insertados, actualizados, paginas, duracion_ms, ultima_sync}}
//   500 en caso de error de handshake / API Kite / DB.
//
// Requiere en el .env:
//   KITE_API_HOST, KITE_API_PORT, KITE_CERT_PATH, KITE_KEY_PATH, KITE_CERT_PASS.
//
// El nucleo del sync (kiteConfig / kiteGet / kiteSyncSims / mapKiteSim) vive
// en api/lib/movistar_sims_kite.php y es reutilizado por el job
// cloud/jobs/movistar_sims_actualizar.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/movistar_sims_kite.php';
require_once __DIR__ . '/lib/sucesos.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$stream = isset($_GET['stream']) && $_GET['stream'] !== '' && $_GET['stream'] !== '0';

if (!$stream) {
    header('Content-Type: application/json; charset=utf-8');
}

requireAuth();

if ($method !== 'POST') {
    if ($stream) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(405);
        echo "___END___ " . json_encode(['ok' => false, 'error' => 'Metodo no soportado']);
        exit;
    }
    jsonError('Metodo no soportado', 405);
}

if (!$stream) {
    // Modo legacy JSON — sin streaming, todavia usado por integraciones que
    // esperan la respuesta compacta.
    try {
        requirePermission('plataformas.movistar.sims.sincronizar');
        $cfg   = kiteConfig();
        $t0    = microtime(true);
        $pdo   = db();
        $stats = kiteSyncSims($cfg, $pdo);
        $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
        // Suceso 'alerta' cuando aparecen SIMs desaparecidas del origen para
        // que quede rastro en el Visor de sucesos incluso cuando la sync se
        // dispara desde el endpoint (no solo desde el job).
        $ausentesNuevos = (int)($stats['ausentes_nuevos'] ?? 0);
        if ($ausentesNuevos > 0) {
            $iccs = array_slice((array)($stats['ausentes_iccs'] ?? []), 0, 500);
            $detalle = "Movistar: {$ausentesNuevos} SIMs no aparecieron en el origen y quedaron marcadas como AUSENTE (no se eliminaron).\n"
                     . "ICCs:\n" . implode("\n", $iccs);
            registrarSuceso($pdo, 'api/movistar_sims_sync', 'alerta', $detalle);
        }
        jsonOk($stats);
    } catch (Throwable $e) {
        jsonError($e->getMessage(), 500);
    }
    exit;
}

// -----------------------------------------------------------------------------
// Modo streaming: text/plain linea por linea.
// -----------------------------------------------------------------------------
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');       // deshabilita el buffering de nginx
header('Cache-Control: no-cache');
// Vaciar cualquier buffer PHP para que cada echo salga inmediato.
while (ob_get_level() > 0) { @ob_end_flush(); }
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');

$errores = [];
$emit = static function (string $tipo, string $mensaje, array $extra = []) use (&$errores): void {
    $payload = array_merge(['tipo' => $tipo, 'mensaje' => $mensaje], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @flush();
    if ($tipo === 'error') $errores[] = $mensaje;
};

try {
    requirePermission('plataformas.movistar.sims.sincronizar');
    $emit('info', 'Verificando configuracion Kite (mTLS)...');
    $cfg = kiteConfig();
    $emit('ok',   'Configuracion OK. Iniciando sincronizacion.');

    $t0    = microtime(true);
    $pdo   = db();
    $stats = kiteSyncSims($cfg, $pdo, $emit);
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    // Suceso 'alerta' cuando aparecen SIMs desaparecidas del origen: el emit
    // ya se lo dice al usuario en el modal, pero conviene dejar la traza
    // permanente en el Visor de sucesos para poder auditarla despues.
    $ausentesNuevos = (int)($stats['ausentes_nuevos'] ?? 0);
    if ($ausentesNuevos > 0) {
        $iccs = array_slice((array)($stats['ausentes_iccs'] ?? []), 0, 500);
        $detalle = "Movistar: {$ausentesNuevos} SIMs no aparecieron en el origen y quedaron marcadas como AUSENTE (no se eliminaron).\n"
                 . "ICCs:\n" . implode("\n", $iccs);
        registrarSuceso($pdo, 'api/movistar_sims_sync', 'alerta', $detalle);
    }

    $ausentesNuevos = (int)($stats['ausentes_nuevos'] ?? 0);
    $resumen = sprintf(
        'Listo: %d SIMs (%d nuevas, %d actualizadas, %d con trafico nuevo, %d marcadas AUSENTE). Duracion: %.1f s.',
        (int)$stats['fetched'],
        (int)$stats['insertados'],
        (int)$stats['actualizados'],
        (int)$stats['con_trafico'],
        $ausentesNuevos,
        $stats['duracion_ms'] / 1000
    );
    $emit(count($errores) === 0 ? 'ok' : 'warn', $resumen);

    $final = [
        'ok'       => true,
        'errores'  => count($errores),
        'stats'    => $stats,
    ];
    echo "___END___ " . json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @flush();
} catch (Throwable $e) {
    $emit('error', 'Fallo fatal: ' . $e->getMessage());
    $final = [
        'ok'       => false,
        'errores'  => count($errores),
        'error'    => $e->getMessage(),
    ];
    echo "___END___ " . json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @flush();
}
