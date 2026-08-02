<?php
/**
 * cloud/jobs/datacount_comprobantes_notificar.php
 * Cron de retry de webhooks post-autorizacion de `datacount_comprobantes`.
 *
 * =========================================================================
 * Que hace
 * =========================================================================
 * Recorre los comprobantes que ya obtuvieron CAE pero cuyo webhook nunca se
 * pudo notificar exitosamente al receptor externo, y reintenta el POST hasta
 * que el receptor devuelva HTTP 2xx (momento en el que `webhook_estado` pasa
 * de 'pendiente' a 'completado' y el comprobante deja de aparecer aca).
 *
 * =========================================================================
 * Criterio de seleccion (SQL)
 * =========================================================================
 *   estado         = '3'                (Autorizado -- ya tiene CAE)
 *   webhook_url    IS NOT NULL <> ''    (el caller pidio webhook al alta v4)
 *   webhook_estado = 'pendiente'        (todavia no se logro notificar)
 *
 * Orden `id ASC` (los mas viejos primero) para que un comprobante viejo con
 * receptor caido no bloquee a los nuevos: procesamos por lote y todos avanzan.
 *
 * =========================================================================
 * Por que existe (casos que atrapa)
 * =========================================================================
 * Complementa al disparo online que hace el microservicio v4 al obtener el
 * CAE. Los casos que caen aca:
 *
 *   (a) Comprobante autorizado por el cron `datacount_comprobantes_autorizar`
 *       (no por el v4). El cron de autorizacion NO dispara webhook -- el
 *       microservicio v4 solo lo dispara para las altas que pasan por su
 *       flujo sincronico. Los comprobantes que quedaron en '2' (Pendiente)
 *       y despues se autorizaron por el cron llegan al '3' con
 *       `webhook_estado = 'pendiente'` sin que nadie haya intentado el POST.
 *
 *   (b) Comprobante autorizado por el v4 PERO el POST al webhook fallo
 *       (timeout, receptor caido, HTTP 5xx transitorio, DNS). El v4 dejo
 *       `webhook_estado = 'pendiente'` justamente para que este cron lo
 *       reintente.
 *
 *   (c) Comprobante autorizado por el boton "Autorizar" del ABM (mismo canal
 *       que el cron de autorizacion) -- tampoco dispara webhook y cae aca.
 *
 * =========================================================================
 * Canal unico
 * =========================================================================
 * La funcion `dccWebhookDisparar` (cloud/api/lib/datacount_comprobantes_
 * webhook.php) es EXACTAMENTE la misma que llama el microservicio v4. No hay
 * logica de armado del payload ni de POST duplicada aca; este cron es solo el
 * orquestador batch (select + iterar). Cualquier cambio en headers, timeout,
 * shape del body, actualizacion de `webhook_estado` o registro de sucesos se
 * hace una sola vez en la lib y aplica a los dos caminos.
 *
 * =========================================================================
 * Politica de errores
 * =========================================================================
 * NO hay circuit breaker global (a diferencia del cron de autorizacion, que
 * frena el motor ante un permanente). Motivo: los fallos de webhook son
 * por-receptor -- cada comprobante puede apuntar a una URL distinta y un
 * receptor caido no afecta a los demas. Cada comprobante se reintenta de
 * forma independiente en el proximo tick, sin intervencion humana.
 *
 * `dccWebhookDisparar` es best-effort y NO propaga excepciones: cualquier
 * fallo (cURL, HTTP != 2xx) deja `webhook_estado = 'pendiente'` y registra
 * un suceso 'alerta' con el motivo (`HTTP 500`, `cURL: timeout`, etc.). El
 * cron sigue con el siguiente comprobante del lote.
 *
 * =========================================================================
 * Cadencia y batching
 * =========================================================================
 * Por corrida procesa hasta `DCC_WH_LOTE` comprobantes (default 20). No hace
 * falta agresivo -- una vez notificado, el comprobante desaparece del pool.
 * Ajustable segun latencia observada de los receptores.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datacount_comprobantes_notificar", tipico cada 1-2 min.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/datacount_comprobantes_webhook.php';

const DCC_WH_LOTE = 20;  // batch size por corrida (ajustable segun latencia observada).

$ORIGEN = 'cron/datacount_comprobantes_notificar';

try {
    $pdo = db();

    $pendientes = seleccionarPendientesWebhook($pdo, DCC_WH_LOTE);
    $total      = count($pendientes);
    anotarLog('Webhooks pendientes: ' . $total . ' (lote=' . DCC_WH_LOTE . ')');

    if ($total === 0) {
        marcarEjecucionOk('sin pendientes');
        return;
    }

    $okC = 0; $errC = 0;
    foreach ($pendientes as $i => $c) {
        $id     = (int)$c['id'];
        $url    = (string)$c['webhook_url'];
        $prefix = sprintf('[%d/%d] cbte#%d', $i + 1, $total, $id);
        try {
            $payload = dccWebhookBuildPayload($pdo, $id);
            if ($payload === null) {
                // Desaparecio entre el SELECT y el load (borrado?). Lo saltamos.
                anotarLog("{$prefix} - SKIP: comprobante desaparecio");
                $errC++;
                continue;
            }
            anotarLog("{$prefix} -> POST {$url}");
            $estado = dccWebhookDisparar($pdo, $id, $url, $payload, $ORIGEN);
            if ($estado === 'completado') {
                anotarLog("{$prefix} - OK");
                $okC++;
            } else {
                anotarLog("{$prefix} - FAIL (sigue pendiente, se reintenta en el proximo tick)");
                $errC++;
            }
        } catch (Throwable $e) {
            // Ultimo dique -- no deberia pasar porque dccWebhookDisparar es
            // best-effort y no propaga. Si igual escapa algo, lo contamos.
            anotarLog("{$prefix} - EXCEPCION: " . $e->getMessage());
            $errC++;
        }
    }

    $resumen = "{$okC} OK / {$errC} err / {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ============================================================================
// Seleccion
// ============================================================================

function seleccionarPendientesWebhook(PDO $pdo, int $lote): array {
    $limite = max(1, (int)$lote);
    $st = $pdo->prepare("
        SELECT id, webhook_url
          FROM datacount_comprobantes
         WHERE estado = '3'
           AND webhook_estado = 'pendiente'
           AND webhook_url IS NOT NULL
           AND webhook_url <> ''
         ORDER BY id ASC
         LIMIT {$limite}
    ");
    $st->execute();
    return $st->fetchAll();
}
