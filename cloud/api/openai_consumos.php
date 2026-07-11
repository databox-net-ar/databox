<?php
// api/openai_consumos.php
// Snapshots persistidos del estado de cuenta OpenAI.
//
// GET  api/openai_consumos.php
//   -> devuelve el ultimo snapshot guardado:
//      { ok: true, data: { fecha, edad_seg, snapshot: {...} } }
//   Si no hay snapshots todavia:
//      { ok: true, data: { fecha: null, edad_seg: null, snapshot: null } }
//
// GET  api/openai_consumos.php?id=N
//   -> devuelve un snapshot puntual (misma forma que el modo anterior).
//
// GET  api/openai_consumos.php?historial=1
//   -> devuelve la lista de snapshots guardados (sin el JSON pesado), para
//      poblar el modal de seleccion de fecha:
//      { ok: true, data: [{ id, fecha }, ...] }  (recientes primero, max 500)
//
// POST api/openai_consumos.php
//   -> fuerza un refresh: golpea la Admin API de OpenAI, guarda un nuevo
//      registro en `openai_consumos` y devuelve el snapshot recien tomado.
//   Rate limit: 60s desde el ultimo snapshot. Si se llama antes, responde
//   429 con { ok: false, error, retry_en_seg }.
//
// La vista /openai llama GET al montarse y POST cuando el usuario pulsa el
// boton Refrescar. El JSON persistido tiene la misma forma que devolvia el
// endpoint openai_kpis.php combinado con openai_apikeys.php (aplanado en un
// solo objeto).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/openai_admin.php';
require_once __DIR__ . '/lib/openai_snapshot.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();
requirePermission('plataformas.openai.consumos.consultar');
$pdo = db();

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'GET' && isset($_GET['historial'])) {
    devolverHistorial($pdo);
} elseif ($metodo === 'GET' && !empty($_GET['id'])) {
    devolverSnapshotPorId($pdo, (int)$_GET['id']);
} elseif ($metodo === 'GET') {
    devolverUltimoSnapshot($pdo);
} elseif ($metodo === 'POST') {
    refrescarSnapshot($pdo);
} else {
    jsonError('Metodo no soportado', 405);
}

// -----------------------------------------------------------------------------

const OPENAI_CONSUMOS_MIN_INTERVALO_SEG = 60;

// Historial reducido para el modal de seleccion de fecha: solo id + fecha,
// sin el JSON pesado. Cap en 500 filas — suficiente para varios meses de
// snapshots a razon de uno por hora.
function devolverHistorial(PDO $pdo): void {
    $rows = $pdo->query(
        'SELECT id, fecha
         FROM openai_consumos
         ORDER BY fecha DESC, id DESC
         LIMIT 500'
    )->fetchAll();

    $out = array_map(fn($r) => [
        'id'    => (int)$r['id'],
        'fecha' => (string)$r['fecha'],
    ], $rows);

    jsonOk($out);
}

function devolverSnapshotPorId(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare(
        'SELECT id, fecha, datos, TIMESTAMPDIFF(SECOND, fecha, NOW()) AS edad_seg
         FROM openai_consumos
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Snapshot no encontrado', 404);

    $snapshot = null;
    if (!empty($row['datos'])) {
        $snapshot = json_decode((string)$row['datos'], true);
        if (!is_array($snapshot)) $snapshot = null;
    }

    jsonOk([
        'fecha'    => $row['fecha'],
        'edad_seg' => (int)$row['edad_seg'],
        'snapshot' => $snapshot,
    ]);
}

function devolverUltimoSnapshot(PDO $pdo): void {
    $row = $pdo->query(
        'SELECT id, fecha, datos, TIMESTAMPDIFF(SECOND, fecha, NOW()) AS edad_seg
         FROM openai_consumos
         ORDER BY fecha DESC, id DESC
         LIMIT 1'
    )->fetch();

    if (!$row) {
        jsonOk(['fecha' => null, 'edad_seg' => null, 'snapshot' => null]);
    }

    $snapshot = null;
    if (!empty($row['datos'])) {
        $snapshot = json_decode((string)$row['datos'], true);
        if (!is_array($snapshot)) $snapshot = null;
    }

    jsonOk([
        'fecha'    => $row['fecha'],
        'edad_seg' => (int)$row['edad_seg'],
        'snapshot' => $snapshot,
    ]);
}

function refrescarSnapshot(PDO $pdo): void {
    // Rate limit contra el snapshot mas reciente.
    $row = $pdo->query(
        'SELECT TIMESTAMPDIFF(SECOND, fecha, NOW()) AS edad_seg
         FROM openai_consumos
         ORDER BY fecha DESC, id DESC
         LIMIT 1'
    )->fetch();
    if ($row && (int)$row['edad_seg'] < OPENAI_CONSUMOS_MIN_INTERVALO_SEG) {
        $restan = OPENAI_CONSUMOS_MIN_INTERVALO_SEG - (int)$row['edad_seg'];
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'           => false,
            'error'        => "Hay un snapshot muy reciente. Esperá {$restan}s para refrescar.",
            'retry_en_seg' => $restan,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $apiKey = openaiAdminKey($pdo);
    } catch (Throwable $e) {
        registrarSuceso($pdo, 'openai_consumos', 'alerta', $e->getMessage());
        jsonError($e->getMessage(), 400);
    }

    $snapshot = openaiCapturarSnapshot($pdo, $apiKey);

    $stmt = $pdo->prepare(
        'INSERT INTO openai_consumos (fecha, datos) VALUES (NOW(), :d)'
    );
    $stmt->execute([':d' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);

    $id = (int)$pdo->lastInsertId();
    $fila = $pdo->prepare(
        'SELECT fecha FROM openai_consumos WHERE id = :id LIMIT 1'
    );
    $fila->execute([':id' => $id]);
    $r = $fila->fetch();

    registrarSuceso($pdo, 'openai_consumos', 'info',
        "Snapshot #{$id} guardado (" . count($snapshot['apikeys'] ?? []) . ' keys)');

    jsonOk([
        'fecha'    => $r['fecha'] ?? null,
        'edad_seg' => 0,
        'snapshot' => $snapshot,
    ]);
}
