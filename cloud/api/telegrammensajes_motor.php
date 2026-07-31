<?php
// api/telegrammensajes_motor.php
// Control manual del motor de envio Telegram. POST con body JSON:
//   { "accion": "detener" }  -> setea parametros.telegram.mensajes.enviar = '0'
//   { "accion": "iniciar" }  -> setea parametros.telegram.mensajes.enviar = '2'
// Devuelve el nuevo valor del flag en la respuesta.
//
// Semantica del flag documentada en cloud/api/lib/telegram_mensajes.php:
//   '0' = DETENIDO / '1' = ESPERANDO / '2' = ENVIANDO
//
// Permiso: `plataformas.telegram.mensajes.motor` -- verbo propio, distinto
// de los CRUD (agregar/editar/eliminar). Encolar un mensaje no debe implicar
// autoridad para parar el motor. Sembrado por la migracion
// 20260731_0000_telegram_mensajes_cola.sql.
//
// Mirror estructural de cloud/api/evolutionmensajes_motor.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/telegram_mensajes.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    requirePermission('plataformas.telegram.mensajes.motor');

    $body   = readJsonBody();
    $accion = (string)($body['accion'] ?? '');
    $pdo    = db();

    if ($accion === 'detener') {
        detenerMotorTelegram($pdo);
    } elseif ($accion === 'iniciar') {
        iniciarMotorTelegram($pdo);
    } else {
        jsonError("Accion invalida: usar 'detener' o 'iniciar'", 400);
    }

    jsonOk(['motor' => getParametro('telegram.mensajes.enviar', '0')]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
