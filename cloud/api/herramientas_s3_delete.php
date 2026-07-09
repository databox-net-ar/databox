<?php
/**
 * API cloud — Herramientas: eliminar objeto/carpeta del bucket S3.
 *
 * POST api/herramientas_s3_delete.php (application/json)
 *   body: { "key": "ruta/al/archivo.jpg" }
 *   body: { "key": "ruta/al/folder/", "recursivo": true }
 *
 * - Si la key NO termina en "/", borra ese único objeto.
 * - Si termina en "/" (carpeta):
 *     · recursivo=true → lista paginado todos los objetos bajo ese prefijo
 *       y los borra uno por uno; al final intenta borrar también el marker
 *       de carpeta.
 *     · recursivo=false (default) → solo borra el marker "carpeta/" si existe.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
$auth      = requireAuth();
$usuarioId = (int)($auth['sub'] ?? 0);

require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sucesos.php';
$pdoLog = db();

$input     = json_decode(file_get_contents('php://input'), true);
$key       = trim($input['key'] ?? '');
$recursivo = !empty($input['recursivo']);

if ($key === '') {
    registrarSuceso($pdoLog, 'Explorador S3', 'error',
        "Eliminar (usuario #$usuarioId): falta parámetro \"key\"");
    echo json_encode(['ok' => false, 'error' => 'Falta el parámetro "key"']);
    exit;
}

// Hard guard: nunca borrar el bucket entero o la raíz.
if ($key === '/' || $key === '*') {
    registrarSuceso($pdoLog, 'Explorador S3', 'error',
        "Eliminar key=\"$key\" (usuario #$usuarioId): operación no permitida (guardia raíz)");
    echo json_encode(['ok' => false, 'error' => 'Operación no permitida']);
    exit;
}

$esCarpeta = substr($key, -1) === '/';
$eliminados = 0;
$errores    = [];

try {
    if ($esCarpeta && $recursivo) {
        $objetos = s3_list_all_objects($key);
        foreach ($objetos as $o) {
            $r = s3_delete_object($o['key']);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $eliminados++;
            } else {
                $errores[] = ['key' => $o['key'], 'status' => $r['status']];
            }
        }
        // Marker de la carpeta (puede no existir como objeto).
        $r = s3_delete_object($key);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $eliminados++;
        }
    } else {
        $res = s3_delete_object($key);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            registrarSuceso($pdoLog, 'Explorador S3', 'error',
                "Eliminar key=\"$key\" (usuario #$usuarioId) — S3 respondió HTTP " . $res['status']
                . (isset($res['body']) ? ': ' . substr((string)$res['body'], 0, 500) : ''));
            echo json_encode([
                'ok'     => false,
                'status' => $res['status'],
                'error'  => 'S3 respondió HTTP ' . $res['status'],
                'detail' => $res['body'],
            ]);
            exit;
        }
        $eliminados = 1;
    }
} catch (Throwable $e) {
    registrarSuceso($pdoLog, 'Explorador S3', 'error',
        "Eliminar key=\"$key\" (usuario #$usuarioId): " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Fallas parciales en modo recursivo — no se cortó el flujo, pero conviene
// dejar registro en `sucesos` para investigar despues.
if (!empty($errores)) {
    $muestras = array_slice(array_map(fn($e) => $e['key'] . ' (HTTP ' . $e['status'] . ')', $errores), 0, 5);
    registrarSuceso($pdoLog, 'Explorador S3', 'error',
        "Eliminar recursivo key=\"$key\" (usuario #$usuarioId): "
        . count($errores) . " objeto(s) fallaron. Ej: " . implode(', ', $muestras));
}

echo json_encode([
    'ok'         => empty($errores),
    'key'        => $key,
    'eliminados' => $eliminados,
    'errores'    => $errores,
]);
