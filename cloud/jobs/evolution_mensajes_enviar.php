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

const MAX_POR_CORRIDA = 20;   // cap duro de envios exitosos por corrida (anti-baneo)

$ORIGEN = 'cron/evolution_mensajes_enviar';

try {
    $pdo = db();
    $r = despacharEvolution($pdo, $ORIGEN);
    $resumen = "Evolution: {$r['ok']} OK / {$r['err']} err / {$r['skip']} skip";
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
