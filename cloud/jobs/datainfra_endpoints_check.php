<?php
/**
 * cloud/jobs/datainfra_endpoints_check.php
 * Recorre todos los endpoints con `activo = 1` de `datainfra_endpoints` y
 * corre el health-check contra cada uno. Reusa la funcion
 * `diepChequearEndpoint()` de la lib compartida para mantener la logica
 * (regla de estado, persistencia, historial) en un solo lugar.
 *
 * Deja un suceso por endpoint que falla en la tabla `sucesos`:
 *   - tipo=alerta : el health-check dio `error` o `timeout`.
 *   - Los OK NO se registran como suceso (evitamos el ruido: si todo esta
 *     bien no queremos una alerta cada N minutos por cada endpoint).
 *
 * Los errores por endpoint NO frenan el job: sigue con el proximo.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datainfra_endpoints_check". Corrida sugerida:
 * cada 5 minutos (o menor, segun sensibilidad).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/datainfraendpoints_check.php';

$ORIGEN_SUCESO = 'cron/datainfra_endpoints_check';

try {
    $pdo = db();

    $stmt = $pdo->query('
        SELECT id, nombre
          FROM datainfra_endpoints
         WHERE activo = 1
         ORDER BY id
    ');
    $endpoints = $stmt->fetchAll();
    $total     = count($endpoints);

    anotarLog("Endpoints activos a chequear: {$total}");
    if ($total === 0) {
        marcarEjecucionOk('Sin endpoints activos.');
        exit(0);
    }

    $okCount    = 0;
    $errCount   = 0;
    $toCount    = 0;

    foreach ($endpoints as $i => $ep) {
        $prefix = sprintf('[%d/%d] endpoint #%d (%s)',
            $i + 1, $total, (int)$ep['id'], $ep['nombre'] ?? '');

        anotarLog("{$prefix} - chequeando...");
        try {
            // Silenciamos el log fino: el job resume el resultado en una
            // linea por endpoint y evita saturar la ejecucion.
            $noop = static fn (string $_m) => null;
            $r = diepChequearEndpoint($pdo, (int)$ep['id'], $noop);

            $estado = $r['estado']    ?? 'error';
            $codigo = $r['codigo']    ?? null;
            $tms    = (int)($r['tiempo_ms'] ?? 0);
            $err    = $r['error']     ?? null;
            $codTxt = $codigo === null ? '-' : (string)$codigo;

            if ($estado === 'ok') {
                anotarLog("{$prefix} - OK  HTTP {$codTxt}  ({$tms} ms)");
                $okCount++;
            } elseif ($estado === 'timeout') {
                anotarLog("{$prefix} - TIMEOUT  ({$tms} ms)  {$err}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "Endpoint #{$ep['id']} ({$ep['nombre']}) - TIMEOUT: {$err}"
                );
                $toCount++;
            } else {
                anotarLog("{$prefix} - ERROR  HTTP {$codTxt}  ({$tms} ms)  {$err}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "Endpoint #{$ep['id']} ({$ep['nombre']}) - ERROR HTTP {$codTxt}: {$err}"
                );
                $errCount++;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            anotarLog("{$prefix} - excepcion: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "Endpoint #{$ep['id']} ({$ep['nombre']}) - excepcion: {$msg}"
            );
            $errCount++;
        }
    }

    $resumen = "{$okCount} OK | {$errCount} con error | {$toCount} timeout | {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
