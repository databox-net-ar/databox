<?php
// api/v4/arca/empresas.php
// GET /v4/arca/empresas
//   -> {ok:true, items:[{slug, nombre, razon, cuit, condicion, certificado_nombre}]}
//
// Devuelve las empresas que estan en condiciones de facturar por este
// microservicio -- solo las que tienen `certificado_id` seteado. Sirve al
// cliente para poblar un <select> sin hardcodear slugs.
//
// Auth: Bearer con apikey de `aplicaciones` (patron /v4/telegram, /v4/evolution).

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/db.php';
require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/_lib/log.php';

arcaInitLog('empresas');

try {
    arcaRequireApp();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') jsonError('Metodo no soportado', 405);

    $pdo = db();
    $st  = $pdo->query(
        'SELECT e.slug, e.nombre, e.razon, e.cuit, e.condicion,
                c.nombre AS certificado_nombre
           FROM datacount_empresas e
           JOIN arca_certificados c ON c.id = e.certificado_id
          WHERE e.certificado_id IS NOT NULL
          ORDER BY e.nombre ASC'
    );
    $items = array_map(function ($r) {
        return [
            'slug'               => (string)$r['slug'],
            'nombre'             => (string)$r['nombre'],
            'razon'              => (string)$r['razon'],
            'cuit'               => $r['cuit'] !== null ? (string)$r['cuit'] : null,
            'condicion'          => (string)$r['condicion'],
            'certificado_nombre' => (string)($r['certificado_nombre'] ?? ''),
        ];
    }, $st->fetchAll());

    jsonOk(['items' => $items]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
