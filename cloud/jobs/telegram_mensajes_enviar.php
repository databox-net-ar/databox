<?php
/**
 * cloud/jobs/telegram_mensajes_enviar.php
 * Cron worker que despacha los mensajes pendientes de `telegram_mensajes`
 * contra el microservicio MTProto /v4/telegram/mensajes. Solo se ocupa de
 * la SELECCION (que mensaje mandar en que orden) y el CAP por corrida.
 * El despacho concreto (lock optimista, HTTP POST loopback, persistencia)
 * vive en la lib compartida cloud/api/lib/telegram_mensajes_enviar.php --
 * misma que usa el endpoint de envio manual desde el ABM
 * (cloud/api/telegrammensajes_enviar.php).
 *
 * Diferencias con evolution_mensajes_enviar:
 *   - No hay throttle por canal (intervaloCorto/intervaloLargo). Telegram
 *     tiene sus propios rate limits a nivel de cuenta (~30 msg/min por
 *     cuenta usuario) y MadelineProto los respeta internamente.
 *   - Cap duro MAX_POR_CORRIDA = 20 por tick. Con cron cada minuto eso
 *     nos deja ~20 msg/min combinados entre TODOS los canales, bien por
 *     debajo del limite de Telegram por cuenta.
 *   - No hay Bot API aca -- todo va por MTProto (user-mode) via el v4.
 *
 * Flag de activacion `telegram.mensajes.enviar` (tabla `parametros`, tri-estado):
 *   '0' = DETENIDO   (pausa manual desde el ABM > menu motor; salta la
 *                     corrida sin importar si hay pendientes).
 *   '1' = ESPERANDO  (cola vacia; el worker salta la corrida, ahorra el
 *                     SELECT hasta que alguien encole).
 *   '2' = ENVIANDO   (hay pendientes; procesa hasta MAX_POR_CORRIDA y
 *                     self-heal decide si sigue).
 *
 * Transiciones:
 *   - Encoladores (encolarTelegramMensaje) suben a '2' salvo que este '0'.
 *   - Este job al terminar: '2' si quedan pendientes, '1' si drenamos.
 *     NUNCA sobreescribe '0' (respeta la pausa manual).
 *   - UI (menu motor del listado) permite alternar 0 <-> 2 via
 *     cloud/api/telegrammensajes_motor.php.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "telegram_mensajes_enviar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/telegram_mensajes_enviar.php';
require_once __DIR__ . '/../api/lib/parametros.php';

const TG_MAX_POR_CORRIDA = 20;                        // cap duro por corrida
const TG_FLAG_ENVIAR     = 'telegram.mensajes.enviar';

$ORIGEN = 'cron/telegram_mensajes_enviar';

try {
    $pdo = db();

    // Sembrar el parametro si no existe (fallback si la migracion nunca corrio).
    parametroAsegurar($pdo, TG_FLAG_ENVIAR, '1',
        'Motor de envio de mensajes Telegram (tri-estado). ' .
        "0 = detenido (pausa manual); 1 = esperando (cola vacia); " .
        "2 = enviando (hay pendientes). " .
        'Ver cloud/jobs/telegram_mensajes_enviar.php.');

    // Gate del worker segun el tri-estado del flag.
    $flag = parametroLeer($pdo, TG_FLAG_ENVIAR, '1');
    if ($flag === '0') {
        $msg = "Flag " . TG_FLAG_ENVIAR . " en '0' (DETENIDO), pausa manual.";
        anotarLog($msg);
        marcarEjecucionOk($msg);
        exit(0);
    }
    if ($flag !== '2') {
        $msg = "Flag " . TG_FLAG_ENVIAR . " en '{$flag}' (ESPERANDO), sin trabajo.";
        anotarLog($msg);
        marcarEjecucionOk($msg);
        exit(0);
    }

    $r = despacharTelegram($pdo, $ORIGEN);

    // Self-heal del flag:
    //   - quedan > 0 -> '2', el proximo tick sigue drenando.
    //   - quedan = 0 -> '1', el cron se saltea hasta que alguien encole
    //                   (encolarTelegramMensaje sube de vuelta a '2').
    // NUNCA sobreescribimos '0' (pausa manual del operador manda).
    $quedanPendientes = telegramContarPendientes($pdo);
    $flagFinal        = $quedanPendientes > 0 ? '2' : '1';
    $flagActual       = parametroLeer($pdo, TG_FLAG_ENVIAR, $flagFinal);
    if ($flagActual !== '0') {
        parametroEscribir($pdo, TG_FLAG_ENVIAR, $flagFinal);
    } else {
        $flagFinal = '0';
    }

    $resumen = "Telegram: {$r['ok']} OK / {$r['err']} err / {$r['skip']} skip"
             . " | quedan {$quedanPendientes} pendientes | flag={$flagFinal}";
    anotarLog('Finalizado: ' . $resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ----------------------------------------------------------------------------
// Orquestacion: seleccion FIFO por prioridad (sin throttle por canal)
// ----------------------------------------------------------------------------

function despacharTelegram(PDO $pdo, string $origen): array {
    $ok = 0; $err = 0; $skip = 0;

    // -- 0) Cleanup de huerfanos ------------------------------------------
    // Un pendiente cuyo canal esta deshabilitado (o cuyo canal ya no existe)
    // no se puede enviar. Lo marcamos como 'error' con un mensaje explicito
    // asi el operador ve por que quedo bloqueado (y no lo tratamos como
    // pendiente para el flag).
    $cleanup = $pdo->prepare("
        UPDATE telegram_mensajes m
          LEFT JOIN telegram_canales c
                 ON c.id = m.canal_id
                AND (c.habilitado = '1' OR c.habilitado IS NULL)
                AND c.slug IS NOT NULL AND c.slug <> ''
                AND c.telefono IS NOT NULL AND c.telefono <> ''
           SET m.estado = 'error',
               m.error  = 'Canal deshabilitado, inexistente o sin telefono al despachar'
         WHERE m.estado = 'pendiente'
           AND c.id IS NULL
    ");
    $cleanup->execute();
    $huerfanos = $cleanup->rowCount();
    if ($huerfanos > 0) {
        anotarLog("Cleanup: {$huerfanos} pendientes marcados como error (canal desh/inexistente/sin telefono)");
        registrarSuceso($pdo, $origen, 'alerta',
            "{$huerfanos} mensajes Telegram marcados como error: canal deshabilitado o inexistente");
    }

    // -- 1) Iteracion FIFO por prioridad -----------------------------------
    // Seleccion simple: los mas prioritarios primero, dentro de igual
    // prioridad los mas viejos primero. Solo tomamos los ya programados
    // (programado <= NOW()) para respetar el diferido.
    while ($ok + $err < TG_MAX_POR_CORRIDA) {
        $st = $pdo->prepare("
            SELECT m.id
              FROM telegram_mensajes m
              JOIN telegram_canales  c ON c.id = m.canal_id
             WHERE m.estado    = 'pendiente'
               AND (c.habilitado = '1' OR c.habilitado IS NULL)
               AND c.slug     IS NOT NULL AND c.slug     <> ''
               AND c.telefono IS NOT NULL AND c.telefono <> ''
               AND (m.programado IS NULL OR m.programado <= NOW())
             ORDER BY m.prioridad DESC, m.id ASC
             LIMIT 1
        ");
        $st->execute();
        $row = $st->fetch();
        if (!$row) break;

        $r = despacharUnoTelegram($pdo, (int)$row['id'], $origen);
        if ($r['ok']) {
            $ok++;
        } elseif (!empty($r['skip'])) {
            $skip++;
        } else {
            $err++;
        }
    }

    return ['ok' => $ok, 'err' => $err, 'skip' => $skip];
}

function despacharUnoTelegram(PDO $pdo, int $id, string $origen): array {
    $r = telegramMensajeEnviarPorId($pdo, $id, $origen);
    $canal   = (string)($r['canal_nombre'] ?? '');
    $destino = (string)($r['destino']      ?? '');
    if ($r['ok']) {
        $mid = isset($r['message_id']) ? " message_id={$r['message_id']}" : '';
        anotarLog("telegram#{$id} canal={$canal} -> {$destino} - OK{$mid}");
    } elseif (!empty($r['skip'])) {
        anotarLog("telegram#{$id} - SKIP ({$r['motivo']})");
    } else {
        anotarLog("telegram#{$id} canal={$canal} -> {$destino} - ERROR: {$r['error']}");
    }
    return $r;
}

/**
 * Cuenta pendientes que aun estan procesables (canal habilitado con telefono
 * y programado <= NOW()). Se usa para decidir el flag final tras cada corrida
 * -- los que quedan atados a un canal roto ya los marcamos como 'error' en
 * el cleanup, asi que no cuentan.
 */
function telegramContarPendientes(PDO $pdo): int {
    return (int) $pdo->query("
        SELECT COUNT(*)
          FROM telegram_mensajes m
          JOIN telegram_canales  c ON c.id = m.canal_id
         WHERE m.estado = 'pendiente'
           AND (c.habilitado = '1' OR c.habilitado IS NULL)
           AND c.slug     IS NOT NULL AND c.slug     <> ''
           AND c.telefono IS NOT NULL AND c.telefono <> ''
           AND (m.programado IS NULL OR m.programado <= NOW())
    ")->fetchColumn();
}
