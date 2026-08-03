<?php
// api/datacount_talonarios_fondo.php
// Endpoint dedicado para subir/borrar la imagen de fondo de un talonario
// Datacount. Esa imagen se imprime como marca de agua al generar el PDF
// del comprobante. El binario se sube al bucket S3 bajo `talonarios/` con
// nombre estandarizado <id-padded-8>.<ext> (se ignora el nombre original).
// En `datacount_talonarios.fondo` se guarda solo el nombre del archivo
// (ej. "00000042.jpg"), no la URL — la URL publica se reconstruye desde
// el frontend / generador de PDF usando el prefijo `talonarios/`.
//
//   POST   api/datacount_talonarios_fondo.php?id=N (multipart/form-data)
//       campo `archivo` (obligatorio): imagen a subir (JPG/PNG/WEBP).
//   DELETE api/datacount_talonarios_fondo.php?id=N
//
// Respuesta: {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Permisos: `datacount.talonarios.editar` para ambos verbos — modificar el
// fondo es una edicion sobre el talonario, no un ABM aparte.
//
// Convencion de key S3: `datacount/talonarios/<id-padded-8>.<ext>`. Si el
// ext cambia respecto del previo (ej. jpg -> png), el archivo viejo se borra
// en el mismo request para no dejar huerfanos.

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';
require_once __DIR__ . '/lib/sucesos.php';

const DCTF_S3_PREFIX   = 'datacount/talonarios/';
const DCTF_MAX_SIZE_MB = 10;
const DCTF_EXT_OK      = ['jpg', 'jpeg', 'png', 'webp'];
const DCTF_MIME_OK     = ['image/jpeg', 'image/png', 'image/webp'];

try {
    requirePermission('datacount.talonarios.editar');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    if ($method === 'POST') {
        handleUploadDctFondo($pdo, $id);
    } elseif ($method === 'DELETE') {
        handleDeleteDctFondo($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------

function handleUploadDctFondo(PDO $pdo, int $talonarioId): void {
    $stmt = $pdo->prepare('SELECT id, nombre, fondo FROM datacount_talonarios WHERE id = :id');
    $stmt->execute([':id' => $talonarioId]);
    $prev = $stmt->fetch();
    if (!$prev) jsonError('Talonario no encontrado', 404);

    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $errores = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamano maximo del servidor',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamano maximo del formulario',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subio parcialmente',
            UPLOAD_ERR_NO_FILE    => 'No se selecciono ningun archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error de escritura en disco',
        ];
        $code = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        jsonError($errores[$code] ?? 'Error al subir archivo', 400);
    }

    $file = $_FILES['archivo'];
    if ($file['size'] > DCTF_MAX_SIZE_MB * 1024 * 1024) {
        jsonError('El archivo excede los ' . DCTF_MAX_SIZE_MB . 'MB', 400);
    }

    $nombreOriginal = basename($file['name'] ?? 'archivo');
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
    if (!in_array($ext, DCTF_EXT_OK, true)) {
        jsonError('Formato no permitido. Debe ser JPG, PNG o WEBP.', 400);
    }

    $contenido = file_get_contents($file['tmp_name']);
    if ($contenido === false) jsonError('No se pudo leer el archivo subido', 500);

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mimeReal, DCTF_MIME_OK, true)) {
        jsonError('El archivo no es una imagen valida (JPG/PNG/WEBP).', 400);
    }

    // Nombre estandarizado: <id-padded-8>.<ext>. Sube siempre a la misma key
    // para el mismo ext, PUT idempotente. Se ignora el nombre original.
    $nombreId    = str_pad((string)$talonarioId, 8, '0', STR_PAD_LEFT);
    $archivo     = $nombreId . '.' . $ext;
    $key         = DCTF_S3_PREFIX . $archivo;

    $res = s3_put_object($key, $contenido, $mimeReal);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        jsonError('S3 respondio HTTP ' . $res['status'], 500);
    }

    // Si el ext cambio respecto del previo, borrar el objeto viejo para no
    // dejar huerfanos. Solo tocamos keys bajo `talonarios/<id-padded>.*`.
    $archivoPrev = trim((string)($prev['fondo'] ?? ''));
    if ($archivoPrev !== '' && $archivoPrev !== $archivo
        && str_starts_with($archivoPrev, $nombreId . '.')) {
        try {
            s3_delete_object(DCTF_S3_PREFIX . $archivoPrev);
        } catch (Throwable $e) {
            error_log('[datacount_talonarios_fondo] S3 delete previo fallo: ' . $e->getMessage());
        }
    }

    $upd = $pdo->prepare('UPDATE datacount_talonarios SET fondo = :fondo WHERE id = :id');
    $upd->execute([':fondo' => $archivo, ':id' => $talonarioId]);

    registrarSuceso($pdo, 'datacount_talonarios', 'info',
        "Fondo actualizado talonario #{$talonarioId} — \"{$prev['nombre']}\" -> {$archivo}");

    jsonOk([
        'id'    => $talonarioId,
        'fondo' => $archivo,
        'url'   => s3_public_url($key),
        'size'  => (int)$file['size'],
    ]);
}

function handleDeleteDctFondo(PDO $pdo, int $talonarioId): void {
    $stmt = $pdo->prepare('SELECT id, nombre, fondo FROM datacount_talonarios WHERE id = :id');
    $stmt->execute([':id' => $talonarioId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Talonario no encontrado', 404);

    $archivoPrev = trim((string)($row['fondo'] ?? ''));
    $nombreId    = str_pad((string)$talonarioId, 8, '0', STR_PAD_LEFT);

    // Solo intentamos borrar en S3 si el nombre almacenado responde a la
    // convencion estandarizada (<id-padded>.*). Si por historia hay algo
    // distinto ahi (nombre libre legacy), no tocamos el bucket.
    if ($archivoPrev !== '' && str_starts_with($archivoPrev, $nombreId . '.')) {
        try {
            s3_delete_object(DCTF_S3_PREFIX . $archivoPrev);
        } catch (Throwable $e) {
            error_log('[datacount_talonarios_fondo] S3 delete fallo: ' . $e->getMessage());
        }
    }

    $upd = $pdo->prepare('UPDATE datacount_talonarios SET fondo = NULL WHERE id = :id');
    $upd->execute([':id' => $talonarioId]);

    registrarSuceso($pdo, 'datacount_talonarios', 'info',
        "Fondo eliminado talonario #{$talonarioId} — \"{$row['nombre']}\"");

    jsonOk(['id' => $talonarioId, 'fondo' => null, 'url' => null]);
}
