<?php
// api/datacountfacturacion.php
// Control del motor de facturacion de Datacount + log de actividad.
//
// El "motor" es un proceso externo (corre fuera de la app cloud) que se
// enciende/apaga leyendo el parametro `datacount.motor` de la tabla
// `parametros` (1 = encendido, 0 = apagado).
//
//   GET  api/datacountfacturacion.php?action=status
//                                       -> { motor: '1'|'0', parametro_id: N }
//   GET  api/datacountfacturacion.php?action=log&since=<id>
//                                       -> { items: [...], last_id: N }
//   POST api/datacountfacturacion.php?action=motor   (body JSON)
//                                       -> { valor: '1'|'0' } => mismo formato que status
//
// El log todavia no tiene fuente real (no hay tabla ni archivo). El endpoint
// devuelve [] como scaffold; cuando el motor empiece a escribir, este handler
// es el unico punto a tocar para conectarlo.
//
// Respuesta siempre {ok: true, data: ...} u {ok: false, error: '...'} (STACK.md sec. 10).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

const MOTOR_VAR    = 'datacount.motor';
const MOTOR_COMENT = 'Motor de facturacion de Datacount (1 = encendido, 0 = apagado).';

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'status') {
        handleStatus($pdo);
    } elseif ($method === 'GET' && $action === 'log') {
        handleLog($pdo, $_GET);
    } elseif ($method === 'POST' && $action === 'motor') {
        handleMotor($pdo, readJsonBody());
    } else {
        jsonError('Accion no soportada', 400);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// Status del motor
// ----------------------------------------------------------------------------

function motorRow(PDO $pdo): ?array {
    $stmt = $pdo->prepare('SELECT id, valor FROM parametros WHERE variable = :v LIMIT 1');
    $stmt->execute([':v' => MOTOR_VAR]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function handleStatus(PDO $pdo): void {
    $row = motorRow($pdo);
    if ($row === null) {
        $ins = $pdo->prepare('INSERT INTO parametros (variable, valor, comentario)
                              VALUES (:v, :val, :c)');
        $ins->execute([':v' => MOTOR_VAR, ':val' => '0', ':c' => MOTOR_COMENT]);
        jsonOk(['motor' => '0', 'parametro_id' => (int)$pdo->lastInsertId()]);
    }
    $motor = ((string)$row['valor'] === '1') ? '1' : '0';
    jsonOk(['motor' => $motor, 'parametro_id' => (int)$row['id']]);
}

// ----------------------------------------------------------------------------
// Encender / Apagar motor
// ----------------------------------------------------------------------------

function handleMotor(PDO $pdo, array $in): void {
    $v = (string)($in['valor'] ?? '');
    if ($v !== '1' && $v !== '0') {
        jsonError('Valor invalido: usar "1" o "0".', 400);
    }

    $row = motorRow($pdo);
    if ($row === null) {
        $ins = $pdo->prepare('INSERT INTO parametros (variable, valor, comentario)
                              VALUES (:v, :val, :c)');
        $ins->execute([':v' => MOTOR_VAR, ':val' => $v, ':c' => MOTOR_COMENT]);
        jsonOk(['motor' => $v, 'parametro_id' => (int)$pdo->lastInsertId()]);
    }

    $upd = $pdo->prepare('UPDATE parametros SET valor = :val WHERE id = :id');
    $upd->execute([':val' => $v, ':id' => $row['id']]);
    jsonOk(['motor' => $v, 'parametro_id' => (int)$row['id']]);
}

// ----------------------------------------------------------------------------
// Log de actividad
// ----------------------------------------------------------------------------

function handleLog(PDO $pdo, array $q): void {
    // Scaffold: aun no hay tabla ni archivo de log del motor de facturacion.
    // Cuando exista, este handler debe devolver los registros nuevos con id > $since
    // en formato { items: [{id, fecha, nivel, mensaje}, ...], last_id: N }.
    $since = isset($q['since']) ? (int)$q['since'] : 0;
    jsonOk([
        'items'   => [],
        'last_id' => $since,
    ]);
}
