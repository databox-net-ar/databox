<?php
// api/v4/arca/consultar.php
// GET /v4/arca/consultar?empresa=<slug>&punto=<PtoVta>&tipo=<CbteTipo>&nro=<CbteNro>
//   -> {ok:true, comprobante:{...}} si existe
//   -> {ok:false, error: 'Comprobante no encontrado'} con code 404 si no
//
// Envuelve FECompConsultar. Sirve para verificar despues de una emision, o
// para reconciliar cuando el estado local del comprobante no coincide con
// lo que AFIP tiene registrado (por ejemplo tras una caida a mitad de
// autorizacion).
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';
require_once __DIR__ . '/_lib/afip_factory.php';

arcaInitLog('consultar', [
    'empresa' => $_GET['empresa'] ?? '',
    'punto'   => $_GET['punto']   ?? '',
    'tipo'    => $_GET['tipo']    ?? '',
    'nro'     => $_GET['nro']     ?? '',
]);

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $slug  = trim((string)($_GET['empresa'] ?? ''));
    $punto = (int)($_GET['punto'] ?? 0);
    $tipo  = (int)($_GET['tipo']  ?? 0);
    $nro   = (int)($_GET['nro']   ?? 0);

    if ($punto <= 0) jsonError('Falta `punto` (PtoVta > 0).', 400);
    if ($tipo  <= 0) jsonError('Falta `tipo` (CbteTipo AFIP > 0).', 400);
    if ($nro   <= 0) jsonError('Falta `nro` (CbteNro > 0).', 400);

    $bag  = arcaFor($slug);
    $info = $bag['arca']->ElectronicBilling->GetVoucherInfo($nro, $punto, $tipo);

    if ($info === null) jsonError('Comprobante no encontrado en AFIP.', 404);

    // AFIP devuelve stdClass; lo serializamos completo para que el cliente
    // vea todo lo que AFIP tiene registrado del comprobante.
    jsonOk([
        'empresa'     => [
            'slug' => (string)$bag['empresa']['slug'],
            'cuit' => (string)$bag['empresa']['cuit'],
        ],
        'comprobante' => $info,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
