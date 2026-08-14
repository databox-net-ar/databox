<?php
/**
 * API cloud — AWS > Servidores: acciones de energia sobre una instancia EC2.
 *
 * Alimenta las opciones "Encender" / "Apagar" / "Reiniciar" que el menu
 * contextual de cada fila del ABM muestra debajo de "Consultar".
 *
 *   POST api/awsservidores_accion.php   { "id": 123, "accion": "encender" }
 *
 * Acciones validas: encender | apagar | reiniciar (mapeadas a
 * StartInstances / StopInstances / RebootInstances de la Query API de EC2).
 *
 * Respuesta:
 *   { ok:true, data:{ id, accion, estado_ec2, mensaje } }
 *   `estado_ec2` es el estado inmediato que devolvio AWS (`pending` /
 *   `stopping`), o null en el caso de reiniciar (que no reporta estado).
 *
 * Requiere el permiso `plataformas.aws.servidores.operar` — separado de los
 * verbos CRUD del ABM porque prender/apagar una instancia impacta sobre la
 * infraestructura viva, no sobre el catalogo: un usuario puede tener permiso
 * para editar la ficha (credenciales SSH, notas) sin poder apagar el server.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sucesos.php';
require_once __DIR__ . '/lib/awsec2.php';

header('Content-Type: application/json; charset=utf-8');

// accion de la UI -> accion de lib/awsec2.php + textos para log y respuesta.
const AWS_SRV_ACCIONES = [
    'encender'  => ['ec2' => 'start',  'txt' => 'Encender',  'hecho' => 'Encendido solicitado.'],
    'apagar'    => ['ec2' => 'stop',   'txt' => 'Apagar',    'hecho' => 'Apagado solicitado.'],
    'reiniciar' => ['ec2' => 'reboot', 'txt' => 'Reiniciar', 'hecho' => 'Reinicio solicitado.'],
];

try {
    requirePermission('plataformas.aws.servidores.operar');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Metodo no soportado', 405);
    }

    $in     = readJsonBody();
    $id     = (int)($in['id'] ?? 0);
    $accion = trim((string)($in['accion'] ?? ''));
    if ($id <= 0)                          jsonError('Falta id', 400);
    if (!isset(AWS_SRV_ACCIONES[$accion])) jsonError('Accion no soportada', 400);

    $pdo  = db();
    $stmt = $pdo->prepare('
        SELECT s.id, s.nombre, s.instance_id, s.region, s.existe,
               s.cuenta_id, c.nombre AS cuenta_nombre,
               c.accesskey, c.secreto
          FROM aws_servidores s
          LEFT JOIN aws_cuentas c ON c.id = s.cuenta_id
         WHERE s.id = :id
    ');
    $stmt->execute([':id' => $id]);
    $srv = $stmt->fetch();
    if (!$srv) jsonError('Servidor AWS no encontrado', 404);

    // La instancia solo es operable si el catalogo tiene los datos que vienen
    // de AWS (instance_id + region) y sigue viva del otro lado. Las filas
    // cargadas a mano antes del boton "Obtener" no tienen instance_id.
    $instanceId = trim((string)($srv['instance_id'] ?? ''));
    $region     = trim((string)($srv['region']      ?? ''));
    if ($instanceId === '') {
        jsonError('El servidor no tiene instance_id: correr «Obtener» antes de operarlo', 400);
    }
    if ($region === '') {
        jsonError('El servidor no tiene región cargada', 400);
    }
    if ((string)$srv['existe'] === '0') {
        jsonError('El servidor ya no existe en AWS', 409);
    }

    $accessKey = trim((string)($srv['accesskey'] ?? ''));
    $secretKey = trim((string)($srv['secreto']   ?? ''));
    if ($accessKey === '' || $secretKey === '') {
        jsonError('La cuenta AWS del servidor no tiene accesskey + secreto configurados', 400);
    }

    $cfg      = AWS_SRV_ACCIONES[$accion];
    $etiqueta = trim((string)($srv['nombre'] ?? '')) !== ''
        ? "{$srv['nombre']} ({$instanceId})"
        : $instanceId;

    $res = aws_ec2_instance_action($accessKey, $secretKey, $region, $cfg['ec2'], $instanceId);

    if ($res['error'] !== null) {
        registrarSuceso($pdo, 'awsservidores', 'error',
            "{$cfg['txt']} #{$id} — {$etiqueta}: {$res['error']}");
        jsonError("AWS rechazó la acción: {$res['error']}", 502);
    }

    // Reflejamos el estado inmediato para que la tabla no quede mostrando el
    // anterior hasta la proxima corrida de "Obtener". Reiniciar no devuelve
    // estado (la instancia sigue `running`), asi que ahi no pisamos nada.
    if ($res['estado'] !== null && $res['estado'] !== '') {
        $upd = $pdo->prepare('
            UPDATE aws_servidores
               SET estado_ec2  = :estado,
                   actualizado = CURRENT_TIMESTAMP
             WHERE id = :id
        ');
        $upd->execute([':estado' => $res['estado'], ':id' => $id]);
    }

    registrarSuceso($pdo, 'awsservidores', 'info',
        "{$cfg['txt']} #{$id} — {$etiqueta}"
        . ($res['estado'] ? " → estado: {$res['estado']}" : ''));

    jsonOk([
        'id'         => $id,
        'accion'     => $accion,
        'estado_ec2' => $res['estado'],
        'mensaje'    => $cfg['hecho'],
    ]);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}
