<?php
/**
 * cloud/jobs/datacount_comprobantes_autorizar.php
 * Cron worker que autoriza contra AFIP los comprobantes fiscales pendientes
 * de `datacount_comprobantes` disparando el microservicio v4:
 *
 *   POST http://localhost:8114/v4/arca/autorizar  (Bearer con apikey de `aplicaciones`)
 *
 * Selecciona comprobantes con:
 *   fiscal = '1'                   (los no-fiscales -- prefacturas, presupuestos, remitos X -- no van a AFIP)
 *   estado = '2'                   (Pendiente segun DC_TRANSICIONES_ESTADO)
 *
 * `caeres` NO se filtra -- un valor previo (transitorio o permanente) es solo
 * evidencia del ultimo intento; no bloquea reintentos. La proteccion contra
 * bucles vive en el circuit breaker (ver mas abajo): un permanente detiene el
 * motor, y no vuelve a correr hasta que un humano rehabilite despues de
 * arreglar la causa del fallo.
 *
 * Los `tipo` fiscales admitidos son facturas (FA/FB/FC/FM) y notas de credito
 * (NA/NB/NC/NM). Para las NC, AFIP exige `cbtes_asoc` con los datos del
 * comprobante que se esta acreditando -- los tomamos del comprobante apuntado
 * por la columna `asociado`, que debe estar autorizado (estado='3') y ser una
 * factura fiscal. Si falta o no esta autorizado, se cierra con error permanente.
 *
 * Por corrida procesa hasta DCC_AUT_LOTE comprobantes (arranca en 1; el cron
 * define la cadencia). El microservicio autonumera el CbteNro pidiendo el
 * ultimo autorizado + 1 -- no le mandamos cbte_desde/hasta.
 *
 * Reglas de cierre por comprobante (ver cerrarConError + esTransitorio):
 *   OK:    estado='3', caenro=CAE, caevto=CAEFchVto, serie=cbte_nro,
 *          autorizado=NOW(), caeres="OK CAE <n>"
 *   ERR TRANSITORIO (AFIP/Apache caidos, TA stale, timeout de red):
 *          NO se toca caeres -- el proximo tick reintenta el mismo comprobante.
 *          Suceso 'alerta' asi el operador ve si AFIP esta flaky por horas.
 *   ERR PERMANENTE (bug de armado, empresa mal configurada, rechazo AFIP):
 *          se graba caeres con el mensaje Y se DETIENE el motor entero
 *          (parametros.datacount.comprobantes.autorizar='0'). El cron no
 *          vuelve a autorizar hasta que un humano reactive desde el ABM
 *          de Parametros (o Herramientas > motor). Suceso 'error'.
 *
 * El microservicio tambien registra cada intento en `arca_autorizaciones`, por
 * lo que la auditoria tecnica vive alli; aca solo dejamos rastro en el log del
 * cron y en `sucesos` con el resumen humano.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datacount_comprobantes_autorizar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/parametros.php';
require_once __DIR__ . '/../api/lib/datacount_comprobantes_autorizar.php';

// Canal unico de autorizacion: TODA la logica de armar el body, llamar al
// v4 y cerrar el comprobante en BD vive en la lib compartida arriba
// (dccAutAutorizar). Este cron es solo el orquestador batch: selecciona
// pendientes, itera y aplica el circuit breaker segun clasifique el error.
// El endpoint manual (api/datacount_comprobantes.php action=autorizar)
// usa la misma lib -- no puede haber divergencia entre ambos caminos.

const DCC_AUT_LOTE = 1;     // batch size por corrida (subir cuando se estabilice).

// Flag booleano de activacion del motor (tabla `parametros`).
//   '1' = HABILITADO. El cron procesa normalmente.
//   cualquier otro valor = DETENIDO. El cron se saltea la corrida hasta
//                                    que alguien restablezca el valor a '1'
//                                    desde el ABM de Parametros.
const DCC_AUT_FLAG = 'datacount.comprobantes.autorizar';

$ORIGEN = 'cron/datacount_comprobantes_autorizar';

try {
    $pdo = db();

    // Sembrar el parametro si no existe (fallback si la migracion nunca corrio).
    parametroAsegurar($pdo, DCC_AUT_FLAG, '1',
        'Motor de autorizacion automatica de comprobantes AFIP. ' .
        'Valor 1 = habilitado; cualquier otro valor = detenido (pausa manual). ' .
        'Ver cloud/jobs/datacount_comprobantes_autorizar.php.');

    // Gate manual: si el operador dejo el flag en algo distinto de '1',
    // el motor esta DETENIDO y no procesa nada hasta ser rehabilitado
    // desde el ABM de Parametros.
    $flag = parametroLeer($pdo, DCC_AUT_FLAG, '1');
    if ($flag !== '1') {
        $msg = "Flag " . DCC_AUT_FLAG . " en '{$flag}' (DETENIDO), pausa manual.";
        anotarLog($msg);
        marcarEjecucionOk($msg);
        return;
    }

    // Apikey para el loopback al v4 (mismo patron que telegramcanales/telegram_mensajes).
    $apikey = dccAutObtenerApikey($pdo);
    if ($apikey === null) {
        $msg = 'No hay ninguna aplicacion habilitada con apikey para el loopback';
        registrarSuceso($pdo, $ORIGEN, 'error', $msg);
        marcarEjecucionError(new RuntimeException($msg));
        exit(1);
    }

    $pendientes = seleccionarPendientes($pdo, DCC_AUT_LOTE);
    $total      = count($pendientes);
    anotarLog('Comprobantes pendientes a autorizar: ' . $total . ' (lote=' . DCC_AUT_LOTE . ')');

    if ($total === 0) {
        marcarEjecucionOk('sin pendientes');
        return;
    }

    $okC = 0; $errC = 0;
    $motorDetenido = false;
    foreach ($pendientes as $i => $c) {
        $id     = (int)$c['id'];
        $prefix = sprintf('[%d/%d] cbte#%d', $i + 1, $total, $id);
        try {
            $ok = autorizarUno($pdo, $apikey, $id, $ORIGEN, $prefix);
            $ok ? $okC++ : $errC++;
        } catch (Throwable $e) {
            // Ultimo dique: si algo escapa del handler por-comprobante lo pasamos
            // igual por la clasificacion transitorio/permanente para que decida
            // si detiene el motor o solo suma al contador de errores.
            $errC++;
            cerrarConError($pdo, $id, 'excepcion no controlada: ' . $e->getMessage(), $ORIGEN, $prefix);
        }
        // Si el ultimo error fue permanente, cerrarConError ya puso el flag en
        // '0'. Cortamos el lote -- el proximo tick ni siquiera va a arrancar
        // hasta que un humano rehabilite desde el ABM.
        if (parametroLeer($pdo, DCC_AUT_FLAG, '1') !== '1') {
            $motorDetenido = true;
            anotarLog('Motor DETENIDO durante la corrida, cortando el lote.');
            break;
        }
    }

    $resumen = "{$okC} OK / {$errC} err / {$total} total"
             . ($motorDetenido ? ' -- MOTOR DETENIDO' : '');
    anotarLog("Finalizado: {$resumen}");
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ============================================================================
// Seleccion + orquestacion (concerns del cron, no de la autorizacion)
// ============================================================================

function seleccionarPendientes(PDO $pdo, int $lote): array {
    // Autorizables = fiscal='1' AND estado='2'. Punto. No filtramos por `caeres`
    // ni por `tipo`:
    //   - `caeres` es solo evidencia del ultimo intento; no debe bloquear el
    //     reintento. La proteccion contra bucles infinitos vive en el circuit
    //     breaker (permanente detiene el motor -- flag='0' -- y no vuelve
    //     hasta que un humano rehabilite despues de arreglar la causa).
    //   - `tipo` se valida dentro de dccAutAutorizar via DCC_TIPO_A_AFIP: si
    //     aparece un codigo sin mapeo cae como error permanente con mensaje
    //     claro y detiene el motor -- mejor que saltearlo silenciosamente.
    $limite = max(1, (int)$lote);
    $st = $pdo->prepare("
        SELECT c.id
          FROM datacount_comprobantes c
         WHERE c.fiscal = '1'
           AND c.estado = '2'
         ORDER BY c.id ASC
         LIMIT {$limite}
    ");
    $st->execute();
    return $st->fetchAll();
}

/**
 * Wrapper del cron sobre la lib compartida `dccAutAutorizar`. Carga el
 * comprobante, delega la autorizacion a la lib, y aplica el circuit breaker
 * del cron sobre el resultado (transitorio -> reintenta, permanente -> frena).
 *
 * NO hay logica de armado del body ni de POST aca -- todo eso vive en la lib
 * para garantizar que cron y endpoint manual usen exactamente el mismo camino.
 */
function autorizarUno(PDO $pdo, string $apikey, int $id, string $origen, string $prefix): bool {
    $c = dccAutLoadComprobante($pdo, $id);
    if ($c === null) {
        // El comprobante desaparecio entre el SELECT y este load (borrado?).
        // No es transitorio ni permanente en sentido AFIP -- solo lo saltamos.
        anotarLog("{$prefix} - SKIP: comprobante desaparecio");
        return false;
    }

    $slug   = trim((string)($c['empresa_slug'] ?? ''));
    $tipoCod = strtoupper(trim((string)($c['tipo'] ?? '')));
    anotarLog(sprintf(
        '%s empresa=%s tipo=%s pto=%d total=%.2f -> POST /v4/arca/autorizar',
        $prefix, $slug, $tipoCod, (int)($c['punto'] ?? 0), (float)($c['total'] ?? 0)
    ));

    $r = dccAutAutorizar($pdo, $apikey, $c);
    if (!$r['ok']) {
        return cerrarConError($pdo, $id, (string)$r['error'], $origen, $prefix);
    }

    registrarSuceso($pdo, $origen, 'info',
        "cbte#{$id} autorizado - CAE={$r['cae']} nro={$r['cbte_nro']} vto={$r['cae_vto']}");
    anotarLog("{$prefix} - OK CAE={$r['cae']} nro={$r['cbte_nro']} vto={$r['cae_vto']}");
    return true;
}

/**
 * Circuit breaker del cron: clasifica el error y actua.
 *
 *   TRANSITORIO (AFIP/Apache caidos, TA stale, timeout de red):
 *     - NO se graba `caeres` -- el proximo tick reintenta el mismo comprobante.
 *     - Se deja rastro en `sucesos` como 'alerta'.
 *
 *   PERMANENTE (bug de armado, empresa mal configurada, rechazo semantico AFIP):
 *     - Se graba `caeres` con el mensaje (evidencia para el humano).
 *     - Se DETIENE el motor -- parametros.datacount.comprobantes.autorizar='0'.
 *     - Se registra el suceso como 'error'.
 *
 * Este comportamiento es EXCLUSIVO del cron. El endpoint manual usa la misma
 * lib de autorizacion pero NO invoca cerrarConError -- muestra un toast y ya.
 *
 * Devuelve false siempre (para que el caller sume al contador de errores).
 */
function cerrarConError(PDO $pdo, int $id, string $msg, string $origen, string $prefix): bool {
    if (dccAutEsTransitorio($msg)) {
        registrarSuceso($pdo, $origen, 'alerta',
            "cbte#{$id}: reintento por error transitorio: {$msg}");
        anotarLog("{$prefix} - TRANSITORIO (reintentable): {$msg}");
        return false;
    }
    dccAutMarcarCaeres($pdo, $id, $msg);
    parametroEscribir($pdo, DCC_AUT_FLAG, '0');
    registrarSuceso($pdo, $origen, 'error',
        "cbte#{$id}: MOTOR DETENIDO por error permanente: {$msg}");
    anotarLog("{$prefix} - PERMANENTE: {$msg} -- MOTOR DETENIDO");
    return false;
}
