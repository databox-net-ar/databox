<?php
/**
 * API cloud — Datarocket > Campañas: ejecutar ahora.
 *
 * Larga la campaña a mano, sin esperar al cron: arma el padrón y encola los
 * mensajes en la cola del canal, streameando el progreso en vivo.
 *
 *   GET api/datarocket_campanas_ejecutar.php?id=N
 *
 * El trabajo real vive en cloud/api/lib/datarocket_campanas_expandir.php, que
 * comparte con el job cloud/jobs/datarocket_campanas_expandir.php. Acá sólo
 * está el transporte: auth, SSE y traducción de errores a líneas de consola.
 *
 * Formato de evento (mismo que el Sincronizador de tablas):
 *   data: {"type":"info|warn|error|success|done","msg":"..."}\n\n
 *
 * Requiere `datarocket.campanas.editar`: lanzar una campaña no es consultarla.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();
requirePermission('datarocket.campanas.editar');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/datarocket_campanas_expandir.php';

// --- Setup SSE ---
// Desactivar cualquier buffer intermedio para que cada echo llegue al navegador
// en el momento (Apache + PHP-FPM tienden a bufferear salidas cortas).
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_implicit_flush(true);
set_time_limit(0);
// Una campaña a medio encolar es reanudable, pero cortar la corrida porque el
// operador cerró la pestaña deja el padrón a medias sin necesidad: se sigue
// hasta terminar el lote y recién ahí se corta.
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function drcaSse(string $type, string $msg, array $extra = []): void {
    echo 'data: ' . json_encode(array_merge(['type' => $type, 'msg' => $msg], $extra),
                                JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    drcaSse('error', 'Falta el id de la campaña.');
    drcaSse('done',  'Abortado.', ['ok' => false]);
    exit;
}

drcaSse('info', '========================================');
drcaSse('info', 'Ejecutar campaña');
drcaSse('info', '========================================');

try {
    $pdo = db();
    $r = drcaCampanaEjecutar($pdo, $id, function (string $linea) {
        drcaSse('info', $linea);
    });

    if ($r['fallidos'] > 0) {
        drcaSse('warn', "{$r['fallidos']} destinatarios quedaron fallidos — mirá el motivo en la pestaña Destinatarios.");
    }
    if ($r['omitidos'] > 0) {
        drcaSse('warn', "{$r['omitidos']} destinatarios quedaron omitidos (sin dato de contacto o repetidos).");
    }

    // El mensaje de cierre tiene que ser honesto sobre qué pasó: encolar no es
    // enviar. El despacho lo hace el motor del canal en sus próximas corridas.
    if ($r['encolados_esta_corrida'] > 0) {
        drcaSse('success', "{$r['encolados_esta_corrida']} mensajes encolados. El motor del canal los va despachando.");
    } elseif ($r['pendientes'] === 0 && $r['total'] > 0) {
        drcaSse('success', 'No quedaba nada por encolar: la campaña ya estaba al día.');
    }

    registrarSuceso($pdo, 'datarocket_campanas', 'info',
        "Ejecución manual de campaña #{$id} — encolados {$r['encolados_esta_corrida']}"
        . ", padrón {$r['total']}, estado {$r['estado']}");

    drcaSse('done', 'Listo.', ['ok' => true, 'resumen' => $r]);
} catch (InvalidArgumentException $e) {
    // Errores de validación: la campaña no está lista para largarse. Es una
    // condición esperable, no una falla del sistema.
    drcaSse('error', 'No se puede ejecutar: ' . $e->getMessage());
    drcaSse('done',  'Abortado.', ['ok' => false]);
} catch (Throwable $e) {
    drcaSse('error', 'Error: ' . $e->getMessage());
    drcaSse('done',  'Abortado.', ['ok' => false]);
}
