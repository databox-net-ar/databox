<?php
// cloud/api/lib/evolution_grupos.php
// Operaciones de dominio sobre canales y grupos de WhatsApp via Evolution API.
//
// Port de la clase `mcEvolution` del legacy
// (databox_legacy/databox-api/modulos/evolution.php), que es lo que alimentaba
// los 11 endpoints sueltos de `/v2/evolution`. Aca vive la logica; los
// endpoints de `api/v4/evolution/` solo validan el request, delegan y
// serializan. Una sola puerta por operacion: si mañana el ABM cloud quiere un
// listado de grupos, se cuelga de estas funciones y no escribe un tercer curl.
//
// ---------------------------------------------------------------------------
// COMO SE RESUELVE EL CANAL
// ---------------------------------------------------------------------------
// El legacy recibia un `canal` que era el uuid o el nombre de la instancia y lo
// resolvia con `uuid2id()`. En el esquema nuevo ese identificador es
// `evolution_canales.slug` (el instance name de Evolution) y la apikey de la
// instancia es `evolution_canales.token`. Se acepta:
//
//   canal_slug : slug de `evolution_canales.slug`   (forma canonica)
//   canal_id   : id numerico                        (alternativa)
//   canal      : alias legacy — numerico se trata como id, texto como slug
//
// Mismo criterio que /v4/evolution/mensajes: si vienen los dos, gana el slug.
//
// ---------------------------------------------------------------------------
// CONTRATO DE ERRORES
// ---------------------------------------------------------------------------
//   InvalidArgumentException -> el caller mando algo mal   (400 en la capa HTTP)
//   RuntimeException         -> Evolution fallo o rechazo  (502 en la capa HTTP)
//
// La distincion importa: un 400 lo arregla quien llama, un 502 no. El legacy
// devolvia HTTP 200 con el texto del error de cURL en el cuerpo, asi que un
// canal caido era indistinguible de un exito para cualquier cliente que mirara
// el status.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/evolution_api.php';

// Columnas del canal que se exponen hacia afuera. `token` queda AFUERA a
// proposito: es la apikey de la instancia en Evolution y con ella cualquiera
// manda mensajes en nombre del canal. No sale nunca en una respuesta.
const EVO_CANAL_COLS_PUBLICAS = [
    'id', 'slug', 'nombre', 'proyecto', 'prefijo', 'numero', 'celular',
    'online', 'latido', 'habilitado', 'actualizado',
];

// Acciones sobre participantes -> `action` que espera /group/updateParticipant.
// Se aceptan los verbos en castellano (los que se documentan) y los de la API
// en ingles, para que un caller que ya hablaba con Evolution directo no tenga
// que traducir.
const EVO_MIEMBRO_ACCIONES = [
    'agregar'  => 'add',
    'quitar'   => 'remove',
    'promover' => 'promote',
    'degradar' => 'demote',
    'add'      => 'add',
    'remove'   => 'remove',
    'promote'  => 'promote',
    'demote'   => 'demote',
];

// ---------------------------------------------------------------------------
// Canales
// ---------------------------------------------------------------------------

/**
 * Resuelve el canal a partir del payload/query del request y devuelve la fila
 * COMPLETA de `evolution_canales` (con token — es lo que consumen las
 * operaciones de abajo). Para responder al cliente usar evoCanalPublico().
 *
 * @throws InvalidArgumentException si falta el identificador, si no existe, o
 *         si el canal esta a medio configurar (sin slug o sin token).
 */
function evoCanalResolver(PDO $pdo, array $in): array {
    $slug = trim((string) ($in['canal_slug'] ?? ''));
    $id   = (int) ($in['canal_id'] ?? 0);

    // Alias legacy `canal`: numerico = id, cualquier otra cosa = slug.
    if ($slug === '' && $id <= 0 && isset($in['canal'])) {
        $canal = trim((string) $in['canal']);
        if ($canal !== '') {
            if (ctype_digit($canal)) $id = (int) $canal;
            else                     $slug = $canal;
        }
    }

    if ($slug === '' && $id <= 0) {
        throw new InvalidArgumentException('Falta canal (canal_slug o canal_id)');
    }

    $cols = implode(', ', array_merge(EVO_CANAL_COLS_PUBLICAS, ['token']));
    if ($slug !== '') {
        $st = $pdo->prepare("SELECT {$cols} FROM evolution_canales WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $slug]);
        $fila = $st->fetch();
        if (!$fila) throw new InvalidArgumentException("Canal con slug '{$slug}' no encontrado");
    } else {
        $st = $pdo->prepare("SELECT {$cols} FROM evolution_canales WHERE id = :i LIMIT 1");
        $st->execute([':i' => $id]);
        $fila = $st->fetch();
        if (!$fila) throw new InvalidArgumentException("Canal #{$id} no encontrado");
    }

    // Un canal sin slug o sin token no es un canal roto en Evolution: es una
    // fila incompleta en nuestra base. Cortar aca con 400 en vez de mandarle a
    // Evolution un request que va a rebotar con un 404 indescifrable.
    $faltantes = [];
    if (trim((string) ($fila['slug']  ?? '')) === '') $faltantes[] = 'slug';
    if (trim((string) ($fila['token'] ?? '')) === '') $faltantes[] = 'token';
    if ($faltantes) {
        throw new InvalidArgumentException(
            "Canal #{$fila['id']} sin configuracion completa (falta: " . implode(', ', $faltantes) . ')'
        );
    }

    return $fila;
}

/** Proyeccion del canal apta para devolver por la API (sin `token`). */
function evoCanalPublico(array $canal): array {
    $out = [];
    foreach (EVO_CANAL_COLS_PUBLICAS as $col) {
        $out[$col] = $canal[$col] ?? null;
    }
    $out['id']       = (int) $out['id'];
    $out['proyecto'] = $out['proyecto'] !== null ? (int) $out['proyecto'] : null;
    $out['online']   = ($out['online'] ?? '') === '1';
    return $out;
}

/**
 * Listado de canales desde la base (no consulta Evolution). Filtros opcionales:
 *   proyecto_slug | proyecto_id : acota a un proyecto
 *   habilitado                  : '1' | '0' — sin el, salen todos
 *
 * @throws InvalidArgumentException si el `proyecto_slug` no existe.
 */
function evoCanalesListar(PDO $pdo, array $filtros): array {
    $where  = [];
    $params = [];

    $proyectoId = null;
    $proyectoSlug = trim((string) ($filtros['proyecto_slug'] ?? ''));
    if ($proyectoSlug !== '') {
        $st = $pdo->prepare('SELECT id FROM proyectos WHERE slug = :s LIMIT 1');
        $st->execute([':s' => $proyectoSlug]);
        $pid = $st->fetchColumn();
        if ($pid === false) {
            throw new InvalidArgumentException("Proyecto con slug '{$proyectoSlug}' no encontrado");
        }
        $proyectoId = (int) $pid;
    } elseif (!empty($filtros['proyecto_id'])) {
        $proyectoId = (int) $filtros['proyecto_id'];
    }

    if ($proyectoId !== null) {
        $where[] = 'proyecto = :proyecto';
        $params[':proyecto'] = $proyectoId;
    }

    if (isset($filtros['habilitado']) && trim((string) $filtros['habilitado']) !== '') {
        $where[] = 'habilitado = :habilitado';
        $params[':habilitado'] = ((string) $filtros['habilitado']) === '1' ? '1' : '0';
    }

    $cols = implode(', ', EVO_CANAL_COLS_PUBLICAS);
    $sql  = "SELECT {$cols} FROM evolution_canales";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY nombre, id';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return array_map('evoCanalPublico', $st->fetchAll());
}

/**
 * Estado en vivo de la instancia del canal — el `canalEstado` del legacy.
 * Consulta /instance/fetchInstances con la apikey del canal y devuelve la
 * instancia que corresponde, cruda como la manda Evolution (connectionStatus,
 * ownerJid, profileName, contadores).
 *
 * Solo lectura: NO refresca el cache de `evolution_canales.canalEstado`. Esa
 * columna la mantiene el cron `evolution_canales_actualizar_estados.php`.
 *
 * @throws RuntimeException si Evolution no responde o no devuelve instancias.
 */
function evoCanalInstancia(array $canal): array {
    $r = evoApiLlamar('GET', '/instance/fetchInstances', (string) $canal['token']);
    if (!$r['ok']) {
        throw new RuntimeException('Consultar estado del canal: ' . $r['error']);
    }
    if (!is_array($r['data'])) {
        throw new RuntimeException(
            'Consultar estado del canal: respuesta no es JSON: ' . substr($r['raw'], 0, 200)
        );
    }

    $instancia = evoElegirInstancia($r['data'], $canal);
    if ($instancia === null) {
        throw new RuntimeException('Evolution devolvio 0 instancias para este canal');
    }

    // fetchInstances devuelve la apikey de la instancia adentro del payload.
    // Es el mismo secreto que se excluye de EVO_CANAL_COLS_PUBLICAS y con el
    // cualquiera manda mensajes como el canal, salteando la cola, el
    // throttling y el registro en `evolution_mensajes`. Se saca DESPUES de
    // elegir la instancia, porque el match usa el token. El legacy lo
    // devolvia; no es motivo para seguir haciendolo.
    unset($instancia['token']);

    return $instancia;
}

// ---------------------------------------------------------------------------
// Grupos — lectura
// ---------------------------------------------------------------------------

/**
 * Todos los grupos de la instancia. Con `$participantes = false` (default) la
 * respuesta es bastante mas liviana: Evolution resuelve la lista de miembros
 * grupo por grupo y en una cuenta con cientos de grupos eso se va de los 30s
 * de timeout.
 */
function evoGruposListar(array $canal, bool $participantes = false): mixed {
    $slug = rawurlencode((string) $canal['slug']);
    $flag = $participantes ? 'true' : 'false';
    $r = evoApiLlamar('GET', "/group/fetchAllGroups/{$slug}?getParticipants={$flag}",
                      (string) $canal['token']);
    return evoApiDatos($r, 'Listar grupos');
}

/** Ficha completa de un grupo: nombre, descripcion, owner, participantes. */
function evoGrupoPropiedades(array $canal, string $grupo): mixed {
    $slug  = rawurlencode((string) $canal['slug']);
    $jid   = evoGrupoJid($grupo);
    $r = evoApiLlamar('GET', "/group/findGroupInfos/{$slug}?groupJid=" . rawurlencode($jid),
                      (string) $canal['token']);
    return evoApiDatos($r, 'Consultar propiedades del grupo');
}

/** Codigo y enlace de invitacion del grupo (`{inviteCode, inviteUrl}`). */
function evoGrupoInvitacion(array $canal, string $grupo): mixed {
    $slug = rawurlencode((string) $canal['slug']);
    $jid  = evoGrupoJid($grupo);
    $r = evoApiLlamar('GET', "/group/inviteCode/{$slug}?groupJid=" . rawurlencode($jid),
                      (string) $canal['token']);
    return evoApiDatos($r, 'Obtener invitacion del grupo');
}

// ---------------------------------------------------------------------------
// Grupos — escritura
// ---------------------------------------------------------------------------

/**
 * Crea un grupo. WhatsApp exige al menos un participante ademas del propio
 * bot; si el caller no manda ninguno se usa el celular del canal, igual que
 * hacia el legacy.
 *
 * @param mixed $miembros Array de numeros, o string/numero separado por comas.
 */
function evoGrupoCrear(array $canal, string $nombre, mixed $miembros = null): mixed {
    $nombre = trim($nombre);
    if ($nombre === '') throw new InvalidArgumentException('Falta nombre del grupo');

    $jids = evoMiembrosJids($miembros);
    if (!$jids) {
        $propio = trim((string) ($canal['celular'] ?? ''));
        if ($propio === '') {
            throw new InvalidArgumentException(
                'Falta miembro: el canal no tiene `celular` cargado para usar por defecto'
            );
        }
        $jids = [evoJid($propio)];
    }

    $slug = rawurlencode((string) $canal['slug']);
    $r = evoApiLlamar('POST', "/group/create/{$slug}", (string) $canal['token'], [
        'subject'      => $nombre,
        'participants' => $jids,
    ]);
    return evoApiDatos($r, 'Crear grupo');
}

/** Cambia el nombre (subject) del grupo. */
function evoGrupoNombre(array $canal, string $grupo, string $nombre): mixed {
    $nombre = trim($nombre);
    if ($nombre === '') throw new InvalidArgumentException('Falta nombre del grupo');

    $r = evoApiLlamar('POST', evoGrupoRuta($canal, 'updateGroupSubject', $grupo),
                      (string) $canal['token'], ['subject' => $nombre]);
    return evoApiDatos($r, 'Cambiar nombre del grupo');
}

/**
 * Cambia la descripcion del grupo.
 *
 * `groupJid` viaja en el body ademas de en la query: asi lo mandaba el legacy
 * para esta operacion y para el icono, y es la forma que esta probada contra
 * el servidor. La query se agrega porque es la que documenta Evolution 2.x.
 */
function evoGrupoDescripcion(array $canal, string $grupo, string $descripcion): mixed {
    $jid = evoGrupoJid($grupo);
    $r = evoApiLlamar('POST', evoGrupoRuta($canal, 'updateGroupDescription', $grupo),
                      (string) $canal['token'], [
        'groupJid'    => $jid,
        'description' => $descripcion,
    ]);
    return evoApiDatos($r, 'Cambiar descripcion del grupo');
}

/** Cambia la foto del grupo. `$icono` es una URL publica de la imagen. */
function evoGrupoIcono(array $canal, string $grupo, string $icono): mixed {
    $icono = trim($icono);
    if ($icono === '') throw new InvalidArgumentException('Falta icono (URL de la imagen)');

    $jid = evoGrupoJid($grupo);
    $r = evoApiLlamar('POST', evoGrupoRuta($canal, 'updateGroupPicture', $grupo),
                      (string) $canal['token'], [
        'groupJid' => $jid,
        'image'    => $icono,
    ]);
    return evoApiDatos($r, 'Cambiar icono del grupo');
}

/**
 * Permisos del grupo. Cada flag es opcional — se manda a Evolution solo el que
 * el caller haya pedido cambiar (el legacy siempre mandaba los dos, con lo cual
 * tocar uno pisaba el otro con el default del caller).
 *
 * @param ?bool $escribir true = escriben todos | false = solo administradores
 * @param ?bool $editar   true = todos editan la info | false = solo administradores
 *
 * @return array Una clave por flag efectivamente aplicado.
 */
function evoGrupoPermisos(array $canal, string $grupo, ?bool $escribir, ?bool $editar): array {
    if ($escribir === null && $editar === null) {
        throw new InvalidArgumentException('Falta al menos uno de: escribir, editar');
    }

    $ruta  = evoGrupoRuta($canal, 'updateSetting', $grupo);
    $token = (string) $canal['token'];
    $out   = [];

    // Las dos llamadas son independientes y no hay forma de agruparlas: son dos
    // settings distintos del mismo endpoint. Si la primera falla se corta — un
    // exito parcial silencioso es peor que un error.
    if ($escribir !== null) {
        $accion = $escribir ? 'not_announcement' : 'announcement';
        $r = evoApiLlamar('POST', $ruta, $token, ['action' => $accion]);
        $out['escribir'] = evoApiDatos($r, 'Cambiar permiso de escritura del grupo');
    }
    if ($editar !== null) {
        $accion = $editar ? 'unlocked' : 'locked';
        $r = evoApiLlamar('POST', $ruta, $token, ['action' => $accion]);
        $out['editar'] = evoApiDatos($r, 'Cambiar permiso de edicion del grupo');
    }

    return $out;
}

/**
 * Agrega, quita, promueve o degrada miembros de un grupo.
 *
 * @param mixed  $miembros Array de numeros, o string/numero separado por comas.
 * @param string $accion   agregar | quitar | promover | degradar
 */
function evoGrupoMiembros(array $canal, string $grupo, mixed $miembros, string $accion): mixed {
    $accion = strtolower(trim($accion));
    if (!isset(EVO_MIEMBRO_ACCIONES[$accion])) {
        throw new InvalidArgumentException(
            "Accion '{$accion}' no valida. Opciones: agregar, quitar, promover, degradar"
        );
    }

    $jids = evoMiembrosJids($miembros);
    if (!$jids) throw new InvalidArgumentException('Falta miembro (numero o lista de numeros)');

    $r = evoApiLlamar('POST', evoGrupoRuta($canal, 'updateParticipant', $grupo),
                      (string) $canal['token'], [
        'action'       => EVO_MIEMBRO_ACCIONES[$accion],
        'participants' => $jids,
    ]);
    return evoApiDatos($r, 'Actualizar miembros del grupo');
}

// ---------------------------------------------------------------------------
// Helpers internos
// ---------------------------------------------------------------------------

/** Ruta `/group/<op>/<slug>?groupJid=<jid>`, que es la forma de casi toda la API de grupos. */
function evoGrupoRuta(array $canal, string $operacion, string $grupo): string {
    $slug = rawurlencode((string) $canal['slug']);
    $jid  = evoGrupoJid($grupo);
    return "/group/{$operacion}/{$slug}?groupJid=" . rawurlencode($jid);
}

/** Normaliza el identificador de grupo a JID (`...@g.us`). */
function evoGrupoJid(string $grupo): string {
    $grupo = trim($grupo);
    if ($grupo === '') throw new InvalidArgumentException('Falta grupo (JID del grupo)');
    return evoJid($grupo, 'g');
}

/**
 * Normaliza uno o varios numeros a JIDs de usuario. Acepta array o string
 * separado por comas (la forma que mandaban los endpoints del legacy).
 *
 * El tipo es `mixed` y no `array|string|null` a proposito: un celular en JSON
 * sin comillas (`"miembro": 5492644984568`) llega como int, y un type hint
 * estricto lo convertiria en un TypeError -> 500 por lo que en realidad es un
 * request perfectamente entendible.
 */
function evoMiembrosJids(mixed $miembros): array {
    if ($miembros === null) return [];
    if (is_scalar($miembros)) $miembros = explode(',', (string) $miembros);
    if (!is_array($miembros)) {
        throw new InvalidArgumentException('`miembros` tiene que ser un numero, una lista separada por comas, o un array');
    }

    $out = [];
    foreach ($miembros as $m) {
        $jid = evoJid(trim((string) $m));
        if ($jid !== '') $out[] = $jid;
    }
    return array_values(array_unique($out));
}

/**
 * Desempaqueta la respuesta de evoApiLlamar() o la convierte en excepcion.
 *
 * Evolution a veces contesta 2xx con `{"error": ...}` adentro — un 200 con el
 * pedido rechazado. Eso tambien es un fallo y tiene que salir como tal.
 *
 * @throws RuntimeException
 */
function evoApiDatos(array $r, string $operacion): mixed {
    if (!$r['ok']) throw new RuntimeException("{$operacion}: " . $r['error']);

    $data = $r['data'];
    if (is_array($data) && isset($data['error']) && $data['error'] !== '' && $data['error'] !== null) {
        $detalle = is_string($data['error'])
            ? $data['error']
            : json_encode($data['error'], JSON_UNESCAPED_UNICODE);
        throw new RuntimeException("{$operacion}: " . $detalle);
    }

    // Respuesta 2xx que no era JSON: se devuelve el cuerpo crudo en vez de un
    // null mudo. Pasa con algun endpoint de Evolution que contesta texto pelado.
    return $data ?? $r['raw'];
}
