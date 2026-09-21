<?php
// api/v4/evolution/grupoMiembros.php
// Microservicio de administracion de miembros de un grupo de WhatsApp.
//
//   POST /v4/evolution/grupoMiembros  (JSON body) -> agregar | quitar | promover | degradar
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto del stack — ver cloud/api/lib/apikey_auth.php).
//
// Port de `/v2/evolution/grupoMiembroAnadir`, `grupoMiembroQuitar` y
// `grupoMiembroCambiar` del legacy. Los tres pegaban contra el mismo endpoint
// de Evolution (/group/updateParticipant) cambiando un `action`, asi que aca
// son un endpoint con un campo `accion`.
//
// POR QUE NO ESTA ADENTRO DE /v4/evolution/grupos
// -----------------------------------------------
// Los miembros son una subcoleccion del grupo y merecen URL propia; meterlos
// en el PATCH de `grupos` mezclaria "cambiarle el nombre al grupo" (idempotente)
// con "sacar a cuatro personas" (no lo es, y no se deshace). Que sean dos URLs
// distintas hace que nadie las confunda en un bulk.
//
// El nombre va en singular-posesivo (`grupoMiembros`, los miembros DE UN grupo)
// y no como recurso propio `miembros`: no existen miembros sueltos, siempre son
// de un grupo. Mismo criterio de nombres que /v4/mercadopago/suscripcionCrear.
//
// La logica vive en cloud/api/lib/evolution_grupos.php; aca solo se valida el
// request y se serializa la respuesta.

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/env.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/evolution_grupos.php';
require_once dirname(__DIR__) . '/_lib/log.php';
require_once dirname(__DIR__, 3) . '/cloud/api/lib/apikey_auth.php';

// Todo error de este endpoint queda registrado en `sucesos` (Visor de sucesos
// del panel). Va antes de la auth para que los 401 tambien caigan adentro.
v4InitLog('v4/evolution.grupoMiembros');

try {
    v4LogApp(requireAppApikey());

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        jsonError('Metodo no soportado. Usa POST con `accion`: agregar, quitar, '
                . 'promover o degradar. Para ver los miembros actuales: '
                . 'GET /v4/evolution/grupos?canal_slug=X&grupo=JID.', 405);
    }

    handleMiembros(db(), readJsonBody());

} catch (InvalidArgumentException $e) {
    // El caller mando algo que no corresponde (accion invalida, falta el JID).
    jsonError($e->getMessage(), 400);
} catch (RuntimeException $e) {
    // Evolution no respondio o rechazo el pedido. No es culpa de quien llama.
    jsonError($e->getMessage(), 502);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// POST /v4/evolution/grupoMiembros
// ---------------------------------------------------------------------------
//
// `accion` es obligatoria y no tiene default a proposito: entre agregar y
// quitar hay un error que no se deshace, y un default convierte un campo
// olvidado en una operacion silenciosa sobre gente real.
//
// La respuesta de Evolution es por participante — devuelve un status por cada
// numero — porque el resultado puede ser mixto: se agregan tres de cuatro
// porque uno tiene la privacidad cerrada, y eso NO es un error del request.

function handleMiembros(PDO $pdo, array $in): void {
    $canal = evoCanalResolver($pdo, $in);

    $grupo = (string)($in['grupo'] ?? '');
    if (trim($grupo) === '') jsonError('Falta grupo (JID del grupo)', 400);

    $accion = trim((string)($in['accion'] ?? ''));
    if ($accion === '') {
        jsonError('Falta accion. Opciones: agregar, quitar, promover, degradar.', 400);
    }

    $miembros = $in['miembros'] ?? $in['miembro'] ?? null;
    if ($miembros === null) {
        jsonError('Falta miembro (numero, lista separada por comas, o array `miembros`)', 400);
    }

    $resp = evoGrupoMiembros($canal, $grupo, $miembros, $accion);

    jsonOk([
        'grupo'     => evoGrupoJid($grupo),
        'accion'    => strtolower($accion),
        'miembros'  => evoMiembrosJids($miembros),
        'respuesta' => $resp,
    ]);
}
