<?php
/**
 * cloud/jobs/evolution_mensajes_enviar.php
 * Cron worker que despacha los mensajes pendientes de `evolution_mensajes`
 * contra Evolution API (WhatsApp). Solo se ocupa de la SELECCION (que
 * mensaje mandar en que momento) y el THROTTLING por canal. El despacho en
 * si (lock optimista, HTTP, persistencia, veto) vive en la lib compartida
 * `cloud/api/lib/mensajes_enviar.php` — que tambien usa el endpoint POST
 * de envio manual desde el ABM (cloud/api/evolutionmensajes_enviar.php).
 *
 * Cadena de prioridades (variante anti-baneo del legacy `datarocketPostman.php`):
 *   - prioridades 5-4-3-2    : uno por canal cuyo `intervaloCorto` ya vencio;
 *                              dentro del canal gana el de mayor prioridad.
 *   - prioridad 1 (muy baja) : uno por canal cuyo `intervaloLargo` ya vencio.
 *   - MAX_POR_CORRIDA        : cap duro absoluto de envios exitosos por corrida
 *                              (defensa contra rafagas si hay muchos pendientes).
 *
 * Flag de activacion `evolution.mensajes.enviar` (tabla `parametros`, tri-estado):
 *   '0' = DETENIDO   (pausa manual desde el listado de mensajes > menu motor;
 *                     el worker se saltea la corrida sin importar si hay
 *                     pendientes. Solo se sale con "Iniciar motor" desde
 *                     el UI).
 *   '1' = ESPERANDO  (cola vacia; el worker se saltea, ahorra el SELECT
 *                     hasta que alguien encole).
 *   '2' = ENVIANDO   (hay pendientes; el worker procesa hasta MAX_POR_CORRIDA
 *                     y self-heal decide si sigue).
 *
 * Transiciones:
 *   - Encoladores (encolarEvolutionMensaje) suben a '2' salvo que este '0'.
 *   - Este job al terminar: '2' si quedan pendientes, '1' si drenamos.
 *     NUNCA sobreescribe '0' (respeta la pausa manual, incluso si alguien
 *     la activo mid-run).
 *   - UI (menu motor del listado) permite alternar 0 <-> 2.
 *
 * Nota: a diferencia del legacy, prioridad 5 SI respeta `intervaloCorto`. Antes
 * "prioridad 5" mandaba todos los pendientes de golpe, lo que provoca baneo de
 * WhatsApp cuando entra un lote grande. Sigue siendo prioridad maxima porque
 * cuando un canal esta habilitado para enviar, se elige el pendiente de mayor
 * prioridad primero (`ORDER BY prioridad DESC`).
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "evolution_mensajes_enviar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/mensajes_enviar.php';
require_once __DIR__ . '/../api/lib/parametros.php';

const MAX_POR_CORRIDA = 20;   // cap duro de envios exitosos por corrida (anti-baneo)
const FLAG_ENVIAR     = 'evolution.mensajes.enviar';

$ORIGEN = 'cron/evolution_mensajes_enviar';

try {
    $pdo = db();

    // Sembrar el parametro si no existe (fallback si la migracion nunca corrio).
    // El comentario lo mantiene la migracion 20260725_1700, aca dejamos un
    // resumen minimo para el ABM de Parametros.
    parametroAsegurar($pdo, FLAG_ENVIAR, '1',
        'Motor de envio de mensajes Evolution (tri-estado). ' .
        "0 = detenido (pausa manual); 1 = esperando (cola vacia); " .
        "2 = enviando (hay pendientes). " .
        'Ver cloud/jobs/evolution_mensajes_enviar.php.');

    // Gate del worker segun el tri-estado del flag:
    //   '0' -> pausa manual, salir sin tocar nada.
    //   '1' -> cola vacia (o eso creemos), salir; wake-on-demand SELECT-saving.
    //   '2' -> hay trabajo, seguir.
    // Cualquier otro valor (basura) lo tratamos como '1' — defensivo.
    $flag = parametroLeer($pdo, FLAG_ENVIAR, '1');
    if ($flag === '0') {
        $msg = "Flag " . FLAG_ENVIAR . " en '0' (DETENIDO), pausa manual.";
        anotarLog($msg);
        marcarEjecucionOk($msg);
        exit(0);
    }
    if ($flag !== '2') {
        $msg = "Flag " . FLAG_ENVIAR . " en '{$flag}' (ESPERANDO), sin trabajo.";
        anotarLog($msg);
        marcarEjecucionOk($msg);
        exit(0);
    }

    $r = despacharEvolution($pdo, $ORIGEN);

    // Self-heal del flag: la seleccion es "1 mensaje por canal por tick"
    // (anti-baneo), asi que despues de una corrida es habitual que aun
    // queden pendientes. Contamos y decidimos:
    //   - quedan > 0 -> flag='2', el proximo tick del cron sigue drenando.
    //   - quedan = 0 -> flag='1', el cron se saltea la cola hasta que alguien
    //                   encole (encolarEvolutionMensaje() vuelve a subirlo).
    // NUNCA sobreescribimos '0': si el operador pauso mid-run, la pausa
    // manda — su decision explicita gana sobre el proximo tick del cron.
    $quedanPendientes = contarPendientes($pdo);
    $flagFinal        = $quedanPendientes > 0 ? '2' : '1';
    $flagActual       = parametroLeer($pdo, FLAG_ENVIAR, $flagFinal);
    if ($flagActual !== '0') {
        parametroEscribir($pdo, FLAG_ENVIAR, $flagFinal);
    } else {
        $flagFinal = '0'; // solo para el log
    }

    $resumen = "Evolution: {$r['ok']} OK / {$r['err']} err / {$r['skip']} skip"
             . " | quedan {$quedanPendientes} pendientes | flag={$flagFinal}";
    anotarLog('Finalizado: ' . $resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ----------------------------------------------------------------------------
// Orquestacion: seleccion por prioridades + throttling por canal
// ----------------------------------------------------------------------------

/**
 * `evolution_canales.ultimo` guarda ms unix del ultimo envio (lo actualiza
 * la lib compartida, tanto para cron como para envios manuales). El
 * throttling compara `ahora - ultimo` contra intervaloCorto/intervaloLargo.
 */
function despacharEvolution(PDO $pdo, string $origen): array {
    $ok = 0; $err = 0; $skip = 0;
    $cap = (int) MAX_POR_CORRIDA;

    // -- 0) Cleanup de huerfanos ------------------------------------------------
    // Los queries de seleccion filtran por `evolution_canales.habilitado = '1'`,
    // asi que un pendiente cuyo canal esta deshabilitado (o cuyo canal ya no
    // existe) queda huerfano: nunca se elige, pero cuenta en contarPendientes()
    // y mantiene al motor en '2' indefinidamente. Los damos por perdidos con
    // estado='error' para que el ABM los muestre y el operador decida
    // (re-encolar, cambiar de canal, anular). El JOIN filtra por habilitado,
    // asi que la fila derecha queda NULL tanto para deshabilitados como para
    // canal_id inexistente — ambos casos entran al UPDATE.
    $cleanup = $pdo->prepare("
        UPDATE evolution_mensajes m
          LEFT JOIN evolution_canales c
                 ON c.id = m.canal_id
                AND c.habilitado = '1'
           SET m.estado = 'error',
               m.error  = 'Canal deshabilitado o inexistente al despachar'
         WHERE m.estado = 'pendiente'
           AND c.id IS NULL
    ");
    $cleanup->execute();
    $huerfanos = $cleanup->rowCount();
    if ($huerfanos > 0) {
        anotarLog("Cleanup: {$huerfanos} pendientes marcados como error (canal desh/inexistente)");
        registrarSuceso($pdo, $origen, 'alerta',
            "{$huerfanos} mensajes Evolution marcados como error: canal deshabilitado o inexistente");
    }

    // -- 1) Prioridades 5-4-3-2: throttle por intervaloCorto ---------------
    // Un mensaje por canal habilitado cuyo `intervaloCorto` ya vencio. Al
    // haber un solo despacho por canal por tick, aunque haya 100 pendientes
    // prioridad 5 se envian a razon de 1 por canal por tick del cron (anti-baneo).
    $ahoraMs = (int) round(microtime(true) * 1000);
    $canales = $pdo->prepare("
        SELECT id
          FROM evolution_canales
         WHERE habilitado = '1'
           AND (:ahora - COALESCE(ultimo, 0)) > COALESCE(intervaloCorto, 0)
         ORDER BY id
    ");
    $canales->execute([':ahora' => $ahoraMs]);
    foreach ($canales->fetchAll() as $c) {
        if ($ok >= $cap) break;
        // ORDER BY prioridad DESC en el SELECT: si hay 5 y 3 pendientes,
        // gana el 5. Asi 5 mantiene su semantica de "urgente" sin saltearse
        // el throttle.
        $m = obtenerMensajePendienteCanal($pdo, (int)$c['id'], [2, 3, 4, 5]);
        if (!$m) continue;
        $res = despacharUno($pdo, (int)$m['id'], $origen);
        $res['ok'] ? $ok++ : ($res['skip'] ? $skip++ : $err++);
    }

    // -- 2) Prioridad 1: throttle por intervaloLargo (mucho mas espaciado) --
    $ahoraMs = (int) round(microtime(true) * 1000);
    $canales = $pdo->prepare("
        SELECT id
          FROM evolution_canales
         WHERE habilitado = '1'
           AND (:ahora - COALESCE(ultimo, 0)) > COALESCE(intervaloLargo, 0)
         ORDER BY id
    ");
    $canales->execute([':ahora' => $ahoraMs]);
    foreach ($canales->fetchAll() as $c) {
        if ($ok >= $cap) break;
        $m = obtenerMensajePendienteCanal($pdo, (int)$c['id'], [1]);
        if (!$m) continue;
        $res = despacharUno($pdo, (int)$m['id'], $origen);
        $res['ok'] ? $ok++ : ($res['skip'] ? $skip++ : $err++);
    }

    return ['ok' => $ok, 'err' => $err, 'skip' => $skip];
}

function obtenerMensajePendienteCanal(PDO $pdo, int $canal, array $prioridades): ?array {
    $inList = implode(',', array_map('intval', $prioridades));
    $st = $pdo->prepare("
        SELECT id
          FROM evolution_mensajes
         WHERE estado    = 'pendiente'
           AND canal_id  = :canal
           AND prioridad IN ($inList)
         ORDER BY prioridad DESC, id
         LIMIT 1
    ");
    $st->execute([':canal' => $canal]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Wrapper delgado sobre la lib compartida — despacha un mensaje y anota una
 * linea de log con el resultado (la lib no logea porque tambien la usa el
 * endpoint web). La lib ya actualiza `evolution_canales.ultimo` en caso OK.
 */
function despacharUno(PDO $pdo, int $id, string $origen): array {
    $r = evolutionMensajeEnviarPorId($pdo, $id, $origen);
    $canal   = (string)($r['canal_nombre'] ?? '');
    $destino = (string)($r['destino']      ?? '');
    if ($r['ok']) {
        anotarLog("evolution#{$id} canal={$canal} -> {$destino} - OK ({$r['formato']})");
    } elseif (!empty($r['skip'])) {
        anotarLog("evolution#{$id} - SKIP ({$r['motivo']})");
    } else {
        anotarLog("evolution#{$id} canal={$canal} -> {$destino} - ERROR: {$r['error']}");
    }
    return $r;
}

function contarPendientes(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM evolution_mensajes WHERE estado = 'pendiente'")->fetchColumn();
}
