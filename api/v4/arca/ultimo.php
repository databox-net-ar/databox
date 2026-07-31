<?php
// api/v4/arca/ultimo.php
// GET /v4/arca/ultimo?empresa=<slug>&punto=<PtoVta>&tipo=<CbteTipo>
//   -> {ok:true, empresa:{slug,cuit}, punto, tipo, cbte_nro}
//
// Envuelve FECompUltimoAutorizado -- numero del ultimo comprobante que AFIP
// autorizo para el par (PtoVta, CbteTipo). Sirve para saber que numero de
// serie le corresponde al siguiente comprobante antes de autorizarlo.
//
// `tipo` es el codigo AFIP:  1=FA, 6=FB, 11=FC, 51=FM,
//                           3=NA-Credito, 8=NB-Credito, 13=NC-Credito, 53=NM,
//                           2=NA-Debito,  7=NB-Debito, 12=NC-Debito,  52=NM-Debito.
// (Ver /v4/arca/parametros?catalogo=tipos_cbte para la lista completa.)
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';
require_once __DIR__ . '/_lib/afip_factory.php';

arcaInitLog('ultimo', [
    'empresa' => $_GET['empresa'] ?? '',
    'punto'   => $_GET['punto']   ?? '',
    'tipo'    => $_GET['tipo']    ?? '',
]);

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $slug  = trim((string)($_GET['empresa'] ?? ''));
    $punto = (int)($_GET['punto'] ?? 0);
    $tipo  = (int)($_GET['tipo']  ?? 0);

    if ($punto <= 0) jsonError('Falta `punto` (PtoVta > 0).', 400);
    if ($tipo  <= 0) jsonError('Falta `tipo` (CbteTipo AFIP > 0).', 400);

    $bag = arcaFor($slug);
    $nro = $bag['arca']->ElectronicBilling->GetLastVoucher($punto, $tipo);

    jsonOk([
        'empresa'  => [
            'slug' => (string)$bag['empresa']['slug'],
            'cuit' => (string)$bag['empresa']['cuit'],
        ],
        'punto'    => $punto,
        'tipo'     => $tipo,
        'cbte_nro' => $nro,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
