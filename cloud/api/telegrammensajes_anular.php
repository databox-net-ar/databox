<?php
// api/telegrammensajes_anular.php
// Anula un mensaje Telegram encolado (setea estado='anulado' y borra el
// error residual si lo tuviera). El worker solo procesa 'pendiente', asi
// que un mensaje anulado no vuelve a intentarse. Deja traza a diferencia
// de eliminar, que borra el registro por completo.
//
// POST api/telegrammensajes_anular.php?id=N   (sin body)
// Respuesta: { ok: true, data: { id: N } }
//
// Regla: solo se puede anular sobre estado 'pendiente'. Mensajes en
// 'enviando' estan en el lock optimista del worker (no queremos crear
// carrera con el UPDATE del sender). Mensajes en 'enviado', 'anulado' o
// 'error' son terminales -- para eliminarlos usar el DELETE del ABM.
//
// Permiso: `plataformas.telegram.mensajes.eliminar` -- anular es una
// mutacion destructiva del ciclo de vida del mensaje (mismo nivel de
// autoridad que borrarlo).
//
// Mirror estructural de cloud/api/evolutionmensajes_anular.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    requirePermission('plataformas.telegram.mensajes.eliminar');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    $pdo = db();
    $exists = $pdo->prepare('SELECT estado FROM telegram_mensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    $estadoActual = $exists->fetchColumn();
    if ($estadoActual === false) jsonError('Mensaje no encontrado', 404);

    if ($estadoActual !== 'pendiente') {
        jsonError(
            "Solo se pueden anular mensajes en estado 'pendiente' (el mensaje esta en '{$estadoActual}').",
            409
        );
    }

    $st = $pdo->prepare("UPDATE telegram_mensajes SET estado = 'anulado', error = NULL WHERE id = :id");
    $st->execute([':id' => $id]);

    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
