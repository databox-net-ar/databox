<?php
// api/usuarios_iniciar.php
// Genera un magic link ad-hoc para abrir la sesion del usuario destino en una
// ventana de incognito (impersonacion asistida desde el ABM de usuarios).
//
//   POST api/usuarios_iniciar.php?id=N
//     -> {ok:true, data:{link, expira, usuario:{id,nombre,correo}}}
//
// A diferencia de `usuarios_invitar.php`, este endpoint NO encola mail: la UI
// muestra el link en pantalla y el admin lo abre en incognito para no pisar la
// cookie `databox_token` de su propia sesion (la cookie tiene scope por dominio,
// no por pestana).
//
// Requisitos:
//   - Usuario destino debe existir y estar habilitado (`estado = '1'`).
//   - El caller debe tener el permiso `seguridad.usuarios.iniciar`.
// El token vive en la tabla `usuarios_invitaciones` (mismo storage que la
// invitacion por mail: ver 20260711_1500_crear_usuarios_invitaciones.sql). TTL
// corto (10 min) porque el admin lo va a abrir en el acto — no es un enlace
// diferido como el de invitacion por mail.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('seguridad.usuarios.iniciar');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, nombre, correo, estado FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();
    if (!$u)                                      jsonError('Usuario no encontrado', 404);
    if ((string)($u['estado'] ?? '') !== '1')     jsonError('Usuario deshabilitado', 400);

    // Token cripto-seguro (64 hex = 32 bytes). El indice `idx_usrinv_token` en
    // usuarios_invitaciones acelera la resolucion en auth.php?action=magic.
    $token   = bin2hex(random_bytes(32));
    $ahora   = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->format('Y-m-d H:i:s');
    // TTL corto: el admin abre el link ahora mismo. Si en 10 min no lo uso, se
    // vence — reduce la ventana de riesgo si el link se filtra por logs, etc.
    $expira  = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->modify('+10 minutes')
                 ->format('Y-m-d H:i:s');

    // `un_solo_uso = 0` -> multi-uso dentro de la ventana `expira`. Diferencia
    // este link del de invitacion por mail (que es de un solo uso).
    $pdo->prepare('
        INSERT INTO usuarios_invitaciones (usuario, token, expira, usado, un_solo_uso, creado)
        VALUES (:u, :t, :e, NULL, 0, :c)
    ')->execute([
        ':u' => (int)$u['id'],
        ':t' => $token,
        ':e' => $expira,
        ':c' => $ahora,
    ]);

    // URL absoluta al panel: derivada del Host del request para que funcione
    // en dev (localhost:8091) y prod (cloud.databox.net.ar) sin config.
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
              ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost:8091');
    $link   = "{$scheme}://{$host}/api/auth.php?action=magic&token={$token}";

    // Impersonacion = evento auditable. Dejamos rastro del quien-a-quien en
    // sucesos para que quede en el visor de sucesos del admin.
    $caller = currentAuth() ?: [];
    $callerId     = (int)($caller['sub']    ?? 0);
    $callerNombre = (string)($caller['nombre'] ?? '');
    registrarSuceso(
        $pdo, 'Usuarios', 'alerta',
        "Magic link de acceso generado por {$callerNombre} (#{$callerId}) "
      . "para {$u['correo']} (usuario #{$u['id']}), expira {$expira}"
    );

    jsonOk([
        'link'   => $link,
        'expira' => $expira,
        'usuario' => [
            'id'     => (int)$u['id'],
            'nombre' => (string)($u['nombre'] ?? ''),
            'correo' => (string)($u['correo'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
