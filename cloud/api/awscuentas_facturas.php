<?php
// api/awscuentas_facturas.php
// Consulta el estado de facturacion de una cuenta AWS combinando dos fuentes:
//   - BCM Recommended Actions: acciones tipo PAYMENTS_DUE / PAYMENTS_PAST_DUE
//     -> unica fuente publica que dice "cuanto adeudas ahora" via API key,
//     sin depender del plan de soporte (a diferencia de AWS Health).
//   - AWS Invoicing: lista de facturas emitidas en los ultimos N meses.
//
// El grueso de la logica (BCM + Invoicing + reconciliacion + cache) vive en
// lib/awscuentas_billing.php porque tambien la usa el job cloud/jobs/
// awscuentas_actualizar_facturas.php que corre en segundo plano.
//
// GET api/awscuentas_facturas.php?id=N[&months=6]
//
// Respuesta:
//   { ok: true, data: {
//       account_id, nombre,
//       payments:  { ok, actions: [...], error }
//       invoicing: { ok, count, invoices: [...], error }
//     } }
//
// Nunca falla si una API no responde: cada seccion lleva su propio flag/error.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/awscuentas_billing.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Metodo no soportado', 405);
}

requireAuth();
requirePermission('plataformas.aws.cuentas.consultar');

$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$months = isset($_GET['months']) ? (int)$_GET['months'] : 6;

if ($id <= 0) jsonError('Falta id de cuenta', 400);

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, nombre, numero, accesskey, secreto FROM awscuentas WHERE id = :id');
$stmt->execute([':id' => $id]);
$cuenta = $stmt->fetch();

if (!$cuenta)                     jsonError('Cuenta AWS no encontrada', 404);
if (empty($cuenta['numero']))     jsonError('La cuenta no tiene numero configurado', 400);
if (empty($cuenta['accesskey']))  jsonError('La cuenta no tiene Access Key configurado', 400);
if (empty($cuenta['secreto']))    jsonError('La cuenta no tiene Secret Key configurado', 400);

$respuesta = actualizarBillingCuenta($pdo, $cuenta, $months);

if (!$respuesta['payments']['ok']) {
    registrarSuceso($pdo, 'awscuentas', 'error',
        "BCM fallo (cuenta #{$id}): " . $respuesta['payments']['error']);
}
if (!$respuesta['invoicing']['ok']) {
    registrarSuceso($pdo, 'awscuentas', 'error',
        "Invoicing fallo (cuenta #{$id}): " . $respuesta['invoicing']['error']);
}

registrarSuceso($pdo, 'awscuentas', 'info', 'Consulta billing ' . $respuesta['summary']);

jsonOk($respuesta);
