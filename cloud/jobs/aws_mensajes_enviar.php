<?php
/**
 * cloud/jobs/aws_mensajes_enviar.php
 * Cron worker que despacha los mensajes pendientes de `aws_mensajes` contra
 * AWS SES via SMTP con STARTTLS + AUTH LOGIN. Solo se ocupa de la SELECCION
 * (que mensaje mandar en que corrida) — el envio en si vive en la lib
 * compartida `cloud/api/lib/mensajes_enviar.php`, que tambien usa el
 * endpoint POST de envio manual desde el ABM (cloud/api/awsmensajes_enviar.php).
 *
 * Flag `parametros.aws.mensajes.enviar` con 3 estados:
 *   '0' = DETENIDO  (gate manual del operador — job se saltea sin tocar la cola)
 *   '1' = ESPERANDO (cola vacia — job se saltea)
 *   '2' = ENVIANDO  (hay pendientes — job procesa)
 *
 * Al terminar la corrida el job hace self-heal RESPETANDO el gate manual:
 *   - cola vacia  -> '1' (via marcarColaAwsVacia, solo si no esta en '0')
 *   - pendientes  -> '2' (via marcarColaAwsConPendientes, idem)
 *
 * Quien mueve el flag entre estados:
 *   - encolar mensaje (POST/PUT del ABM) -> '2' via marcarColaAwsConPendientes
 *   - accion "Iniciar motor" del ABM     -> '2' via iniciarMotorAws
 *   - accion "Detener motor" del ABM     -> '0' via detenerMotorAws
 *   - este job al self-heal              -> '1' o '2' (nunca pisa '0')
 *
 * Estrategia de seleccion: hasta MAX_POR_CORRIDA mensajes por corrida,
 * ordenados por `prioridad DESC, id ASC`.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "aws_mensajes_enviar".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/mensajes_enviar.php';
require_once __DIR__ . '/../api/lib/aws_mensajes.php';   // marcarCola*Aws*() con respeto al gate '0'

const MAX_POR_CORRIDA = 10;
const FLAG_VARIABLE   = 'aws.mensajes.enviar';

$ORIGEN = 'cron/aws_mensajes_enviar';

try {
    $pdo = db();

    // Wake-on-demand: solo procesamos cuando el flag esta en '2' (ENVIANDO).
    // '0' = detenido manual (gate del operador), '1' = cola vacia. Ahorra el
    // SELECT de la cola en la mayoria de los ticks del cron.
    $flagInicial = leerFlag($pdo);
    if ($flagInicial !== '2') {
        $motivo = $flagInicial === '0' ? 'motor detenido' : 'cola vacia';
        anotarLog('Flag ' . FLAG_VARIABLE . "='{$flagInicial}' ({$motivo}), no hay nada que procesar.");
        marcarEjecucionOk("sin trabajo (flag={$flagInicial})");
        return;
    }

    $r = despacharAws($pdo, $ORIGEN);

    // Self-heal del flag (respetando el gate '0' — si el operador paro el
    // motor mientras esta corrida procesaba, no lo pisamos):
    //   cola vacia   -> '1' via marcarColaAwsVacia
    //   pendientes   -> '2' via marcarColaAwsConPendientes (idempotente)
    $quedanPendientes = contarPendientes($pdo);
    if ($quedanPendientes > 0) {
        marcarColaAwsConPendientes($pdo);
    } else {
        marcarColaAwsVacia($pdo);
    }
    $flagFinal = leerFlag($pdo);

    $resumen = "AWS SES: {$r['ok']} OK / {$r['err']} err / {$r['skip']} skip"
             . " | quedan {$quedanPendientes} pendientes | flag={$flagFinal}";
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
        if ($res['ok']) $ok++;
        elseif (!empty($res['skip'])) $skip++;
        else $err++;
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

// ----------------------------------------------------------------------------
// Flag wake-on-demand (`parametros.aws.mensajes.enviar`)
// ----------------------------------------------------------------------------

function leerFlag(PDO $pdo): string {
    $st = $pdo->prepare("SELECT valor FROM parametros WHERE variable = :v LIMIT 1");
    $st->execute([':v' => FLAG_VARIABLE]);
    return (string)($st->fetchColumn() ?: '');
}

function contarPendientes(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM aws_mensajes WHERE estado = 'pendiente'")->fetchColumn();
}
