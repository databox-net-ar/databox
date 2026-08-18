<?php
// api/lib/telegram_mensajes.php
// Punto UNICO de entrada para insertar en `telegram_mensajes`. Cualquier
// caller (endpoint HTTP del ABM cloud, futuros microservicios v4, jobs
// manuales) debe pasar por encolarTelegramMensaje() para garantizar las
// mismas reglas de ingreso -- sanitizacion, obligatorios, defaults y
// wake-on-demand del cron sender
// (cloud/jobs/telegram_mensajes_enviar.php).
//
// Mirror estructural de cloud/api/lib/evolution_mensajes.php. Diferencias:
//   * `canal_id` referencia `telegram_canales` (cuentas MTProto / usuario)
//     -- no `telegram_bots` (Bot API), que era el diseño anterior. El
//     despacho concreto lo hace el worker via POST al endpoint MTProto
//     /v4/telegram/mensajes.
//   * NO hay envio sincrono aca -- solo alta a la cola con estado='pendiente'.
//
// Contrato:
//   int encolarTelegramMensaje(PDO $pdo, array $datos)
//     -> devuelve el id insertado (estado 'pendiente')
//     -> tira InvalidArgumentException si faltan obligatorios (la capa HTTP
//        lo traduce a 400 via jsonError)
//     -> como efecto lateral llama marcarColaTelegramConPendientes() asi el
//        cron proxima corrida encuentra el flag activo y despacha (self-heal
//        del propio cron lo baja a '1' cuando la cola queda vacia)

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/datarocket_interacciones.php';

// Mapa columna -> regla del sanitizador. Reusado para INSERT completo.
const TG_MSG_SANITIZERS = [
    'uuid'         => 'str:255',
    'fecha'        => 'dt',
    'proyecto_id'  => 'int',
    'canal_id'     => 'int',
    'plantilla_id' => 'int',
    'prospecto_id'  => 'int',
    'remitente'    => 'str:255',
    'remite'       => 'str:255',
    'destinatario' => 'str:255',
    'destino'      => 'str:255',
    'prioridad'    => 'int',
    'asunto'       => 'str:255',
    'cuerpo'       => 'str',
    'formato'      => 'str:20',
    'adjunto'      => 'str:500',
    'tags'         => 'str:255',
    'estado'       => 'str:20',
    'error'        => 'str:1000',
    'encolado'     => 'dt',
    'programado'   => 'dt',
    'enviado'      => 'dt',
    'demora'       => 'int',
];

// Campos obligatorios al encolar. Sin canal no hay cuenta remitente; sin
// destino no hay a quien mandar; sin cuerpo no hay texto que enviar.
// `proyecto_id` es obligatorio para reportabilidad / filtrado.
const TG_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Canal',
    'destino'     => 'Destino',
    'cuerpo'      => 'Cuerpo',
];

// ----------------------------------------------------------------------------
// API publica
// ----------------------------------------------------------------------------

/**
 * Punto UNICO de entrada para insertar en `telegram_mensajes` como pendiente.
 * Cualquier caller debe pasar por aca -- evita que dos consumidores distintos
 * apliquen reglas de ingreso divergentes.
 */
function encolarTelegramMensaje(PDO $pdo, array $datos): int {
    $p = sanitizeTgMsgPayload($pdo, $datos);

    // Validar obligatorios antes de tocar la BD.
    $faltantes = [];
    foreach (TG_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
    }
    if ($faltantes) {
        throw new InvalidArgumentException(
            'Faltan campos obligatorios: ' . implode(', ', $faltantes)
        );
    }

    // Validar que el canal exista, este habilitado y tenga telefono cargado.
    // Sin telefono el worker no puede ubicar la sesion MTProto en disco.
    $canal = tgMsgCargarCanal($pdo, (int)$p['canal_id']);
    if ($canal === null) {
        throw new InvalidArgumentException("Canal #{$p['canal_id']} no existe");
    }
    if (((string)($canal['habilitado'] ?? '')) !== '1') {
        throw new InvalidArgumentException("Canal '{$canal['slug']}' esta deshabilitado");
    }
    if (empty($canal['telefono'])) {
        throw new InvalidArgumentException("Canal '{$canal['slug']}' no tiene telefono cargado");
    }

    // Defaults system-managed:
    //   - fecha      : NOW() en zona AR
    //   - encolado   : mismo instante que fecha
    //   - programado : mismo instante que fecha (envio inmediato)
    //   - estado     : 'pendiente' (el worker lo pasa a 'enviando'/'enviado'/'error')
    //   - formato    : 'texto'
    //   - prioridad  : 3 = media (rango 1..5, mantenido por consistencia
    //                  con evolution/aws aunque el worker MTProto no la use)
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    if ($p['encolado']   === null) $p['encolado']   = $p['fecha'];
    if ($p['programado'] === null) $p['programado'] = $p['fecha'];
    if ($p['estado']     === null) $p['estado']     = 'pendiente';
    if ($p['formato']    === null) $p['formato']    = 'texto';
    if ($p['prioridad']  === null) $p['prioridad']  = 3;

    // uuid: identificador propio del mensaje (36 chars con guiones).
    // Mirror del pattern de evolution_mensajes / aws_mensajes.
    if ($p['uuid'] === null) {
        $p['uuid'] = (string)$pdo->query('SELECT UUID()')->fetchColumn();
    }

    $sql = "
        INSERT INTO telegram_mensajes
            (uuid, fecha, proyecto_id, canal_id, plantilla_id, prospecto_id, remitente,
             remite, destinatario, destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:uuid, :fecha, :proyecto_id, :canal_id, :plantilla_id, :prospecto_id, :remitente,
             :remite, :destinatario, :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uuid'         => $p['uuid'],
        ':fecha'        => $p['fecha'],
        ':proyecto_id'  => $p['proyecto_id'],
        ':canal_id'     => $p['canal_id'],
        ':plantilla_id' => $p['plantilla_id'],
        ':prospecto_id'  => $p['prospecto_id'],
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

    // Registrar interaccion en el historial del prospecto (best-effort).
    if (!empty($p['prospecto_id'])) {
        registrarInteraccionMensaje(
            $pdo,
            (int)$p['prospecto_id'],
            'saliente',
            'telegram',
            // Telegram tampoco tiene asunto propio — ver evolution_mensajes.php.
            $p['asunto'] ?? null,
            $p['cuerpo'] ?? $p['destino'],
            $p['fecha']
        );
    }

    // Wake-on-demand: si el mensaje quedo pendiente, avisar al cron worker.
    // Idempotente y respetuoso de la pausa manual: no pisa '0' con '2'.
    if ($p['estado'] === 'pendiente') marcarColaTelegramConPendientes($pdo);

    return $id;
}

// ----------------------------------------------------------------------------
// Motor: helpers de flag `parametros.telegram.mensajes.enviar` (tri-estado)
// ----------------------------------------------------------------------------
// Semantica del flag (mismo patron que evolution/aws):
//   '0' = DETENIDO   (pausa manual del ABM > menu motor; worker se saltea)
//   '1' = ESPERANDO  (cola vacia; worker se saltea, ahorra el SELECT)
//   '2' = ENVIANDO   (hay pendientes; worker procesa hasta MAX_POR_CORRIDA)

/**
 * Levanta la bandera runtime a '2' si estaba en '1' (ESPERANDO). NO pisa
 * '0' (DETENIDO / pausa manual) -- el operador la subio a mano y el
 * encolador respeta esa pausa (el mensaje queda pendiente, el motor no
 * lo procesa hasta que hagan "Iniciar motor").
 */
function marcarColaTelegramConPendientes(PDO $pdo): void {
    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '2'
          WHERE variable = 'telegram.mensajes.enviar' AND valor <> '0'"
    );
    $st->execute();
}

/**
 * Pausa manual del motor: escribe '0' en la bandera. El worker se saltea
 * todas las corridas hasta que alguien haga iniciarMotorTelegram(). El
 * encolador respeta el '0' (los mensajes nuevos quedan pendientes pero no
 * despiertan al motor).
 *
 * Llamado desde cloud/api/telegrammensajes_motor.php, gateado por el
 * permiso propio `plataformas.telegram.mensajes.motor`.
 */
function detenerMotorTelegram(PDO $pdo): void {
    // Sembrar el parametro si no existe (fallback si la migracion nunca
    // corrio). Mirror de la logica de evolution.
    $st = $pdo->prepare(
        "INSERT INTO parametros (variable, valor, comentario)
         SELECT 'telegram.mensajes.enviar', '0',
                'Motor de envio de mensajes Telegram (tri-estado). 0 = detenido; 1 = esperando; 2 = enviando.'
         WHERE NOT EXISTS (SELECT 1 FROM parametros WHERE variable = 'telegram.mensajes.enviar')"
    );
    $st->execute();

    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '0' WHERE variable = 'telegram.mensajes.enviar'"
    );
    $st->execute();
}

/**
 * Inicia el motor: escribe '2' (ENVIANDO). El worker en la proxima corrida
 * procesa lo pendiente; si drena, self-heal baja a '1' (ESPERANDO).
 */
function iniciarMotorTelegram(PDO $pdo): void {
    $st = $pdo->prepare(
        "INSERT INTO parametros (variable, valor, comentario)
         SELECT 'telegram.mensajes.enviar', '2',
                'Motor de envio de mensajes Telegram (tri-estado). 0 = detenido; 1 = esperando; 2 = enviando.'
         WHERE NOT EXISTS (SELECT 1 FROM parametros WHERE variable = 'telegram.mensajes.enviar')"
    );
    $st->execute();

    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '2' WHERE variable = 'telegram.mensajes.enviar'"
    );
    $st->execute();
}

// ----------------------------------------------------------------------------
// Sanitizacion
// ----------------------------------------------------------------------------

function sanitizeTgMsgPayload(PDO $pdo, array $in): array {
    // Aliases legacy: aceptar `proyecto`/`canal`/`plantilla` (sin sufijo _id)
    // ademas de la forma canonica.
    if (!array_key_exists('proyecto_id',  $in) && array_key_exists('proyecto',  $in)) $in['proyecto_id']  = $in['proyecto'];
    if (!array_key_exists('canal_id',     $in) && array_key_exists('canal',     $in)) $in['canal_id']     = $in['canal'];
    if (!array_key_exists('plantilla_id', $in) && array_key_exists('plantilla', $in)) $in['plantilla_id'] = $in['plantilla'];

    // Resolucion de slugs -> ids (proyecto_slug, canal_slug, plantilla_slug).
    $in = resolverTgMsgSlugs($pdo, $in);

    // Aplicacion de plantilla: si viene plantilla_id, sobrescribe
    // cuerpo/asunto/formato/adjunto/remite/remitente con los valores de la
    // plantilla, y sustituye placeholders `{clave}` con $in['variables'].
    $in = aplicarPlantillaTgMsg($pdo, $in);

    $out = [];
    foreach (TG_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applyTgMsgSanitizer($rule, $in[$col] ?? null);
    }
    return $out;
}

// Si viene `plantilla_id`, carga la fila de `datarocket_plantillas` y
// sobrescribe con ella los campos `cuerpo`, `asunto`, `formato`, `adjunto`,
// `remite` y `remitente` del payload. Despues aplica sustitucion de
// variables `{clave}` sobre `cuerpo` y `asunto` usando el dict
// `$in['variables']` (opcional). Mirror del comportamiento de evolution.
const TG_MSG_PLANTILLA_COLS = ['cuerpo', 'asunto', 'formato', 'adjunto', 'remite', 'remitente'];

function aplicarPlantillaTgMsg(PDO $pdo, array $in): array {
    if (empty($in['plantilla_id'])) {
        unset($in['variables']);
        return $in;
    }
    $st = $pdo->prepare(
        "SELECT " . implode(', ', TG_MSG_PLANTILLA_COLS) . "
           FROM datarocket_plantillas WHERE id = :id LIMIT 1"
    );
    $st->execute([':id' => (int)$in['plantilla_id']]);
    $pl = $st->fetch();
    if (!$pl) {
        unset($in['variables']);
        return $in;
    }

    foreach (TG_MSG_PLANTILLA_COLS as $col) {
        if (isset($pl[$col]) && (string)$pl[$col] !== '') {
            $in[$col] = $pl[$col];
        }
    }

    $vars = $in['variables'] ?? null;
    if (is_array($vars)) {
        foreach (['cuerpo', 'asunto'] as $col) {
            if (empty($in[$col])) continue;
            foreach ($vars as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $in[$col] = str_replace('{' . $k . '}', (string)$v, (string)$in[$col]);
            }
        }
    }
    unset($in['variables']);
    return $in;
}

// Resuelve `proyecto_slug` / `canal_slug` / `plantilla_slug` a sus ids.
// Cuando viene el slug se ignora el id numerico del mismo campo (el slug es la
// fuente de verdad). Slug no encontrado -> InvalidArgumentException.
function resolverTgMsgSlugs(PDO $pdo, array $in): array {
    $resolver = [
        'proyecto_slug'  => ['proyecto_id',  'proyectos',              'Proyecto'],
        'canal_slug'     => ['canal_id',     'telegram_canales',       'Canal'],
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

// Carga la fila completa del canal (para validar habilitado / telefono).
// Devuelve null si el canal no existe.
function tgMsgCargarCanal(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, slug, nombre, telefono, habilitado
                           FROM telegram_canales WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    return $r ?: null;
}

function applyTgMsgSanitizer(string $rule, mixed $val): mixed {
    if ($rule === 'int') return tgMsgNullableInt($val);
    if ($rule === 'dt')  return tgMsgNullableDateTime($val);
    if ($rule === 'str') return tgMsgNullableStr($val);
    if (str_starts_with($rule, 'str:')) {
        return tgMsgNullableStr($val, (int)substr($rule, 4));
    }
    throw new RuntimeException("Sanitizer desconocido: {$rule}");
}

function tgMsgNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function tgMsgNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function tgMsgNullableDateTime(mixed $v): ?string {
    $s = tgMsgNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}
