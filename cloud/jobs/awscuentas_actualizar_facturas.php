<?php
/**
 * cloud/jobs/awscuentas_actualizar_facturas.php
 * Recorre todas las cuentas AWS configuradas y actualiza su cache de
 * facturacion (deuda BCM + facturas emitidas Invoicing). Es el unico
 * mecanismo de actualizacion masiva: la UI ya no dispara nada.
 *
 * Deja un suceso por cuenta en la tabla `sucesos`:
 *   - tipo=info   : ambas APIs OK, cache actualizado.
 *   - tipo=alerta : falta configuracion (numero/accesskey/secreto) o falla
 *                   parcial (BCM y/o Invoicing devolvieron error).
 *
 * Los errores por cuenta NO frenan el job: sigue con la proxima.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "awscuentas_actualizar_facturas".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/awscuentas_billing.php';

$ORIGEN_SUCESO = 'cron/awscuentas_facturas';

try {
    $pdo = db();

    $stmt = $pdo->query('
        SELECT id, nombre, numero, usuario, accesskey, secreto
          FROM aws_cuentas
         ORDER BY id
    ');
    $cuentas = $stmt->fetchAll();
    $total   = count($cuentas);

    anotarLog("Cuentas AWS a actualizar: {$total}");
    if ($total === 0) {
        marcarEjecucionOk('Sin cuentas AWS configuradas.');
        exit(0);
    }

    $okCount    = 0;
    $errCount   = 0;
    $skipCount  = 0;

    foreach ($cuentas as $i => $c) {
        $prefix = sprintf('[%d/%d] cuenta #%d (%s)',
            $i + 1, $total, (int)$c['id'], $c['nombre'] ?? '');

        // Cuentas sin credenciales completas no se pueden consultar: se
        // deja constancia como alerta y se sigue con la proxima.
        $faltantes = [];
        if (empty($c['numero']))    $faltantes[] = 'numero';
        if (empty($c['accesskey'])) $faltantes[] = 'accesskey';
        if (empty($c['secreto']))   $faltantes[] = 'secreto';
        if ($faltantes) {
            $msg = 'Sin credenciales completas (falta: ' . implode(', ', $faltantes) . ')';
            anotarLog("{$prefix} - salteada: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "AWS cuenta #{$c['id']} ({$c['nombre']}) - {$msg}"
            );
            $skipCount++;
            continue;
        }

        anotarLog("{$prefix} - consultando AWS...");
        try {
            $r = actualizarBillingCuenta($pdo, $c);
            if ($r['ok']) {
                anotarLog("{$prefix} - OK ({$r['summary']})");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'info',
                    "AWS actualizada {$r['summary']}"
                );
                $okCount++;
            } else {
                $errs = implode(' | ', $r['errores']);
                anotarLog("{$prefix} - parcial: {$errs}");
                registrarSuceso(
                    $pdo, $ORIGEN_SUCESO, 'alerta',
                    "AWS cuenta #{$c['id']} ({$c['nombre']}) - fallo parcial: {$errs}"
                );
                $errCount++;
            }
        } catch (Throwable $e) {
            // Blindaje adicional: la lib normalmente atrapa BCM/Invoicing por
            // separado, pero cualquier fatal inesperado tampoco puede tumbar
            // el resto del recorrido.
            $msg = $e->getMessage();
            anotarLog("{$prefix} - excepcion: {$msg}");
            registrarSuceso(
                $pdo, $ORIGEN_SUCESO, 'alerta',
                "AWS cuenta #{$c['id']} ({$c['nombre']}) - excepcion: {$msg}"
            );
            $errCount++;
        }
    }

    $resumen = "{$okCount} OK | {$errCount} con error | {$skipCount} salteadas | {$total} total";
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
