<?php
// api/datarocketplantillas_adjunto.php
// Endpoint dedicado para subir el adjunto de una plantilla Datarocket al
// bucket S3, bajo la carpeta `datarocket/plantillas/`. Devuelve la URL
// publica que se guarda tal cual en `datarocket_plantillas.adjunto`.
//
//   POST api/datarocketplantillas_adjunto.php (multipart/form-data)
//     campo `archivo`      (obligatorio): archivo a subir.
//     campo `plantilla_id` (obligatorio): id de la plantilla ya creada.
//
// Respuesta: {ok: true, key, url, size, content_type} o {ok:false, error}.
//
// Permisos: `sistemas.datarocket.plantillas.editar` — la subida solo puede
// ocurrir contra una plantilla YA existente (modo edicion). En el modo "nueva
// plantilla" el frontend oculta el boton, y el endpoint tambien rechaza el
// intento si el id no matchea una fila existente.
//
// Convencion de key: `datarocket/plantillas/<id>.<ext>` — nombre estandarizado
// usando el ID de la plantilla, para hacer trivial la limpieza y evitar
// huerfanos cuando el usuario reemplaza un archivo por otro con el mismo tipo.
// Si el ext cambia (ej. jpg -> png), el archivo previo (con ext viejo) queda
// huerfano hasta que el PUT del ABM lo limpie (ver drPlBorrarAdjuntoS3).

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
requirePermission('sistemas.datarocket.plantillas.editar');

require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$plantillaId = isset($_POST['plantilla_id']) ? (int)$_POST['plantilla_id'] : 0;
if ($plantillaId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Falta plantilla_id']);
    exit;
}

// Verificar que la plantilla existe antes de tocar S3: no se aceptan uploads
// contra ids inventados ni desde el flujo de alta (la plantilla no existe
// hasta que el POST del ABM devuelve el id).
$stmt = db()->prepare('SELECT id FROM datarocket_plantillas WHERE id = :id');
$stmt->execute([':id' => $plantillaId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Plantilla no encontrada']);
    exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    $errores = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño maximo del servidor',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño maximo del formulario',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subio parcialmente',
        UPLOAD_ERR_NO_FILE    => 'No se selecciono ningun archivo',
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

// Nombre estandarizado: se ignora el nombre original del archivo y se usa el
// ID de la plantilla rellenado con ceros a la izquierda a 8 chars + extension.
// Sube siempre a la misma key para el mismo tipo de archivo, lo que hace que
// un reemplazo por otro archivo con el mismo ext funcione como PUT idempotente
// y no deje huerfanos.
$nombreOriginal = basename($file['name'] ?? 'archivo');
$ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
$ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
$nombreId = str_pad((string)$plantillaId, 8, '0', STR_PAD_LEFT);
$key = 'datarocket/plantillas/' . $nombreId . ($ext !== '' ? '.' . $ext : '');

try {
    $res = s3_put_object($key, $contenido, $mimeReal);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

if ($res['status'] < 200 || $res['status'] >= 300) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'S3 respondio HTTP ' . $res['status'],
        'detail' => $res['body'] ?? '',
    ]);
    exit;
}

echo json_encode([
    'ok'           => true,
    'key'          => $key,
    'url'          => s3_public_url($key),
    'size'         => $file['size'],
    'content_type' => $mimeReal,
]);
