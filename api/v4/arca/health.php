<?php
// api/v4/arca/health.php
// GET /v4/arca/health
//   -> {ok:true, version, php, ext_openssl, ext_soap, empresas_facturables}
//
// Healthcheck LOCAL del microservicio -- NO toca AFIP. Sirve para verificar
// desde monitoring que el endpoint esta arriba, las extensiones PHP
// necesarias estan cargadas y hay al menos una empresa facturable.
//
// Para chequear el estado de AFIP en si, ver /v4/arca/dummy?empresa=<slug>
// (que si dispara FEDummy contra WSFEv1).
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';

arcaInitLog('health');

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $pdo   = db();
    $st    = $pdo->query('SELECT COUNT(*) FROM datacount_empresas WHERE certificado_id IS NOT NULL');
    $count = (int)$st->fetchColumn();

    jsonOk([
        'php'                  => PHP_VERSION,
        'ext_openssl'          => extension_loaded('openssl'),
        'ext_soap'             => extension_loaded('soap'),
        'empresas_facturables' => $count,
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
