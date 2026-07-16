<?php
// api/perfil.php
// Perfil del usuario logueado. Solo requiere sesion valida — cualquier usuario
// autenticado puede leer sus propios datos y cambiar su propia contrasena;
// no hay permiso especifico porque no se puede operar sobre otro usuario.
//
//   GET api/perfil.php             -> nombre, correo, celular del usuario actual
//   PUT api/perfil.php             -> cambiar contrasena
//     body: { contrasena_nueva }
//
// La contrasena se guarda con encriptar() (cifra reversible legacy compartida
// con las otras apps del grupo — ver db.php y auth.php). No se pide la
// contrasena actual: la sesion valida (cookie JWT) ya prueba identidad.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth   = requireAuth();
    $userId = (int)($auth['sub'] ?? 0);
    if ($userId <= 0) jsonError('Sesion invalida', 401);

    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleGet($pdo, $userId);
    } elseif ($method === 'PUT') {
        handleCambiarContrasena($pdo, $userId, readJsonBody());
    } else {
        jsonError('Metodo no soportado', 405);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

function handleGet(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('SELECT id, nombre, correo, celular FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Usuario no encontrado', 404);
    jsonOk([
        'id'      => (int)$row['id'],
        'nombre'  => $row['nombre']  ?? '',
        'correo'  => $row['correo']  ?? '',
        'celular' => $row['celular'] ?? '',
    ]);
}

function handleCambiarContrasena(PDO $pdo, int $userId, array $in): void {
    $nueva = (string)($in['contrasena_nueva'] ?? '');

    if ($nueva === '')        jsonError('La contrasena nueva es obligatoria', 400);
    if (strlen($nueva) < 4)   jsonError('La contrasena nueva debe tener al menos 4 caracteres', 400);
    if (strlen($nueva) > 100) jsonError('La contrasena nueva no puede superar 100 caracteres', 400);

    $stmt = $pdo->prepare('UPDATE usuarios SET contrasena = :c WHERE id = :id');
    $stmt->execute([':c' => encriptar($nueva), ':id' => $userId]);
    if ($stmt->rowCount() === 0) {
        // rowCount = 0 puede ser "sin cambios" (misma cifra que ya tenia) o
        // "usuario no existe". Chequeamos existencia solo si no hubo update.
        $chk = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $userId]);
        if (!$chk->fetch()) jsonError('Usuario no encontrado', 404);
    }

    $u = $pdo->prepare('SELECT correo FROM usuarios WHERE id = :id LIMIT 1');
    $u->execute([':id' => $userId]);
    $correo = (string)($u->fetchColumn() ?: '');
    registrarSuceso(
        $pdo, 'Autenticacion', 'info',
        "Contrasena restablecida por el propio usuario \"{$correo}\" (#{$userId})"
    );

    jsonOk(['ok' => true]);
}
