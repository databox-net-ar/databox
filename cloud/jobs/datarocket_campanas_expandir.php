<?php
/**
 * cloud/jobs/datarocket_campanas_expandir.php
 * Cron worker de las campañas Datarocket. Hace dos cosas por corrida:
 *
 *   1. LEVANTA las campañas cuya hora llegó (`estado='programada'` y
 *      `programada <= NOW()`) y las corre: arma el padrón y encola. En la misma
 *      pasada RETOMA las que quedaron `enviando` con pendientes en el padrón,
 *      que es como termina toda campaña que no entra en una sola corrida.
 *   2. RECONCILIA las que están `enviando`, para que el padrón se entere de
 *      lo que el motor del canal ya despachó y la campaña pueda cerrarse.
 *   3. BARRE las campañas de correo cerradas hace poco, para recoger los
 *      eventos de SES que llegan tarde (rebote, spam, apertura) y disparar las
 *      bajas de lista que correspondan.
 *
 * El trabajo real vive en cloud/api/lib/datarocket_campanas_expandir.php, que
 * comparte con el endpoint del botón "Iniciar" del panel. Este archivo
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

// Cuántos días después de cerrada se sigue mirando una campaña de correo para
// recoger los eventos tardíos de SES.
//
// El envío y la entrega son inmediatos, pero el resto del feedback no: un open
// puede llegar horas después y un complaint (el "esto es spam" del
// destinatario) días después, cuando la campaña hace rato que está
// 'completada'. Sin esta ventana el paso 2 no la mira más — sólo reconcilia
// 'enviando' — y esos eventos no aterrizan nunca: los rebotes tardíos no
// darían de baja a nadie y el contador quedaría mintiendo.
//
// 7 días cubre con margen el complaint típico. Más allá, el evento igual queda
// en `aws_eventos` y en `aws_mensajes.resultado`; lo único que se pierde es la
// propagación al padrón de una campaña vieja.
const VENTANA_EVENTOS_DIAS = 7;

$ORIGEN = 'cron/datarocket_campanas_expandir';

try {
    $pdo = db();

    // --- 1. Campañas cuya hora llegó, y las que quedaron a medio encolar -----
    // Son DOS casos y hacen falta los dos:
    //
    //   a) 'programada' con la hora cumplida — el arranque normal.
    //   b) 'enviando' con pendientes en el padrón — la reanudación. Una campaña
    //      que no entra entera en una corrida termina la fase 2 con pendientes,
    //      y drcaCampanaReconciliar() la deja en 'enviando'. Sin esta rama nadie
    //      volvía a encolar esos pendientes: el paso 2 de acá abajo sólo
    //      reconcilia (no encola) y el botón del panel sólo ofrecía lanzar desde
    //      borrador/programada. La campaña quedaba clavada para siempre con
    //      parte del padrón sin despachar.
    //
    // 'encolando' queda EXCLUIDO a propósito: es el candado que toma una
    // corrida manual desde el panel. Si el operador está ejecutando la campaña
    // a mano, el cron no se mete en el mismo padrón.
    //
    // El EXISTS se apoya en idx_drcam_campana_estado(campana_id, estado), así
    // que cuesta un seek por campaña en vuelo y no un recuento del padrón.
    $lote = MAX_CAMPANAS_POR_CORRIDA;
    $st = $pdo->query("
        SELECT c.id, c.nombre
          FROM datarocket_campanas c
         WHERE (c.estado = 'programada'
                AND c.programada IS NOT NULL
                AND c.programada <= NOW())
            OR (c.estado = 'enviando'
                AND EXISTS (SELECT 1
                              FROM datarocket_campanas_mensajes m
                             WHERE m.campana_id = c.id
                               AND m.estado     = 'pendiente'))
         ORDER BY c.prioridad DESC, c.programada ASC, c.id ASC
         LIMIT {$lote}
    ");
    $pendientes = $st->fetchAll();

    $corridas  = 0;
    $yaCorrida = [];   // ids que el paso 2 no tiene que volver a reconciliar
    foreach ($pendientes as $c) {
        $id = (int) $c['id'];
        $yaCorrida[$id] = true;
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
    // Las que acaba de correr el paso 1 ya salieron reconciliadas de
    // drcaCampanaEjecutar(): volver a mirarlas sería un JOIN al pedo y, sobre
    // todo, las contaría dos veces en el resumen de la corrida.
    $enVuelo = $pdo->query("SELECT id FROM datarocket_campanas WHERE estado = 'enviando'")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $enVuelo = array_values(array_filter($enVuelo, function ($cid) use ($yaCorrida) {
        return !isset($yaCorrida[(int) $cid]);
    }));
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

    // --- 3. Eventos tardíos de SES sobre campañas ya cerradas ---------------
    // El paso 2 sólo mira 'enviando', y una campaña de correo se cierra apenas
    // SES acepta todos sus mensajes. Pero el feedback que importa llega
    // después: el rebote puede tardar minutos y el complaint, días. Sin este
    // barrido esos eventos nunca llegan al padrón — o sea, los rebotes tardíos
    // no darían de baja a nadie, que es justamente lo que la baja automática
    // tiene que atrapar.
    //
    // Sólo correo: `resultado` lo alimenta el webhook SNS de SES, que no existe
    // para WhatsApp ni Telegram.
    $ventana = VENTANA_EVENTOS_DIAS;
    $tardias = $pdo->query("
        SELECT id
          FROM datarocket_campanas
         WHERE medio      = 'correo'
           AND estado     = 'completada'
           AND completada IS NOT NULL
           AND completada >= NOW() - INTERVAL {$ventana} DAY
    ")->fetchAll(PDO::FETCH_COLUMN);

    $bajasTotal = 0;
    foreach ($tardias as $cid) {
        // Reconciliar una 'completada' no le mueve el estado: el bloque que
        // avanza estados sólo actúa sobre 'encolando'/'enviando'. Acá se la
        // llama por su efecto sobre el padrón (resultado + bajas).
        $r = drcaCampanaReconciliar($pdo, (int) $cid);
        if (($r['bajas'] ?? 0) > 0) $bajasTotal += (int) $r['bajas'];
    }

    $resumen = "{$corridas} campañas corridas, " . count($enVuelo) . " reconciliadas, {$cerradas} completadas"
             . ', ' . count($tardias) . " revisadas por eventos tardíos"
             . ($bajasTotal > 0 ? ", {$bajasTotal} bajas de lista" : '');
    anotarLog($resumen);
    marcarEjecucionOk($resumen);
} catch (Throwable $e) {
    registrarSuceso(db(), 'datarocket_campanas', 'error',
        'Falla del cron de campañas: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}
