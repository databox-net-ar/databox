<?php
/**
 * cloud/jobs/datarocketdominios_actualizar_whois.php
 * Recorre todos los dominios de `datarocket_dominios` y refresca sus datos
 * WHOIS (titular, fechas, entidad registrante, costo estimado). Reusa la
 * funcion `drdoActualizarWhois()` del endpoint HTTP para mantener la
 * logica en un solo lugar.
 *
 * Deja un suceso por dominio en la tabla `sucesos`:
 *   - tipo=info   : consulta OK (con o sin cambios).
 *   - tipo=alerta : WHOIS inalcanzable, dominio libre, no encontrado, etc.
 *
 * Los errores por dominio NO frenan el job: sigue con el proximo.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datarocketdominios_actualizar_whois". Corrida sugerida:
 * diaria, madrugada.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/datarocketdominios_whois.php';

$ORIGEN_SUCESO = 'cron/datarocketdominios_whois';

try {
    $pdo = db();

    $stmt = $pdo->query('
        SELECT id, dominio
          FROM datarocket_dominios
         ORDER BY id
    ');
    $dominios = $stmt->fetchAll();
    $total    = count($dominios);

    anotarLog("Dominios a actualizar: {$total}");
    if ($total === 0) {
        marcarEjecucionOk('Sin dominios cargados.');
        exit(0);
    }

    $okCount     = 0;
    $errCount    = 0;
    $cambiosTot  = 0;

    foreach ($dominios as $i => $d) {
        $prefix = sprintf('[%d/%d] dominio #%d (%s)',
            $i + 1, $total, (int)$d['id'], $d['dominio'] ?? '');

        anotarLog("{$prefix} - consultando WHOIS...");
        try {
            // Silenciamos el log fino del scraper: el job resume el resultado
            // en una linea por dominio y evita saturar la ejecucion con
            // decenas de lineas por cada consulta HTTP.
            $noop = static fn (string $_m) => null;
            $r = drdoActualizarWhois($pdo, (int)$d['id'], $noop);

            if ($r['ok']) {
                $cambios = (int)($r['cambios'] ?? 0);
                $fuente  = $r['fuente'] ?? '?';
                if ($cambios > 0) {
                    anotarLog("{$prefix} - OK via {$fuente} ({$cambios} campo/s actualizado/s)");
                } else {
                    anotarLog("{$prefix} - OK via {$fuente} (sin cambios)");
                }
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'info',
                    "Dominio #{$d['id']} ({$d['dominio']}) - WHOIS OK via {$fuente} ({$cambios} cambios)"
                );
                $okCount++;
                $cambiosTot += $cambios;
            } else {
                $detail = $r['detail'] ?? ($r['error'] ?? 'error desconocido');
                anotarLog("{$prefix} - falla: {$detail}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "Dominio #{$d['id']} ({$d['dominio']}) - WHOIS fallo: {$detail}"
                );
                $errCount++;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            anotarLog("{$prefix} - excepcion: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "Dominio #{$d['id']} ({$d['dominio']}) - excepcion: {$msg}"
            );
            $errCount++;
        }
    }

    $resumen = "{$okCount} OK ({$cambiosTot} cambios) | {$errCount} con error | {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
