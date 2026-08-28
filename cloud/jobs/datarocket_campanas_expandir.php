<?php
/**
 * cloud/jobs/datarocket_campanas_expandir.php
 * Cron worker de las campañas Datarocket. Hace dos cosas por corrida:
 *
 *   1. LEVANTA las campañas cuya hora llegó (`estado='programada'` y
 *      `programada <= NOW()`) y las corre: arma el padrón y encola.
 *   2. RECONCILIA las que están `enviando`, para que el padrón se entere de
 *      lo que el motor del canal ya despachó y la campaña pueda cerrarse.
 *
 * El trabajo real vive en cloud/api/lib/datarocket_campanas_expandir.php, que
 * comparte con el endpoint del botón "Ejecutar ahora" del panel. Este archivo
 * es sólo la SELECCIÓN (qué campaña toca en qué corrida) — misma división que
 * aws_mensajes_enviar.php.
 *
 * Es idempotente y reanudable: una campaña que no entra en una corrida queda
 * en `enviando` con pendientes y la siguiente la retoma donde quedó. Por eso el
 * presupuesto de tiempo por campaña es corto — más vale muchas corridas cortas
 * que una que el `timeout` del Programador mate a la mitad.
 *
 * NO envía: encola. El despacho sigue siendo de los motores de cada canal
 * (aws_mensajes_enviar, evolution_mensajes_enviar, telegram_mensajes_enviar),
 * que ya tienen su rate limit y su gate manual.
 *
 * Se registra desde el Programador de tareas (tabla `tareas`) apuntando
 * `script` = "datarocket_campanas_expandir".
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../api/lib/datarocket_campanas_expandir.php';

// Cuántas campañas se levantan por corrida. Una campaña grande consume la
// corrida entera; el resto espera al próximo tick. Ordenadas por prioridad.
const MAX_CAMPANAS_POR_CORRIDA = 3;

// Presupuesto por campaña. Deliberadamente menor que el timeout típico de una
// tarea del Programador: preferimos cortar limpio y reanudar.
const DEADLINE_POR_CAMPANA = 60;

$ORIGEN = 'cron/datarocket_campanas_expandir';

try {
    $pdo = db();

    // --- 1. Campañas cuya hora llegó ---------------------------------------
    // 'expandiendo' queda EXCLUIDO a propósito: es el candado que toma una
    // corrida manual desde el panel. Si el operador está ejecutando la campaña
    // a mano, el cron no se mete en el mismo padrón.
    $lote = MAX_CAMPANAS_POR_CORRIDA;
    $st = $pdo->query("
        SELECT id, nombre
          FROM datarocket_campanas
         WHERE estado = 'programada'
           AND programada IS NOT NULL
           AND programada <= NOW()
         ORDER BY prioridad DESC, programada ASC, id ASC
         LIMIT {$lote}
    ");
    $pendientes = $st->fetchAll();

    $corridas = 0;
    foreach ($pendientes as $c) {
        $id = (int) $c['id'];
        anotarLog("--- Campaña #{$id} \"{$c['nombre']}\" ---");
        try {
            $r = drcaCampanaEjecutar($pdo, $id, function (string $l) { anotarLog($l); },
                                     ['deadline_seg' => DEADLINE_POR_CAMPANA]);
            $corridas++;
            registrarSuceso($pdo, 'datarocket_campanas', 'info',
                "Corrida de campaña #{$id} — encolados {$r['encolados_esta_corrida']}"
                . ", padrón {$r['total']}, estado {$r['estado']}");
        } catch (InvalidArgumentException $e) {
            // La campaña está incompleta (sin lista, sin canal, lista vacía).
            // No es una falla del job: se la saca de la cola dejándola pausada,
            // con el motivo anotado, y se sigue con la siguiente.
            $pdo->prepare("UPDATE datarocket_campanas SET estado = 'pausada' WHERE id = :id")
                ->execute([':id' => $id]);
            anotarLog("Campaña #{$id} pausada: " . $e->getMessage());
            registrarSuceso($pdo, 'datarocket_campanas', 'alerta',
                "Campaña #{$id} pausada al intentar largarla: " . $e->getMessage());
        }
    }

    // --- 2. Reconciliar las que están en vuelo ------------------------------
    // Sin este paso una campaña se queda en 'enviando' para siempre: el motor
    // del canal marca sus mensajes como enviados pero no conoce a las campañas.
    $enVuelo = $pdo->query("SELECT id FROM datarocket_campanas WHERE estado = 'enviando'")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $cerradas = 0;
    foreach ($enVuelo as $cid) {
        $r = drcaCampanaReconciliar($pdo, (int) $cid);
        if (($r['estado'] ?? '') === 'completada') {
            $cerradas++;
            anotarLog("Campaña #{$cid} completada — enviados {$r['enviados']}, fallidos {$r['fallidos']}.");
            registrarSuceso($pdo, 'datarocket_campanas', 'info',
                "Campaña #{$cid} completada — enviados {$r['enviados']}, fallidos {$r['fallidos']}, omitidos {$r['omitidos']}");
        }
    }

    $resumen = "{$corridas} campañas corridas, " . count($enVuelo) . " reconciliadas, {$cerradas} completadas";
    anotarLog($resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    registrarSuceso(db(), 'datarocket_campanas', 'error',
        'Falla del cron de campañas: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
