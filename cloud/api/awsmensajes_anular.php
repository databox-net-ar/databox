<?php
// api/awsmensajes_anular.php
// Anula un mensaje AWS encolado (setea estado='anulado' y borra el error
// residual si lo tuviera). El sender solo procesa 'pendiente', asi que un
// mensaje anulado no vuelve a intentarse.
//
// POST api/awsmensajes_anular.php?id=N   (sin body)
// Respuesta: { ok: true, data: { id: N } }
//
// Permiso: `plataformas.aws.mensajes.editar` — anular es una accion de
// mutacion sobre un mensaje existente, no una creacion.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    requirePermission('plataformas.aws.mensajes.editar');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    $pdo = db();
    $exists = $pdo->prepare('SELECT estado FROM aws_mensajes WHERE id = :id');
    $exists->execute([':id' => $id]);
    $estadoActual = $exists->fetchColumn();
    if ($estadoActual === false) jsonError('Mensaje no encontrado', 404);

    // Solo se puede anular mientras esta pendiente. Los estados enviando/
    // enviado/anulado/error son inmutables desde esta accion:
    //  - enviando: el sender lo tiene lockeado, race si lo tocamos.
    //  - enviado:  ya salio, no hay nada que cancelar.
    //  - anulado:  ya esta anulado, no-op ruidoso.
    //  - error:    resultado terminal, no vale la pena reescribir.
    if ($estadoActual !== 'pendiente') {
        jsonError("Solo se puede anular un mensaje en estado 'pendiente' (actual: '{$estadoActual}')", 409);
    }

    $st = $pdo->prepare("UPDATE aws_mensajes SET estado = 'anulado', error = NULL WHERE id = :id");
    $st->execute([':id' => $id]);

    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
