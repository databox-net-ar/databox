<?php
// api/usuarios_invitar.php
// Genera una invitacion "magic link" para un usuario y encola el mail en
// `awssesmensajes`. El enlace apunta a auth.php?action=magic&token=... — al
// clickearlo, el usuario queda logueado sin necesidad de contrasena.
//
//   POST api/usuarios_invitar.php?id=N
//     -> {ok:true, data:{destino, expira}}
//
// Requisitos:
//   - Usuario destino debe existir y tener `correo` cargado.
//   - El caller debe tener el permiso `seguridad.usuarios.invitar`.
// El token vive en la tabla `usuarios_invitaciones` (ver migracion
// 20260711_1500_crear_usuarios_invitaciones.sql) con expira +7 dias y un solo
// uso: al validarse en auth.php se marca `usado`.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requirePermission('seguridad.usuarios.invitar');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('Falta id', 400);

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, nombre, correo FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();
    if (!$u)                     jsonError('Usuario no encontrado', 404);
    if (empty(trim((string)$u['correo']))) {
        jsonError('El usuario no tiene un correo cargado', 400);
    }

    // Token cripto-seguro (64 hex = 32 bytes). El indice `idx_usrinv_token` en
    // usuarios_invitaciones acelera la resolucion en auth.php?action=magic.
    $token   = bin2hex(random_bytes(32));
    $ahora   = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->format('Y-m-d H:i:s');
    $expira  = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->modify('+7 days')
                 ->format('Y-m-d H:i:s');

    $pdo->prepare('
        INSERT INTO usuarios_invitaciones (usuario, token, expira, creado)
        VALUES (:u, :t, :e, :c)
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

    $nombreEsc = htmlspecialchars((string)($u['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
    $linkEsc   = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $expiraEsc = htmlspecialchars($expira, ENT_QUOTES, 'UTF-8');

    // Cuerpo HTML minimo con inline styles (los clientes de correo no cargan
    // stylesheets externos ni respetan clases). Coincide visualmente con la
    // paleta del panel (primary #4c1d95).
    $cuerpo = <<<HTML
<html><body style="margin:0;padding:24px;font-family:Segoe UI,Arial,sans-serif;color:#222;line-height:1.5;background:#f6f6f9">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;border:1px solid #e5e5ea">
    <p style="margin:0 0 12px">Hola {$nombreEsc},</p>
    <p style="margin:0 0 16px">Se te invito a acceder al panel de <strong>Databox Cloud</strong>. Hace click en el boton para ingresar sin necesidad de contrasena:</p>
    <p style="text-align:center;margin:24px 0">
      <a href="{$linkEsc}" style="display:inline-block;padding:12px 22px;background:#4c1d95;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Ingresar al panel</a>
    </p>
    <p style="margin:16px 0 6px;color:#666;font-size:.85em">Si el boton no funciona, copia y pega este enlace en tu navegador:</p>
    <p style="margin:0 0 20px;color:#666;font-size:.8em;word-break:break-all"><code>{$linkEsc}</code></p>
    <p style="margin:0;color:#888;font-size:.8em;border-top:1px solid #eee;padding-top:12px">El enlace expira el {$expiraEsc}. Es de un solo uso.</p>
  </div>
</body></html>
HTML;

    // Encolar mail. `canal=NULL` deja que el worker de envio elija el canal
    // por default de la config. `codificado='1'` guarda el HTML en base64
    // (convencion del grupo — evita escapes al pasar por triggers/etl).
    $pdo->prepare('
        INSERT INTO awssesmensajes
          (fecha, destinatario, destino, prioridad, asunto, cuerpo,
           codificado, formato, estado, encolado)
        VALUES
          (:fecha, :destinatario, :destino, :prioridad, :asunto, :cuerpo,
           :codificado, :formato, :estado, :encolado)
    ')->execute([
        ':fecha'        => $ahora,
        ':destinatario' => (string)($u['nombre'] ?? ''),
        ':destino'      => (string)$u['correo'],
        ':prioridad'    => 'N',
        ':asunto'       => 'Invitación al panel Databox Cloud',
        ':cuerpo'       => base64_encode($cuerpo),
        ':codificado'   => '1',
        ':formato'      => 'H',
        ':estado'       => 'P',
        ':encolado'     => $ahora,
    ]);

    registrarSuceso(
        $pdo, 'Usuarios', 'info',
        "Invitacion enviada a {$u['correo']} (usuario #{$u['id']}), expira {$expira}"
    );

    jsonOk(['destino' => (string)$u['correo'], 'expira' => $expira]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
