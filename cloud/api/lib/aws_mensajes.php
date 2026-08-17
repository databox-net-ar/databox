<?php
// api/lib/aws_mensajes.php
// Punto UNICO de entrada para insertar en `aws_mensajes`. Cualquier caller
// (endpoint HTTP del ABM, futuro microservicio v4, script CLI, job manual)
// debe pasar por encolarAwsMensaje() para garantizar las mismas reglas de
// ingreso — sanitizacion, obligatorios, defaults y wake-on-demand del cron.
// Espeja el criterio del sender centralizado en aws_mensajes_enviar.
//
// Contrato:
//   int encolarAwsMensaje(PDO $pdo, array $datos)
//     -> devuelve el id insertado
//     -> tira InvalidArgumentException si faltan obligatorios (la capa HTTP
//        lo traduce a 400 via jsonError)
//     -> como efecto lateral llama marcarColaAwsConPendientes() asi el cron
//        proxima corrida encuentra el flag activo y despacha (self-heal del
//        propio cron lo baja a '0' cuando la cola queda vacia)

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/datarocket_interacciones.php';

// Mapa columna -> regla del sanitizador. Reusado por sanitizeAwsMsgPayload.
// (No hay variante partial: los mensajes encolados no se editan.)
const AWS_MSG_SANITIZERS = [
    'uuid'         => 'str:255',
    'fecha'        => 'dt',
    'proyecto_id'  => 'int',
    'canal_id'     => 'int',
    'plantilla_id' => 'int',
    'contacto_id'  => 'int',
    'remitente'    => 'str:255',
    'remite'       => 'str:255',
    'destinatario' => 'str:255',
    'destino'      => 'str:255',
    'prioridad'    => 'int',
    'asunto'       => 'str:255',
    'cuerpo'       => 'str',
    'formato'      => 'str:10',
    'adjunto'      => 'str:500',
    'tags'         => 'str:255',
    'estado'       => 'str:20',
    'error'        => 'str:1000',
    'resultado'    => 'str:20',
    'encolado'     => 'dt',
    'programado'   => 'dt',
    'enviado'      => 'dt',
    'demora'       => 'int',
];

// Campos obligatorios al encolar un mensaje nuevo. Coincide con lo que exige
// el sender: sin proyecto/canal no puede firmar contra SES, sin destino no
// puede rutear.
const AWS_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Canal',
    'destino'     => 'Destino',
];

// Obligatorios adicionales cuando NO hay plantilla — con plantilla estos
// campos los aporta `datarocket_plantillas` (ver aplicarPlantillaAws()).
const AWS_MSG_REQUERIDOS_SIN_PLANTILLA = [
    'remite' => 'Remite',
    'asunto' => 'Asunto',
    'cuerpo' => 'Cuerpo',
];

// ----------------------------------------------------------------------------
// API publica
// ----------------------------------------------------------------------------

/**
 * Punto UNICO de entrada para insertar en `aws_mensajes`.
 * Cualquier caller debe pasar por aca — evita que dos consumidores distintos
 * apliquen reglas de ingreso divergentes.
 */
function encolarAwsMensaje(PDO $pdo, array $datos): int {
    $p = sanitizeAwsMsgPayload($pdo, $datos);

    // Validar obligatorios antes de tocar la BD. Con plantilla,
    // remite/asunto/cuerpo los aporta datarocket_plantillas (ya aplicada por
    // aplicarPlantillaAws() dentro del sanitize).
    $faltantes = [];
    foreach (AWS_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
    }
    if ($p['plantilla_id'] === null) {
        foreach (AWS_MSG_REQUERIDOS_SIN_PLANTILLA as $col => $label) {
            if ($p[$col] === null) $faltantes[] = $label;
        }
    }
    if ($faltantes) {
        throw new InvalidArgumentException(
            'Faltan campos obligatorios: ' . implode(', ', $faltantes)
        );
    }

    // Defaults system-managed:
    //   - fecha    : NOW() en zona AR (los sanitizadores no la generan).
    //   - encolado : mismo instante que fecha (evita drift entre ambos).
    //   - estado   : 'pendiente' — el sender lo pasa a 'enviando'/'enviado'/'error'.
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    if ($p['encolado'] === null) $p['encolado'] = $p['fecha'];
    if ($p['estado']   === null) $p['estado']   = 'pendiente';

    // Resolucion de contacto_id a partir del destino: buscamos en
    // `datarocket_contactos.correo` y, si no existe, damos de alta el contacto
    // antes de insertar el mensaje. Si el caller ya paso un contacto_id
    // explicito lo respetamos (por ejemplo, un futuro flujo que ya lo tenga
    // resuelto no paga el lookup de nuevo).
    if ($p['contacto_id'] === null) {
        $p['contacto_id'] = resolverContactoIdAws($pdo, $p['destino'], $p['destinatario']);
    }

    $sql = "
        INSERT INTO aws_mensajes
            (fecha, proyecto_id, canal_id, plantilla_id, contacto_id, remitente,
             remite, destinatario, destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:fecha, :proyecto_id, :canal_id, :plantilla_id, :contacto_id, :remitente,
             :remite, :destinatario, :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
        ':proyecto_id'  => $p['proyecto_id'],
        ':canal_id'     => $p['canal_id'],
        ':plantilla_id' => $p['plantilla_id'],
        ':contacto_id'  => $p['contacto_id'],
        ':remitente'    => $p['remitente'],
        ':remite'       => $p['remite'],
        ':destinatario' => $p['destinatario'],
        ':destino'      => $p['destino'],
        ':prioridad'    => $p['prioridad'],
        ':asunto'       => $p['asunto'],
        ':cuerpo'       => $p['cuerpo'],
        ':formato'      => $p['formato'],
        ':adjunto'      => $p['adjunto'],
        ':tags'         => $p['tags'],
        ':estado'       => $p['estado'],
        ':error'        => $p['error'],
        ':encolado'     => $p['encolado'],
        ':programado'   => $p['programado'],
        ':enviado'      => $p['enviado'],
        ':demora'       => $p['demora'],
    ]);
    $id = (int)$pdo->lastInsertId();

    // Registrar la interaccion en el historial del contacto. Best-effort:
    // si falla no tira, el mensaje ya quedo en la cola. Ver
    // cloud/api/lib/datarocket_interacciones.php.
    registrarInteraccionMensaje(
        $pdo,
        $p['contacto_id'],
        'saliente',
        'correo',
        $p['asunto'] ?? $p['destino'],
        $p['cuerpo'] ?? null,
        $p['fecha']
    );

    // Wake-on-demand: si el mensaje quedo pendiente (99% de los casos al
    // encolar), avisar al cron worker. Idempotente — si ya vale '1' el UPDATE
    // no cambia nada.
    if ($p['estado'] === 'pendiente') marcarColaAwsConPendientes($pdo);

    return $id;
}

// ----------------------------------------------------------------------------
// Sanitizacion (compartida con handleUpdate del endpoint)
// ----------------------------------------------------------------------------

// Sanitiza TODAS las columnas — los faltantes quedan NULL. Usado por
// encolarAwsMensaje al crear. No hay variante partial porque los mensajes
// encolados no se editan (ver comentario en cloud/api/awsmensajes.php).
function sanitizeAwsMsgPayload(PDO $pdo, array $in): array {
    // Resolucion de slugs -> ids (proyecto_slug, canal_slug, plantilla_slug).
    // Si vienen ambos slug + id, el slug gana y se ignora el id.
    $in = resolverAwsMsgSlugs($pdo, $in);

    // Si viene plantilla_id, expandirla: la plantilla aporta remitente/remite/
    // asunto/cuerpo/formato/adjunto y el body inyecta {asunto}/{cuerpo}/{...vars}
    // como variables dentro de esos strings. Mirror del legacy v3
    // databox_legacy/databox-api/v3/awsses/mensajes/index.php.
    $in = aplicarPlantillaAws($pdo, $in);

    $out = [];
    foreach (AWS_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applyAwsMsgSanitizer($rule, $in[$col] ?? null);
    }
    return $out;
}

// Expande el mensaje con los campos de `datarocket_plantillas` cuando viene
// `plantilla_id`. Semantica identica al legacy v3:
//
//   - remitente / remite / adjunto  -> pisan lo del body (los toma la plantilla).
//   - formato                       -> pisan lo del body, mapeado H->html, T->texto,
//                                       M->null (Markdown deprecado en el sender).
//   - asunto                        -> plantilla.asunto con str_replace('{asunto}', body.asunto).
//   - cuerpo                        -> plantilla.cuerpo con str_replace('{cuerpo}', body.cuerpo).
//   - variables (JSON opcional)     -> hace str_replace('{clave}', valor) sobre asunto y cuerpo.
//
// Si no viene plantilla_id, no toca nada. Si viene pero no matchea, el error
// ya lo tiro resolverAwsMsgSlugs (que tambien resuelve plantilla_id numerico
// bypasseando esto — validamos aca de nuevo por defensa).
function aplicarPlantillaAws(PDO $pdo, array $in): array {
    $plantillaId = (int)($in['plantilla_id'] ?? 0);
    if ($plantillaId <= 0) return $in;

    $st = $pdo->prepare(
        "SELECT remitente, remite, asunto, cuerpo, formato, adjunto
           FROM datarocket_plantillas WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => $plantillaId]);
    $tpl = $st->fetch();
    if (!$tpl) {
        throw new InvalidArgumentException("Plantilla id={$plantillaId} no encontrada");
    }

    // Renderizado de asunto y cuerpo: primero {asunto}/{cuerpo} desde el body,
    // despues cada clave del JSON `variables` (si vino).
    $asuntoBody = (string)($in['asunto'] ?? '');
    $cuerpoBody = (string)($in['cuerpo'] ?? '');
    $asunto     = str_replace('{asunto}', $asuntoBody, (string)$tpl['asunto']);
    $cuerpo     = str_replace('{cuerpo}', $cuerpoBody, (string)$tpl['cuerpo']);

    if (!empty($in['variables'])) {
        $vars = is_array($in['variables']) ? $in['variables'] : json_decode((string)$in['variables'], true);
        if (is_array($vars)) {
            foreach ($vars as $clave => $valor) {
                $asunto = str_replace('{' . $clave . '}', (string)$valor, $asunto);
                $cuerpo = str_replace('{' . $clave . '}', (string)$valor, $cuerpo);
            }
        }
    }

    // Mapeo de formato legacy (H/T/M) al vocabulario del sender (html/texto/null).
    $formatoMap = ['H' => 'html', 'T' => 'texto', 'M' => null];
    $formatoTpl = strtoupper(trim((string)$tpl['formato']));
    $formato    = $formatoMap[$formatoTpl] ?? null;

    $in['remitente'] = $tpl['remitente'];
    $in['remite']    = $tpl['remite'];
    $in['asunto']    = $asunto;
    $in['cuerpo']    = $cuerpo;
    $in['formato']   = $formato;
    $in['adjunto']   = $tpl['adjunto'];
    return $in;
}

/**
 * Resuelve el `contacto_id` de `aws_mensajes` a partir del `destino` del
 * mensaje: si el correo ya existe en `datarocket_contactos.correo` devuelve
 * su id; si no, lo da de alta primero y devuelve el id recien insertado.
 *
 * Reglas:
 *   - Si `destino` esta vacio devuelve null (mensajes sin destino quedan sin
 *     contacto asociado; la validacion de obligatorios ya los rechaza antes).
 *   - Si `destino` trae varios correos separados por coma (envio masivo a un
 *     grupo), toma el primero — un mensaje solo puede apuntar a un contacto.
 *   - El lookup es case-insensitive porque `datarocket_contactos.correo` usa
 *     `utf8mb4_general_ci`.
 *   - El alta automatica ya NO deja marca de procedencia: la columna `origen`
 *     se dropeo en la migracion 20260817_2000. Para saber que un contacto
 *     nacio de un envio hay que mirar `datarocket_interacciones`.
 *   - El alta automatica crea el contacto como `tipo='persona'` y completa
 *     `persona_nombre` (cayendo al correo si el envio no trajo destinatario),
 *     para cumplir la invariante de identidad documentada en db/schema.sql.
 */
function resolverContactoIdAws(PDO $pdo, ?string $destino, ?string $destinatario): ?int {
    if ($destino === null) return null;
    $emails = array_values(array_filter(array_map('trim', explode(',', $destino))));
    if (!$emails) return null;
    $correo = $emails[0];
    if ($correo === '') return null;

    $st = $pdo->prepare("SELECT id FROM datarocket_contactos WHERE correo = :c LIMIT 1");
    $st->execute([':c' => $correo]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;

    $ahora = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
             ->format('Y-m-d H:i:s');
    // Invariante de identidad (ver db/schema.sql): un contacto creado al vuelo
    // igual necesita `tipo` y el nombre del lado que corresponda, porque es el
    // que alimenta a `nombre`. Un alta automatica desde un envio es siempre un
    // individuo, asi que va como 'persona'.
    //
    // Si el envio no trajo `destinatario` se cae al correo: es el unico dato
    // identificatorio disponible, y dejar el nombre vacio reintroduce
    // exactamente las filas sin nombre que limpio la migracion 20260817_2100.
    // Mostrar "juan@example.com" como nombre de un contacto que todavia nadie
    // completo es preferible a un contacto anonimo que no se puede ni listar.
    $nombre = trim((string)($destinatario ?? ''));
    if ($nombre === '') $nombre = $correo;

    $ins = $pdo->prepare("
        INSERT INTO datarocket_contactos (uuid, tipo, nombre, persona_nombre, correo, registrado)
        VALUES (:uuid, 'persona', :nombre, :persona_nombre, :correo, :registrado)
    ");
    $ins->execute([
        ':uuid'           => bin2hex(random_bytes(16)),
        ':nombre'         => $nombre,
        ':persona_nombre' => $nombre,
        ':correo'         => $correo,
        ':registrado'     => $ahora,
    ]);
    return (int)$pdo->lastInsertId();
}

// Resuelve `proyecto_slug` / `canal_slug` / `plantilla_slug` a sus ids
// correspondientes en `proyectos.slug`, `aws_canales.slug` y
// `datarocket_plantillas.slug`. Cuando viene el slug se ignora el id numerico
// del mismo campo (el slug es la fuente de verdad). Slug no encontrado ->
// InvalidArgumentException (400 en la capa HTTP).
//
// Espejo estructural de resolverEvoMsgSlugs() en evolution_mensajes.php — si
// se toca la firma o la semantica, mantener ambas en linea.
function resolverAwsMsgSlugs(PDO $pdo, array $in): array {
    $resolver = [
        'proyecto_slug'  => ['proyecto_id',  'proyectos',              'Proyecto'],
        'canal_slug'     => ['canal_id',     'aws_canales',            'Canal'],
        'plantilla_slug' => ['plantilla_id', 'datarocket_plantillas',  'Plantilla'],
    ];
    foreach ($resolver as $slugKey => [$idKey, $tabla, $label]) {
        if (!array_key_exists($slugKey, $in)) continue;
        $slug = trim((string)$in[$slugKey]);
        if ($slug === '') continue;
        $st = $pdo->prepare("SELECT id FROM {$tabla} WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $slug]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("{$label} con slug '{$slug}' no encontrado");
        }
        $in[$idKey] = (int)$id;
    }
    return $in;
}

function applyAwsMsgSanitizer(string $rule, mixed $val): mixed {
    if ($rule === 'int') return awsMsgNullableInt($val);
    if ($rule === 'dt')  return awsMsgNullableDateTime($val);
    if ($rule === 'str') return awsMsgNullableStr($val);
    if (str_starts_with($rule, 'str:')) {
        return awsMsgNullableStr($val, (int)substr($rule, 4));
    }
    throw new RuntimeException("Sanitizer desconocido: {$rule}");
}

function awsMsgNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function awsMsgNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function awsMsgNullableDateTime(mixed $v): ?string {
    $s = awsMsgNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

// ----------------------------------------------------------------------------
// Bandera del motor (`parametros.aws.mensajes.enviar`)
// ----------------------------------------------------------------------------
// Semantica del valor:
//   '0' = DETENIDO  — gate manual. Ni el cron ni ningun encolamiento nuevo lo
//                     mueven; solo iniciarMotorAws() lo saca de este estado.
//   '1' = ESPERANDO — cola vacia; el cron proxima corrida se saltea la seleccion.
//   '2' = ENVIANDO  — hay pendientes en la cola; el cron debe procesar.
//
// Transiciones automaticas:
//   * encolarAwsMensaje() con estado='pendiente' -> marcarColaAwsConPendientes()
//     sube a '2' SOLO si no esta en '0' (no queremos que un mensaje nuevo
//     re-arranque el motor que el operador paro a mano).
//   * cron worker al terminar la corrida:
//        cola vacia    -> '1' (via marcarCola AwsVacia)
//        aun pendientes -> deja en '2' (o lo sube, si no era '0')
//   * detenerMotorAws() / iniciarMotorAws() son las unicas dos acciones que
//     mueven el flag entre '0' y (>0).

/**
 * Sube el flag a '2' (ENVIANDO) para avisarle al cron que hay pendientes.
 * NO mueve el flag si esta en '0' (motor detenido manualmente) — encolar un
 * mensaje nuevo no debe reactivar el motor que el operador paro. Idempotente
 * si ya vale '2'.
 */
function marcarColaAwsConPendientes(PDO $pdo): void {
    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '2'
          WHERE variable = 'aws.mensajes.enviar' AND valor <> '0'"
    );
    $st->execute();
}

/**
 * Baja el flag a '1' (ESPERANDO) — el cron lo usa como self-heal cuando la
 * cola queda vacia. Respeta el gate manual: no toca si esta en '0'.
 */
function marcarColaAwsVacia(PDO $pdo): void {
    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '1'
          WHERE variable = 'aws.mensajes.enviar' AND valor <> '0'"
    );
    $st->execute();
}

/**
 * Setea el flag en '0' (DETENIDO). Accion manual desde el UI — bloquea todo
 * envio hasta que se llame iniciarMotorAws().
 */
function detenerMotorAws(PDO $pdo): void {
    $st = $pdo->prepare("UPDATE parametros SET valor = '0' WHERE variable = 'aws.mensajes.enviar'");
    $st->execute();
}

/**
 * Setea el flag en '2' (ENVIANDO). Accion manual desde el UI — saca al motor
 * del estado detenido y le dice al cron que procese en la proxima corrida.
 * Si la cola resulta vacia, el propio cron lo baja a '1' en su self-heal.
 */
function iniciarMotorAws(PDO $pdo): void {
    $st = $pdo->prepare("UPDATE parametros SET valor = '2' WHERE variable = 'aws.mensajes.enviar'");
    $st->execute();
}
