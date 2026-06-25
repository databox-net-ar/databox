<?php
/**
 * API cloud — Herramientas: listar contenido del bucket S3 (explorador).
 *
 * GET api/herramientas_s3_list.php?prefix=carpeta/&token=...
 *
 * Devuelve el contenido del bucket configurado en este entorno (AWS_BUCKET)
 * agrupado por carpeta usando delimiter "/". Si hay más resultados de los
 * que devuelve una página, incluye un continuation token para paginar.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();

require_once __DIR__ . '/lib/s3.php';

$prefix = isset($_GET['prefix']) ? (string)$_GET['prefix'] : '';
$token  = isset($_GET['token'])  ? (string)$_GET['token']  : '';

$prefix = ltrim($prefix, '/');

try {
    $res = s3_list_objects($prefix, $token !== '' ? $token : null, '/');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

if (!$res['ok']) {
    echo json_encode([
        'ok'     => false,
        'error'  => $res['error'] ?? 'Error al listar S3',
        'status' => $res['status'] ?? 0,
        'detail' => $res['detail'] ?? null,
    ]);
    exit;
}

$objetos = [];
foreach ($res['objects'] as $o) {
    // Filtra el propio "directorio" (key igual al prefix actual) que algunos
    // clientes crean como objeto vacío.
    if ($o['key'] === $prefix) continue;
    $objetos[] = [
        'key'           => $o['key'],
        'size'          => $o['size'],
        'last_modified' => $o['last_modified'],
        'url'           => s3_public_url($o['key']),
    ];
}

echo json_encode([
    'ok'         => true,
    'bucket'     => s3_bucket_name(),
    'region'     => s3_region(),
    'prefix'     => $prefix,
    'folders'    => $res['folders'],
    'objects'    => $objetos,
    'truncated'  => $res['truncated'],
    'next_token' => $res['next_token'],
]);
