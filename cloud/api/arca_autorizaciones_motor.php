<?php
// api/arca_autorizaciones_motor.php
// Control manual del motor de autorizacion automatica de comprobantes AFIP.
// POST con body JSON:
//   { "accion": "detener" }  -> setea parametros.datacount.comprobantes.autorizar = '0'
//   { "accion": "iniciar" }  -> setea parametros.datacount.comprobantes.autorizar = '1'
// Devuelve el nuevo valor del flag en la respuesta.
//
// Semantica del flag (booleana; NO es el tri-estado del motor de mensajes):
//   '1' = HABILITADO. El cron cloud/jobs/datacount_comprobantes_autorizar.php
//         procesa los pendientes normalmente.
//   cualquier otro valor = DETENIDO. El cron se saltea la corrida hasta que
//         alguien reactive desde aca (o desde el ABM de Parametros).
//
// Permiso: `plataformas.arca.autorizaciones.motor` -- verbo propio, distinto
// del `.consultar` del visor. Ver quien mira el log no implica autoridad
// para parar la autorizacion.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/parametros.php';

header('Content-Type: application/json; charset=utf-8');

const ARCA_AUT_FLAG = 'datacount.comprobantes.autorizar';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    requirePermission('plataformas.arca.autorizaciones.motor');

    $body   = readJsonBody();
    $accion = (string)($body['accion'] ?? '');
    $pdo    = db();

    if ($accion === 'detener') {
        parametroEscribir($pdo, ARCA_AUT_FLAG, '0');
    } elseif ($accion === 'iniciar') {
        parametroEscribir($pdo, ARCA_AUT_FLAG, '1');
    } else {
        jsonError("Accion invalida: usar 'detener' o 'iniciar'", 400);
    }

    jsonOk(['motor' => parametroLeer($pdo, ARCA_AUT_FLAG, '1')]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
