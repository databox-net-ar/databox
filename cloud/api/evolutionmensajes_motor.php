<?php
// api/evolutionmensajes_motor.php
// Control manual del motor de envio Evolution. POST con body JSON:
//   { "accion": "detener" }  -> setea parametros.evolution.mensajes.enviar = '0'
//   { "accion": "iniciar" }  -> setea parametros.evolution.mensajes.enviar = '2'
// Devuelve el nuevo valor del flag en la respuesta.
//
// Semantica del flag documentada en cloud/api/lib/evolution_mensajes.php:
//   '0' = DETENIDO / '1' = ESPERANDO / '2' = ENVIANDO
//
// Permiso: `plataformas.evolution.mensajes.motor` — verbo propio, distinto
// de los CRUD (agregar/editar/eliminar). Encolar un mensaje no debe implicar
// autoridad para parar el motor. Sembrado por la migracion
// 20260725_1800_agregar_permiso_evolution_mensajes_motor.sql.
//
// Mirror estructural de cloud/api/awsmensajes_motor.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/evolution_mensajes.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    requirePermission('plataformas.evolution.mensajes.motor');

    $body   = readJsonBody();
    $accion = (string)($body['accion'] ?? '');
    $pdo    = db();

    if ($accion === 'detener') {
        detenerMotorEvolution($pdo);
    } elseif ($accion === 'iniciar') {
        iniciarMotorEvolution($pdo);
    } else {
        jsonError("Accion invalida: usar 'detener' o 'iniciar'", 400);
    }

    jsonOk(['motor' => getParametro('evolution.mensajes.enviar', '0')]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
