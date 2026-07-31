<?php
// api/telegrammensajes_enviar.php
// Envio manual de un mensaje individual de `telegram_mensajes` (MTProto via
// microservicio v4). Usado por la accion "Enviar ahora" del menu contextual
// del ABM. La logica de despacho vive en lib/telegram_mensajes_enviar.php --
// la misma que usa el cron job cloud/jobs/telegram_mensajes_enviar.php.
//
//   POST api/telegrammensajes_enviar.php?id=N
//
// Respuesta: {ok:true, data:{destino, canal_nombre, message_id}} o
//            {ok:false, error:'...'}
//
// Mirror estructural de cloud/api/evolutionmensajes_enviar.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/telegram_mensajes_enviar.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Mismo permiso que "editar" del ABM: quien puede modificar el mensaje
    // puede dispararlo. No hace falta un slug nuevo.
    requirePermission('plataformas.telegram.mensajes.editar');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    $pdo = db();
    $r = telegramMensajeEnviarPorId($pdo, $id, 'abm/telegram_mensajes');

    if (!empty($r['ok'])) {
        jsonOk([
            'destino'      => $r['destino']      ?? null,
            'canal_nombre' => $r['canal_nombre'] ?? null,
            'message_id'   => $r['message_id']   ?? null,
        ]);
    }
    // Skip: el mensaje ya no estaba pendiente (otro proceso lo tomo o
    // fue anulado / ya enviado). Devolvemos 409 conflict con motivo.
    if (!empty($r['skip'])) {
        jsonError('El mensaje ya no esta pendiente (' . ($r['motivo'] ?? '?') . ')', 409);
    }
    // Error real: propagamos el detalle que ya quedo persistido en `error`.
    jsonError($r['error'] ?? 'error desconocido', 422);

} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
