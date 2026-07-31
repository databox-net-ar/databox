<?php
// api/v4/arca/parametros.php
// GET /v4/arca/parametros?empresa=<slug>&catalogo=<nombre>
//   -> {ok:true, catalogo, items:[...]}
//
// Envuelve los FEParamGet* de WSFEv1. Los catalogos disponibles son:
//
//   catalogo         -> operacion AFIP
//   -----------------------------------------------
//   tipos_cbte       FEParamGetTiposCbte
//   tipos_concepto   FEParamGetTiposConcepto
//   tipos_doc        FEParamGetTiposDoc
//   tipos_iva        FEParamGetTiposIva
//   monedas          FEParamGetTiposMonedas
//   opcionales       FEParamGetTiposOpcional
//   tributos         FEParamGetTiposTributos
//   ptos_venta       FEParamGetPtosVenta
//
// Los catalogos cambian poco pero AFIP no publica versionado, asi que este
// endpoint es la fuente de verdad -- consumir en frio y cachear en el
// cliente por 24h esta bien.
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';
require_once __DIR__ . '/_lib/afip_factory.php';

arcaInitLog('parametros', [
    'empresa'  => $_GET['empresa']  ?? '',
    'catalogo' => $_GET['catalogo'] ?? '',
]);

const ARCA_CATALOGOS = [
    'tipos_cbte'     => 'GetVoucherTypes',
    'tipos_concepto' => 'GetConceptTypes',
    'tipos_doc'      => 'GetDocumentTypes',
    'tipos_iva'      => 'GetAliquotTypes',
    'monedas'        => 'GetCurrenciesTypes',
    'opcionales'     => 'GetOptionsTypes',
    'tributos'       => 'GetTaxTypes',
    'ptos_venta'     => 'GetSalesPoints',
];

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $slug     = trim((string)($_GET['empresa']  ?? ''));
    $catalogo = trim((string)($_GET['catalogo'] ?? ''));

    if (!isset(ARCA_CATALOGOS[$catalogo])) {
        jsonError('Catalogo invalido. Validos: ' . implode(', ', array_keys(ARCA_CATALOGOS)), 400);
    }

    $bag    = arcaFor($slug);
    $metodo = ARCA_CATALOGOS[$catalogo];
    $items  = $bag['arca']->ElectronicBilling->{$metodo}();

    // AFIP devuelve una unica entrada como stdClass, no como array de 1.
    // Normalizamos siempre a array para simplificar al cliente.
    if ($items === null)     $items = [];
    elseif (!is_array($items)) $items = [$items];

    jsonOk([
        'empresa'  => ['slug' => (string)$bag['empresa']['slug']],
        'catalogo' => $catalogo,
        'items'    => $items,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
