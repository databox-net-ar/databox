<?php
/**
 * API cloud — Herramientas: subir archivo al bucket S3 (explorador).
 *
 * POST api/herramientas_s3_upload.php (multipart/form-data)
 *   campo `archivo` (obligatorio): el archivo a subir.
 *   campo `prefix`  (opcional)   : carpeta destino (ej: "subcarpeta/").
 *   campo `nombre`  (opcional)   : nombre destino; default = nombre original.
 *
 * Si no se pasa prefix, sube a `pruebas/<timestamp>_<rand>_<base>.<ext>`
 * (modo legacy de prueba). Con prefix, sube a `<prefix><nombre>` respetando
 * el nombre original (saneado).
 *
 * Respuesta: { ok, bucket, key, url, size, content_type }
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'PHP: ' . $err['message']]);
    }
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

require_once __DIR__ . '/lib/auth_check.php';
requireAuth();

require_once __DIR__ . '/lib/s3.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    $errores = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo del servidor',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
        UPLOAD_ERR_CANT_WRITE => 'Error de escritura en disco',
    ];
    $code = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok' => false, 'error' => $errores[$code] ?? 'Error al subir archivo']);
    exit;
}

$file = $_FILES['archivo'];

$maxSize = 20 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'error' => 'El archivo excede los 20MB']);
    exit;
}

$contenido = file_get_contents($file['tmp_name']);
if ($contenido === false) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo leer el archivo subido']);
    exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

$prefix      = isset($_POST['prefix']) ? (string)$_POST['prefix'] : '';
$nombreInput = isset($_POST['nombre']) ? (string)$_POST['nombre'] : '';

if ($prefix !== '') {
    $prefix = ltrim($prefix, '/');
    if (substr($prefix, -1) !== '/') $prefix .= '/';

    $rawName = $nombreInput !== '' ? $nombreInput : ($file['name'] ?? 'archivo');
    $rawName = basename($rawName);
    $nombre  = preg_replace('/[^\w\.\- ]/u', '_', $rawName);
    if ($nombre === '' || $nombre === false || $nombre === '.' || $nombre === '..') {
        $nombre = 'archivo';
    }
    $key = $prefix . $nombre;
} else {
    $nombreOriginal = $file['name'] ?? 'archivo';
    $ext  = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
    $base = pathinfo($nombreOriginal, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $base);
    $base = trim($base, '_') ?: 'archivo';
    $key  = 'pruebas/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $base . ($ext ? '.' . strtolower($ext) : '');
}

try {
    $res = s3_put_object($key, $contenido, $mimeReal);
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
    'ok'           => true,
    'bucket'       => s3_bucket_name(),
    'key'          => $key,
    'url'          => s3_public_url($key),
    'size'         => $file['size'],
    'content_type' => $mimeReal,
]);
