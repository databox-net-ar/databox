<?php
// api/movistarsims_sync.php
// Sincroniza el inventario de SIMs Movistar desde Kite Platform.
// Pagina GET /Inventory/v13/r12/sim (maxBatchSize=1000) respetando el limite
// de 4 TPS documentado por Kite para esa operacion, y hace UPSERT sobre la
// tabla `movistarsims` por ICCID (UNIQUE KEY uk_movistarsims_icc). Idempotente.
//
// Consume:
//   POST api/movistarsims_sync.php   (sin body)
//
// Respuesta:
//   200 {ok:true, data:{fetched, insertados, actualizados, paginas, duracion_ms, ultima_sync}}
//   500 en caso de error de handshake / API Kite / DB.
//
// Requiere en el .env:
//   KITE_API_HOST, KITE_API_PORT, KITE_CERT_PATH, KITE_KEY_PATH, KITE_CERT_PASS.
//
// El nucleo del sync (kiteConfig / kiteGet / kiteSyncSims / mapKiteSim) vive
// en api/lib/movistarsims_kite.php y es reutilizado por el job
// cloud/jobs/movistarsims_actualizar.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/movistarsims_kite.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jsonError('Metodo no soportado', 405);

try {
    requirePermission('plataformas.movistar.sims.sincronizar');
    $cfg   = kiteConfig();
    $t0    = microtime(true);
    $stats = kiteSyncSims($cfg, db());
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);
    jsonOk($stats);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
