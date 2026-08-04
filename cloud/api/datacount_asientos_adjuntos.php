<?php
// api/datacount_asientos_adjuntos.php
// Endpoint dedicado para subir y borrar adjuntos de un asiento contable
// (`datacount_asientos_adjuntos`). El binario se sube a S3 bajo la carpeta
// `datacount/asientos/`, y la metadata (nombre / cargado / formato / archivo /
// uuid) queda registrada en la tabla.
//
//   POST   api/datacount_asientos_adjuntos.php?asiento=N (multipart/form-data)
//       campo `archivo` (obligatorio): binario a subir.
//   DELETE api/datacount_asientos_adjuntos.php?id=N
//
// Respuesta: {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).
//
// Permisos: se pide `datacount.asientos.editar` para ambos verbos — modificar
// los adjuntos de un asiento es una edicion sobre el asiento mismo (no un ABM
// aparte), asi que reusamos el permiso del recurso padre en vez de crear uno
// propio (mismo patron que `datacount_pagos_adjuntos.php`).
//
// Convencion de key S3: `datacount/asientos/<uuid>.<ext>`. `uuid` es el mismo
// string aleatorio que se guarda en `datacount_asientos_adjuntos.uuid` y en la
// columna `archivo` (con extension) — asi la URL publica
// `https://media.databox.net.ar/datacount/asientos/<archivo>` matchea 1:1 sin
// necesidad de un mapeo extra. El nombre original del archivo NO se usa para
// la clave: se guarda en `nombre` solo como metadata para mostrar en la UI.

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/s3.php';

// Prefijo S3 donde viven los adjuntos. La URL publica se arma con
// `s3_public_url()`, que lee `AWS_S3_URL` del .env (fallback: endpoint
// path-style de amazonaws.com) — nada de dominios hardcodeados.
const DCAA_S3_PREFIX   = 'datacount/asientos/';
const DCAA_MAX_SIZE_MB = 20;

try {
    requirePermission('datacount.asientos.editar');
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $asientoId = isset($_GET['asiento']) ? (int)$_GET['asiento'] : 0;
        if ($asientoId <= 0) jsonError('Falta asiento', 400);
        handleUploadDcAsientoAdjunto($pdo, $asientoId);
    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) jsonError('Falta id', 400);
        handleDeleteDcAsientoAdjunto($pdo, $id);
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------

function handleUploadDcAsientoAdjunto(PDO $pdo, int $asientoId): void {
    // Verificamos que el asiento exista antes de tocar S3.
    $stmt = $pdo->prepare('SELECT id FROM datacount_asientos WHERE id = :id');
    $stmt->execute([':id' => $asientoId]);
    if (!$stmt->fetch()) jsonError('Asiento no encontrado', 404);

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
    if ($file['size'] > DCAA_MAX_SIZE_MB * 1024 * 1024) {
        jsonError('El archivo excede los ' . DCAA_MAX_SIZE_MB . 'MB', 400);
    }

    $contenido = file_get_contents($file['tmp_name']);
    if ($contenido === false) jsonError('No se pudo leer el archivo subido', 500);

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

    $nombreOriginal = basename($file['name'] ?? 'archivo');
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';

    // uuid corto (16 chars hex, mismo estilo que datacount_pagos_adjuntos.php).
    // Se usa como identificador de fila y como base del nombre en S3 — asi el
    // nombre en el bucket queda estandarizado (sin espacios / caracteres raros
    // del nombre original) y unico.
    $uuid    = bin2hex(random_bytes(8));
    $archivo = $uuid . ($ext !== '' ? '.' . $ext : '');
    $key     = DCAA_S3_PREFIX . $archivo;

    $res = s3_put_object($key, $contenido, $mimeReal);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        jsonError('S3 respondio HTTP ' . $res['status'], 500);
    }

    $cargado = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
        ->format('Y-m-d H:i:s');
    $nombre  = substr($nombreOriginal, 0, 50);
    $formato = $ext !== '' ? substr($ext, 0, 10) : null;

    $stmt = $pdo->prepare("
        INSERT INTO datacount_asientos_adjuntos
            (uuid, asiento, nombre, cargado, tipo, archivo, formato)
        VALUES
            (:uuid, :asiento, :nombre, :cargado, NULL, :archivo, :formato)
    ");
    $stmt->execute([
        ':uuid'    => $uuid,
        ':asiento' => $asientoId,
        ':nombre'  => $nombre,
        ':cargado' => $cargado,
        ':archivo' => $archivo,
        ':formato' => $formato,
    ]);

    jsonOk([
        'id'      => (int)$pdo->lastInsertId(),
        'uuid'    => $uuid,
        'asiento' => $asientoId,
        'nombre'  => $nombre,
        'cargado' => $cargado,
        'tipo'    => null,
        'archivo' => $archivo,
        'formato' => $formato,
        'url'     => s3_public_url($key),
    ], 201);
}

function handleDeleteDcAsientoAdjunto(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare('SELECT id, archivo FROM datacount_asientos_adjuntos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Adjunto no encontrado', 404);

    // Intentamos borrar el binario de S3 primero; si falla NO abortamos, para
    // no dejar filas huerfanas apuntando a un objeto que ya no queremos.
    if (!empty($row['archivo'])) {
        try {
            s3_delete_object(DCAA_S3_PREFIX . $row['archivo']);
        } catch (Throwable $e) {
            // Log silencioso — el DELETE de la fila sigue adelante.
            error_log('[datacount_asientos_adjuntos] S3 delete fallo para '
                    . $row['archivo'] . ': ' . $e->getMessage());
        }
    }

    $del = $pdo->prepare('DELETE FROM datacount_asientos_adjuntos WHERE id = :id');
    $del->execute([':id' => $id]);

    jsonOk(['id' => $id]);
}
