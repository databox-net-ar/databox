<?php
// api/v4/arca/dummy.php
// GET /v4/arca/dummy?empresa=<slug>
//   -> {ok:true, empresa:{slug,cuit}, app_server, db_server, auth_server}
//
// Envuelve FEDummy contra WSFEv1. No requiere firmar TA (es el unico
// metodo publico de WSFE), sirve para ping-ear AFIP y confirmar que el
// servicio esta arriba antes de intentar operaciones firmadas.
//
// Auth: Bearer con apikey de `aplicaciones`.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';
require_once __DIR__ . '/_lib/afip_factory.php';

arcaInitLog('dummy', ['empresa' => $_GET['empresa'] ?? '']);

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $slug = trim((string)($_GET['empresa'] ?? ''));
    $bag  = arcaFor($slug);

    $status = $bag['arca']->ElectronicBilling->GetServerStatus();

    jsonOk([
        'empresa' => [
            'slug' => (string)$bag['empresa']['slug'],
            'cuit' => (string)$bag['empresa']['cuit'],
        ],
        'app_server'  => (string)($status->AppServer  ?? ''),
        'db_server'   => (string)($status->DbServer   ?? ''),
        'auth_server' => (string)($status->AuthServer ?? ''),
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
