<?php
/**
 * cloud/jobs/movistarsims_actualizar.php
 * Sincroniza el inventario de SIMs Movistar contra Kite Platform (mTLS) y
 * hace UPSERT sobre la tabla `movistarsims`. Es el equivalente automatico
 * al boton "Sincronizar" del ABM de SIMs Movistar (que llama a
 * POST api/movistarsims_sync.php).
 *
 * Reutiliza el mismo nucleo que el endpoint: api/lib/movistarsims_kite.php.
 *
 * Deja un unico suceso al terminar:
 *   - tipo=info   : Kite respondio OK, upsert completo.
 *   - tipo=error  : cualquier fallo (config faltante, curl, HTTP, DB...).
 *
 * Requiere en el .env:
 *   KITE_API_HOST, KITE_API_PORT, KITE_CERT_PATH, KITE_KEY_PATH, KITE_CERT_PASS.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "movistarsims_actualizar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/movistarsims_kite.php';

$ORIGEN_SUCESO = 'cron/movistarsims_actualizar';

try {
    $pdo = db();

    anotarLog('Leyendo configuracion Kite...');
    $cfg = kiteConfig();

    anotarLog('Sincronizando inventario contra Kite Platform (mTLS)...');
    $t0    = microtime(true);
    $stats = kiteSyncSims($cfg, $pdo);
    $stats['duracion_ms'] = (int) round((microtime(true) - $t0) * 1000);

    $resumen = sprintf(
        '%d SIMs (%d nuevas, %d actualizadas) en %d paginas — %d ms',
        (int)$stats['fetched'],
        (int)$stats['insertados'],
        (int)$stats['actualizados'],
        (int)$stats['paginas'],
        (int)$stats['duracion_ms']
    );
    anotarLog("Finalizado: {$resumen}");
    registrarSuceso($pdo, $ORIGEN_SUCESO, 'info', "Movistar SIMs sincronizadas: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    $msg = $e->getMessage();
    anotarLog('ERROR fatal: ' . $msg);
    try {
        registrarSuceso(db(), $ORIGEN_SUCESO, 'error', "Movistar SIMs sync fallo: {$msg}");
    } catch (Throwable $_) { /* nada */ }
    marcarEjecucionError($e);
    throw $e;
}
