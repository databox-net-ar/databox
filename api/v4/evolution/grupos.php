<?php
// api/v4/evolution/grupos.php
// Microservicio de gestion de grupos de WhatsApp via Evolution API.
//
//   GET   /v4/evolution/grupos?canal_slug=X                        -> listar grupos
//   GET   /v4/evolution/grupos?canal_slug=X&grupo=JID              -> propiedades del grupo
//   GET   /v4/evolution/grupos?canal_slug=X&grupo=JID&invitacion=1 -> enlace de invitacion
//   POST  /v4/evolution/grupos    (JSON body)                      -> crear grupo
//   PATCH /v4/evolution/grupos    (JSON body)                      -> nombre / descripcion / icono / permisos
//
// Auth: Bearer con apikey de la tabla `aplicaciones` (mismo esquema que el
// resto del stack — ver cloud/api/lib/apikey_auth.php).
//
// Port de los endpoints `/v2/evolution/canalGrupos`, `grupoCrear`,
// `grupoPropiedadesObtener`, `grupoInvitacionObtener`, `grupoNombreCambiar`,
// `grupoDescripcionCambiar`, `grupoIconoCambiar` y `grupoPermisosCambiar` del
// legacy. Los miembros van aparte, en /v4/evolution/grupoMiembros.
//
// Este microservicio NO guarda nada: Evolution es la fuente de verdad de los
// grupos y no hay tabla local que espejarlos. Todo GET es una consulta en vivo
// y todo POST/PATCH impacta en WhatsApp de inmediato.
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
v4InitLog('v4/evolution.grupos');

try {
    v4LogApp(requireAppApikey());

    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleConsultar($pdo, $_GET);
    } elseif ($method === 'POST') {
        handleCrear($pdo, readJsonBody());
    } elseif ($method === 'PATCH') {
        handleActualizar($pdo, readJsonBody());
    } else {
        jsonError('Metodo no soportado. Usa GET (consultar), POST (crear) o '
                . 'PATCH (nombre/descripcion/icono/permisos). Para agregar o '
                . 'quitar miembros: /v4/evolution/grupoMiembros.', 405);
    }

} catch (InvalidArgumentException $e) {
    // El caller mando algo que no corresponde (canal inexistente, falta el JID).
    jsonError($e->getMessage(), 400);
} catch (RuntimeException $e) {
    // Evolution no respondio o rechazo el pedido. No es culpa de quien llama.
    jsonError($e->getMessage(), 502);
} catch (Throwable $e) {
    jsonError($e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// GET -> listar / propiedades / invitacion
// ---------------------------------------------------------------------------
//
// Las tres lecturas comparten URL y se distinguen por los parametros, que es
// como estan modeladas del otro lado: sin `grupo` la pregunta es por la
// coleccion, con `grupo` es por un elemento, y `invitacion=1` pide un atributo
// de ese elemento que Evolution sirve por un endpoint aparte.

function handleConsultar(PDO $pdo, array $q): void {
    $canal = evoCanalResolver($pdo, $q);
    $grupo = trim((string)($q['grupo'] ?? ''));

    if ($grupo === '') {
        // `participantes=1` hace que Evolution resuelva la lista de miembros de
        // CADA grupo. En una cuenta con cientos de grupos eso se va del timeout,
        // asi que el default es no pedirlos — para los miembros de uno solo esta
        // la consulta por `grupo`.
        $items = evoGruposListar($canal, grpFlag($q['participantes'] ?? null));
        jsonOk([
            'total' => is_array($items) ? count($items) : null,
            'items' => $items,
        ]);
    }

    if (grpFlag($q['invitacion'] ?? null)) {
        jsonOk(evoGrupoInvitacion($canal, $grupo));
    }

    jsonOk(evoGrupoPropiedades($canal, $grupo));
}

// ---------------------------------------------------------------------------
// POST -> crear grupo
// ---------------------------------------------------------------------------
//
// WhatsApp no permite crear un grupo vacio. Si el body no trae miembros se usa
// el celular del propio canal (mismo default que el legacy), con lo cual queda
// un grupo con el bot adentro al que despues se le agregan participantes por
// /v4/evolution/grupoMiembros.

function handleCrear(PDO $pdo, array $in): void {
    $canal    = evoCanalResolver($pdo, $in);
    $nombre   = (string)($in['nombre'] ?? '');
    $miembros = $in['miembros'] ?? $in['miembro'] ?? null;

    $resp = evoGrupoCrear($canal, $nombre, $miembros);
    jsonOk($resp, 201);
}

// ---------------------------------------------------------------------------
// PATCH -> nombre / descripcion / icono / permisos
// ---------------------------------------------------------------------------
//
// Un PATCH y no cuatro endpoints: del lado de Evolution son cuatro llamadas
// distintas, pero del lado del integrador es una sola intencion ("cambiar la
// configuracion de este grupo") y la mitad de las veces se tocan dos cosas
// juntas. Se aplica unicamente lo que venga en el body.
//
// OJO: no es atomico. Son hasta cinco requests contra Evolution y WhatsApp no
// ofrece forma de agruparlos; si el tercero falla, los dos primeros ya se
// aplicaron. Por eso la respuesta lleva `aplicado` con lo que efectivamente
// paso, y un fallo corta ahi en vez de seguir.

function handleActualizar(PDO $pdo, array $in): void {
    $canal = evoCanalResolver($pdo, $in);
    $grupo = (string)($in['grupo'] ?? '');
    if (trim($grupo) === '') jsonError('Falta grupo (JID del grupo)', 400);

    $escribir = grpBool($in['escribir'] ?? null);
    $editar   = grpBool($in['editar']   ?? null);

    $tiene = static fn(string $k): bool => isset($in[$k]) && trim((string)$in[$k]) !== '';

    if (!$tiene('nombre') && !array_key_exists('descripcion', $in) && !$tiene('icono')
        && $escribir === null && $editar === null) {
        jsonError('Nada que cambiar. Campos aceptados: nombre, descripcion, '
                . 'icono, escribir, editar.', 400);
    }

    $aplicado = [];

    if ($tiene('nombre')) {
        $aplicado['nombre'] = evoGrupoNombre($canal, $grupo, (string)$in['nombre']);
    }
    // `descripcion` se chequea con array_key_exists y no con $tiene: la cadena
    // vacia es un valor legitimo (borrar la descripcion del grupo).
    if (array_key_exists('descripcion', $in)) {
        $aplicado['descripcion'] = evoGrupoDescripcion($canal, $grupo, (string)$in['descripcion']);
    }
    if ($tiene('icono')) {
        $aplicado['icono'] = evoGrupoIcono($canal, $grupo, (string)$in['icono']);
    }
    if ($escribir !== null || $editar !== null) {
        $aplicado['permisos'] = evoGrupoPermisos($canal, $grupo, $escribir, $editar);
    }

    jsonOk([
        'grupo'    => evoGrupoJid($grupo),
        'aplicado' => $aplicado,
    ]);
}

// ---------------------------------------------------------------------------
// Helpers de parseo
// ---------------------------------------------------------------------------

/**
 * Flag de query string: `1`, `true`, `si` y `on` valen true; el resto (incluido
 * el parametro ausente) false. Tolerante a proposito — un `?participantes=true`
 * y un `?participantes=1` quieren decir lo mismo y nadie lee la documentacion
 * para eso.
 */
function grpFlag(mixed $v): bool {
    if ($v === null) return false;
    if (is_bool($v)) return $v;
    return in_array(strtolower(trim((string)$v)), ['1', 'true', 'si', 'on'], true);
}

/**
 * Booleano de un campo OPCIONAL del body: distingue "no vino" (null) de
 * "vino en false". Sin esa distincion un PATCH que solo cambia el nombre
 * pisaria los permisos del grupo con los defaults.
 */
function grpBool(mixed $v): ?bool {
    if ($v === null) return null;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    if ($s === '') return null;
    if (in_array($s, ['1', 'true', 'si', 'on'],  true)) return true;
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
    throw new InvalidArgumentException("Valor '{$v}' no es un booleano valido (usa true/false o 1/0)");
}
