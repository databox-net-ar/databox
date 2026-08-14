<?php
/**
 * cloud/jobs/dolarhoy_cotizacion_actualizar.php
 * Registra la cotizacion del dolar OFICIAL del dia en `dolarhoy_cotizaciones`.
 *
 * Scrapea https://dolarhoy.com/i/cotizaciones/dolar-oficial reusando el helper
 * `dhRegistrarCotizacionDelDia()` de api/lib/dolarhoy_cotizacion.php — el mismo
 * scraper que consume el endpoint realtime del ABM (api/dolarhoy_realtime.php),
 * asi la logica de parseo vive en un solo lugar.
 *
 * Si ya hay una fila para la fecha de hoy la actualiza en vez de insertar otra:
 * el ABM y el microservicio v4 asumen una cotizacion por dia. Por eso correr
 * seguido no ensucia la serie — cada corrida refresca la fila del dia con el
 * ultimo valor publicado.
 *
 * Deja un unico suceso al terminar:
 *   - tipo=info   : cotizacion registrada o actualizada.
 *   - tipo=error  : dolarhoy.com inalcanzable, markup cambiado o sin venta.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "jobs/dolarhoy_cotizacion_actualizar.php". Corrida: cada hora en
 * punto (`0 * * * *`) — ver
 * cloud/sql/migrations/20260814_1400_tarea_dolarhoy_cotizacion_actualizar.sql
 * (alta) y 20260814_1600_tarea_dolarhoy_cotizacion_cada_hora.sql (frecuencia).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/dolarhoy_cotizacion.php';

$ORIGEN_SUCESO = 'cron/dolarhoy_cotizacion_actualizar';

try {
    $pdo = db();

    $r = dhRegistrarCotizacionDelDia($pdo, null, 'anotarLog');

    $compra  = $r['compra'] !== null ? number_format($r['compra'], 2, ',', '.') : '—';
    $venta   = number_format($r['venta'], 2, ',', '.');
    $verbo   = $r['accion'] === 'update' ? 'actualizada' : 'registrada';
    $resumen = "Cotizacion del {$r['fecha']} {$verbo} (#{$r['id']}): compra \${$compra} | venta \${$venta}";

    anotarLog("Finalizado: {$resumen}");
    registrarSuceso($pdo, $ORIGEN_SUCESO, 'info', $resumen);
    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    $msg = $e->getMessage();
    anotarLog('ERROR fatal: ' . $msg);
    try {
        registrarSuceso(db(), $ORIGEN_SUCESO, 'error', "Cotizacion Dolarhoy fallo: {$msg}");
    } catch (Throwable $_) { /* nada */ }
    marcarEjecucionError($e);
    throw $e;
}
