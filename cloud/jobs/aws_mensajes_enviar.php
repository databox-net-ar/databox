<?php
/**
 * cloud/jobs/aws_mensajes_enviar.php
 * Cron worker que despacha los mensajes pendientes de `aws_mensajes` contra
 * AWS SES via SMTP con STARTTLS + AUTH LOGIN. Solo se ocupa de la SELECCION
 * (que mensaje mandar en que corrida) — el envio en si vive en la lib
 * compartida `cloud/api/lib/mensajes_enviar.php`, que tambien usa el
 * endpoint POST de envio manual desde el ABM (cloud/api/awsmensajes_enviar.php).
 *
 * Estrategia: hasta MAX_POR_CORRIDA mensajes por corrida, ordenados por
 * `prioridad DESC, id ASC`.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "aws_mensajes_enviar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/mensajes_enviar.php';

const MAX_POR_CORRIDA = 10;

$ORIGEN = 'cron/aws_mensajes_enviar';

try {
    $pdo = db();
    $r = despacharAws($pdo, $ORIGEN);
    $resumen = "AWS SES: {$r['ok']} OK / {$r['err']} err / {$r['skip']} skip";
    anotarLog('Finalizado: ' . $resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    anotarLog('ERROR fatal: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

// ----------------------------------------------------------------------------
// Orquestacion: seleccion por prioridad
// ----------------------------------------------------------------------------

function despacharAws(PDO $pdo, string $origen): array {
    $ok = 0; $err = 0; $skip = 0;

    $limite = (int) MAX_POR_CORRIDA;
    $st = $pdo->query("
        SELECT id
          FROM aws_mensajes
         WHERE estado = 'pendiente'
         ORDER BY CAST(COALESCE(prioridad,'3') AS UNSIGNED) DESC, id ASC
         LIMIT {$limite}
    ");
    foreach ($st->fetchAll() as $r) {
        $res = despacharUno($pdo, (int)$r['id'], $origen);
        $res['ok'] ? $ok++ : ($res['skip'] ? $skip++ : $err++);
    }
    return ['ok' => $ok, 'err' => $err, 'skip' => $skip];
}

/**
 * Wrapper delgado sobre la lib compartida — despacha un mensaje y anota una
 * linea de log con el resultado (la lib no logea porque tambien la usa el
 * endpoint web).
 */
function despacharUno(PDO $pdo, int $id, string $origen): array {
    $r = awsMensajeEnviarPorId($pdo, $id, $origen);
    $canal   = (string)($r['canal_nombre'] ?? '');
    $destino = (string)($r['destino']      ?? '');
    if ($r['ok']) {
        anotarLog("aws#{$id} canal={$canal} -> {$destino} - OK ({$r['formato']})");
    } elseif (!empty($r['skip'])) {
        anotarLog("aws#{$id} - SKIP ({$r['motivo']})");
    } else {
        anotarLog("aws#{$id} canal={$canal} -> {$destino} - ERROR: {$r['error']}");
    }
    return $r;
}
