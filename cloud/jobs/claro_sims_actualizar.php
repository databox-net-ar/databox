<?php
/**
 * cloud/jobs/clarosims_actualizar.php
 * Dispara la sincronizacion de SIMs Claro dejando la bandera
 * `pedido_clarosims_sincronizar` en "1" (tabla `parametros`), tal como lo
 * hace el boton "Sincronizar" del ABM de SIMs Claro (PUT
 * api/clarosims_sync_pedido.php).
 *
 * IMPORTANTE: este job NO scrapea el portal. El portal
 * https://iotgestion.claro.com.ar/ esta detras de un WAF con fingerprint
 * dinamico que corta cualquier scraper HTTP puro (PHP + cURL). El scraping
 * lo hace el agente externo `openclaw`, con navegador real. openclaw
 * pollea cada 5 min:
 *   POST api/clarosims_sync_pedido.php (con su apikey)
 * y cuando ve la bandera en "1" la consume, scrapea el portal y postea el
 * CSV a POST api/clarosims_sync.php.
 *
 * Reutiliza el mismo helper que el endpoint: api/lib/clarosims_sync_pedido.php.
 *
 * Deja un unico suceso al terminar:
 *   - tipo=info   : pedido marcado (o ya estaba pendiente).
 *   - tipo=error  : fallo en la escritura del parametro.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "clarosims_actualizar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/clarosims_sync_pedido.php';

$ORIGEN_SUCESO = 'cron/clarosims_actualizar';

try {
    $pdo = db();

    anotarLog('Marcando pedido de sync Claro para openclaw...');
    $r = marcarPedidoSyncClaro($pdo);

    $resumen = $r['ya_pendiente']
        ? "Ya habia un pedido pendiente desde {$r['marcado_en']} — openclaw lo levantara en el proximo poll."
        : "Pedido marcado a las {$r['marcado_en']} — openclaw lo levantara en el proximo poll (hasta 5 min).";

    anotarLog("Finalizado: {$resumen}");
    registrarSuceso($pdo, $ORIGEN_SUCESO, 'info', $resumen);
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    $msg = $e->getMessage();
    anotarLog('ERROR fatal: ' . $msg);
    try {
        registrarSuceso(db(), $ORIGEN_SUCESO, 'error', "Claro SIMs pedido fallo: {$msg}");
    } catch (Throwable $_) { /* nada */ }
    marcarEjecucionError($e);
    throw $e;
}
