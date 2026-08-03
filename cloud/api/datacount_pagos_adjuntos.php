<?php
// api/datacount_pagos_adjuntos.php
// Endpoint dedicado para subir y borrar adjuntos de una orden de pago
// (`datacount_pagos_adjuntos`). El binario se sube a S3 bajo la carpeta
// `datacount/pagos/`, y la metadata (nombre / cargado / formato / archivo /
// uuid) queda registrada en la tabla.
//
//   POST   api/datacount_pagos_adjuntos.php?pago=N (multipart/form-data)
//       campo `archivo` (obligatorio): binario a subir.
//   DELETE api/datacount_pagos_adjuntos.php?id=N
//
// Respuesta: {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Permisos: se pide `datacount.pagos.editar` para ambos verbos — modificar los
// adjuntos de un pago es una edicion sobre el pago mismo (no un ABM aparte),
// asi que reusamos el permiso del recurso padre en vez de crear uno propio.
//
// Convencion de key S3: `datacount/pagos/<uuid>.<ext>`. `uuid` es el mismo
// string aleatorio que se guarda en `datacount_pagos_adjuntos.uuid` y en la
// columna `archivo` (con extension) — asi la URL publica
// `https://media.databox.net.ar/datacount/pagos/<archivo>` matchea 1:1 sin
// necesidad de un mapeo extra.

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';

// Prefijo S3 donde viven los adjuntos. La URL publica se arma con
// `s3_public_url()`, que lee `AWS_S3_URL` del .env (fallback: endpoint
// path-style de amazonaws.com) — nada de dominios hardcodeados.
const DCPA_S3_PREFIX   = 'datacount/pagos/';
const DCPA_MAX_SIZE_MB = 20;

try {
    requirePermission('datacount.pagos.editar');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $pagoId = isset($_GET['pago']) ? (int)$_GET['pago'] : 0;
        if ($pagoId <= 0) jsonError('Falta pago', 400);
        handleUploadDcPagoAdjunto($pdo, $pagoId);
    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteDcPagoAdjunto($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------

function handleUploadDcPagoAdjunto(PDO $pdo, int $pagoId): void {
    // Verificamos que el pago exista antes de tocar S3.
    $stmt = $pdo->prepare('SELECT id FROM datacount_pagos WHERE id = :id');
    $stmt->execute([':id' => $pagoId]);
    if (!$stmt->fetch()) jsonError('Pago no encontrado', 404);

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
        jsonError($errores[$code] ?? 'Error al subir archivo', 400);
    }

    $file = $_FILES['archivo'];
    if ($file['size'] > DCPA_MAX_SIZE_MB * 1024 * 1024) {
        jsonError('El archivo excede los ' . DCPA_MAX_SIZE_MB . 'MB', 400);
    }

    $contenido = file_get_contents($file['tmp_name']);
    if ($contenido === false) jsonError('No se pudo leer el archivo subido', 500);

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

    $nombreOriginal = basename($file['name'] ?? 'archivo');
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';

    // uuid corto (16 chars hex, mismo estilo que datacount_pagos.php). Se usa
    // como identificador de fila y como base del nombre en S3.
    $uuid    = bin2hex(random_bytes(8));
    $archivo = $uuid . ($ext !== '' ? '.' . $ext : '');
    $key     = DCPA_S3_PREFIX . $archivo;

    $res = s3_put_object($key, $contenido, $mimeReal);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        jsonError('S3 respondio HTTP ' . $res['status'], 500);
    }

    $cargado = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
        ->format('Y-m-d H:i:s');
    $nombre  = substr($nombreOriginal, 0, 50);
    $formato = $ext !== '' ? substr($ext, 0, 10) : null;

    $stmt = $pdo->prepare("
        INSERT INTO datacount_pagos_adjuntos
            (uuid, pago, nombre, cargado, tipo, archivo, formato)
        VALUES
            (:uuid, :pago, :nombre, :cargado, NULL, :archivo, :formato)
    ");
    $stmt->execute([
        ':uuid'    => $uuid,
        ':pago'    => $pagoId,
        ':nombre'  => $nombre,
        ':cargado' => $cargado,
        ':archivo' => $archivo,
        ':formato' => $formato,
    ]);

    jsonOk([
        'id'      => (int)$pdo->lastInsertId(),
        'uuid'    => $uuid,
        'pago'    => $pagoId,
        'nombre'  => $nombre,
        'cargado' => $cargado,
        'tipo'    => null,
        'archivo' => $archivo,
        'formato' => $formato,
        'url'     => s3_public_url($key),
    ], 201);
}

function handleDeleteDcPagoAdjunto(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, archivo FROM datacount_pagos_adjuntos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Adjunto no encontrado', 404);

    // Intentamos borrar el binario de S3 primero; si falla NO abortamos, para
    // no dejar filas huerfanas apuntando a un objeto que ya no queremos.
    if (!empty($row['archivo'])) {
        try {
            s3_delete_object(DCPA_S3_PREFIX . $row['archivo']);
        } catch (Throwable $e) {
            // Log silencioso — el DELETE de la fila sigue adelante.
            error_log('[datacount_pagos_adjuntos] S3 delete falló para '
                    . $row['archivo'] . ': ' . $e->getMessage());
        }
    }

    $del = $pdo->prepare('DELETE FROM datacount_pagos_adjuntos WHERE id = :id');
    $del->execute([':id' => $id]);

    jsonOk(['id' => $id]);
}
