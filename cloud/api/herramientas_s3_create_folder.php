<?php
/**
 * API cloud — Herramientas: crear "carpeta" en el bucket S3.
 *
 * S3 no tiene carpetas reales: se simula subiendo un objeto vacío cuya key
 * termina en "/". Las consolas (incluida la nuestra) la muestran como
 * directorio gracias al delimiter "/" del ListObjectsV2.
 *
 * POST api/herramientas_s3_create_folder.php (application/json)
 *   body: { "prefix": "ruta/actual/", "nombre": "nueva-carpeta" }
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();

require_once __DIR__ . '/lib/s3.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$prefix = isset($input['prefix']) ? (string)$input['prefix'] : '';
$nombre = isset($input['nombre']) ? (string)$input['nombre'] : '';

$prefix = ltrim($prefix, '/');
if ($prefix !== '' && substr($prefix, -1) !== '/') $prefix .= '/';

$nombre = trim($nombre);
$nombre = preg_replace('/[^\w\.\- ]/u', '_', $nombre);
$nombre = trim($nombre, " /");
if ($nombre === '' || $nombre === '.' || $nombre === '..') {
    echo json_encode(['ok' => false, 'error' => 'Nombre de carpeta inválido']);
    exit;
}

$key = $prefix . $nombre . '/';

try {
    $res = s3_put_object($key, '', 'application/x-directory');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

if ($res['status'] < 200 || $res['status'] >= 300) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'S3 respondió HTTP ' . $res['status'],
        'detail' => $res['body'],
    ]);
    exit;
}

echo json_encode([
    'ok'  => true,
    'key' => $key,
]);
