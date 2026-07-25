<?php
// api/lib/evolution_mensajes.php
// Punto UNICO de entrada para insertar en `evolution_mensajes`. Cualquier caller
// (endpoint HTTP del ABM cloud, microservicio v4 de ingesta, futuros scripts CLI
// o jobs manuales) debe pasar por encolarEvolutionMensaje() para garantizar las
// mismas reglas de ingreso — sanitizacion, obligatorios, defaults y wake-on-demand
// del cron sender (cloud/jobs/datarocket_mensajes_enviar.php).
//
// Espejo estructural de cloud/api/lib/aws_mensajes.php.
//
// Contrato:
//   int encolarEvolutionMensaje(PDO $pdo, array $datos)
//     -> devuelve el id insertado
//     -> tira InvalidArgumentException si faltan obligatorios (la capa HTTP
//        lo traduce a 400 via jsonError)
//     -> como efecto lateral llama marcarColaEvolutionConPendientes() asi el
//        cron proxima corrida encuentra el flag activo y despacha (self-heal
//        del propio cron lo baja a '0' cuando la cola queda vacia)

require_once __DIR__ . '/../db.php';

// Mapa columna -> regla del sanitizador. Reusado por sanitizeEvoMsgPayload
// (INSERT completo) y sanitizeEvoMsgPartialPayload (UPDATE parcial).
const EVO_MSG_SANITIZERS = [
    'fecha'        => 'dt',
    'proyecto_id'  => 'int',
    'canal_id'     => 'int',
    'plantilla_id' => 'int',
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

// Campos obligatorios al encolar un mensaje nuevo. Coincide con lo que exige
// el sender: sin proyecto/canal no puede rutear, sin remite/destino/cuerpo no
// puede armar el request contra Evolution API. Nota: `asunto` es opcional
// (WhatsApp no tiene subject; el sender lo antepone en negrita al cuerpo si
// esta presente).
const EVO_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Canal',
    'remite'      => 'Remite',
    'destino'     => 'Destino',
    'cuerpo'      => 'Cuerpo',
];

// ----------------------------------------------------------------------------
// API publica
// ----------------------------------------------------------------------------

/**
 * Punto UNICO de entrada para insertar en `evolution_mensajes`.
 * Cualquier caller debe pasar por aca — evita que dos consumidores distintos
 * apliquen reglas de ingreso divergentes.
 */
function encolarEvolutionMensaje(PDO $pdo, array $datos): int {
    $p = sanitizeEvoMsgPayload($datos);

    // Validar obligatorios antes de tocar la BD.
    $faltantes = [];
    foreach (EVO_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
    }
    if ($faltantes) {
        throw new InvalidArgumentException(
            'Faltan campos obligatorios: ' . implode(', ', $faltantes)
        );
    }

    // Defaults system-managed. Los sanitizadores devuelven null si el caller
    // no envio el campo; aca decidimos que ponemos por defecto:
    //   - fecha      : NOW() en zona AR (cuando se creo el mensaje en la cola)
    //   - encolado   : mismo instante que fecha (evita drift entre ambos)
    //   - programado : mismo instante que fecha (enviar apenas el worker lo tome;
    //                  el caller puede pasar una fecha futura para diferir)
    //   - estado     : 'pendiente' (el sender lo pasa a 'enviando'/'enviado'/'error')
    //   - formato    : 'texto' (por default WhatsApp de texto plano)
    //   - prioridad  : 3 = media (rango 1..5, 5 = envia primero)
    if ($p['fecha'] === null) {
        $p['fecha'] = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                      ->format('Y-m-d H:i:s');
    }
    if ($p['encolado']   === null) $p['encolado']   = $p['fecha'];
    if ($p['programado'] === null) $p['programado'] = $p['fecha'];
    if ($p['estado']     === null) $p['estado']     = 'pendiente';
    if ($p['formato']    === null) $p['formato']    = 'texto';
    if ($p['prioridad']  === null) $p['prioridad']  = 3;

    $sql = "
        INSERT INTO evolution_mensajes
            (fecha, proyecto_id, canal_id, plantilla_id, remitente, remite,
             destinatario, destino, prioridad, asunto, cuerpo, formato,
             adjunto, tags, estado, error, encolado, programado, enviado, demora)
        VALUES
            (:fecha, :proyecto_id, :canal_id, :plantilla_id, :remitente, :remite,
             :destinatario, :destino, :prioridad, :asunto, :cuerpo, :formato,
             :adjunto, :tags, :estado, :error, :encolado, :programado, :enviado, :demora)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fecha'        => $p['fecha'],
        ':proyecto_id'  => $p['proyecto_id'],
        ':canal_id'     => $p['canal_id'],
        ':plantilla_id' => $p['plantilla_id'],
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

    // Wake-on-demand: si el mensaje quedo pendiente (99% de los casos al
    // encolar), avisar al cron worker. Idempotente — si ya vale '1' el UPDATE
    // no cambia nada.
    if ($p['estado'] === 'pendiente') marcarColaEvolutionConPendientes($pdo);

    return $id;
}

// ----------------------------------------------------------------------------
// Sanitizacion (compartida con handleUpdate del endpoint)
// ----------------------------------------------------------------------------

// Sanitiza TODAS las columnas — los faltantes quedan NULL. Usado por
// encolarEvolutionMensaje al crear.
function sanitizeEvoMsgPayload(array $in): array {
    // Aliases legacy: aceptar `proyecto`/`canal`/`plantilla` (sin sufijo _id)
    // ademas de la forma canonica. Asi bookmarks y clientes viejos del v4
    // siguen andando sin romper la unificacion.
    if (!array_key_exists('proyecto_id',  $in) && array_key_exists('proyecto',  $in)) $in['proyecto_id']  = $in['proyecto'];
    if (!array_key_exists('canal_id',     $in) && array_key_exists('canal',     $in)) $in['canal_id']     = $in['canal'];
    if (!array_key_exists('plantilla_id', $in) && array_key_exists('plantilla', $in)) $in['plantilla_id'] = $in['plantilla'];

    $out = [];
    foreach (EVO_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applyEvoMsgSanitizer($rule, $in[$col] ?? null);
    }
    return $out;
}

// Sanitiza SOLO las columnas presentes en el payload. Usado por handleUpdate
// para no pisar a NULL las columnas system-managed que setea el sender.
function sanitizeEvoMsgPartialPayload(array $in): array {
    // Mismos aliases legacy que en sanitizeEvoMsgPayload — mirror para consistencia.
    if (!array_key_exists('proyecto_id',  $in) && array_key_exists('proyecto',  $in)) $in['proyecto_id']  = $in['proyecto'];
    if (!array_key_exists('canal_id',     $in) && array_key_exists('canal',     $in)) $in['canal_id']     = $in['canal'];
    if (!array_key_exists('plantilla_id', $in) && array_key_exists('plantilla', $in)) $in['plantilla_id'] = $in['plantilla'];

    $out = [];
    foreach (EVO_MSG_SANITIZERS as $col => $rule) {
        if (array_key_exists($col, $in)) {
            $out[$col] = applyEvoMsgSanitizer($rule, $in[$col]);
        }
    }
    return $out;
}

// Chequea los 5 campos obligatorios (proyecto_id, canal_id, remite, destino,
// cuerpo). Se aplica tanto en Alta como en Edicion desde el endpoint HTTP —
// no queremos que una edicion pueda dejar un mensaje sin FK, sin remitente/
// destino o sin contenido; se cortaria el sender worker.
//
// Tira InvalidArgumentException (400 en la capa HTTP).
function validarEvoMsgObligatorios(array $p): void {
    $faltantes = [];
    foreach (EVO_MSG_REQUERIDOS_CREATE as $col => $label) {
        if (!array_key_exists($col, $p) || $p[$col] === null) $faltantes[] = $label;
    }
    if ($faltantes) {
        throw new InvalidArgumentException(
            'Faltan campos obligatorios: ' . implode(', ', $faltantes) . '.'
        );
    }
}

function applyEvoMsgSanitizer(string $rule, mixed $val): mixed {
    if ($rule === 'int') return evoMsgNullableInt($val);
    if ($rule === 'dt')  return evoMsgNullableDateTime($val);
    if ($rule === 'str') return evoMsgNullableStr($val);
    if (str_starts_with($rule, 'str:')) {
        return evoMsgNullableStr($val, (int)substr($rule, 4));
    }
    throw new RuntimeException("Sanitizer desconocido: {$rule}");
}

function evoMsgNullableStr(mixed $v, ?int $max = null): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max !== null) $s = substr($s, 0, $max);
    return $s;
}

function evoMsgNullableInt(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function evoMsgNullableDateTime(mixed $v): ?string {
    $s = evoMsgNullableStr($v);
    if ($s === null) return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return null;
    return $s;
}

// ----------------------------------------------------------------------------
// Bandera del motor (`parametros.evolution.mensajes.enviar`)
// ----------------------------------------------------------------------------

/**
 * Sube `parametros.evolution.mensajes.enviar` a '2' (ENVIANDO) para
 * avisarle al cron worker `cloud/jobs/evolution_mensajes_enviar.php` que
 * hay pendientes. El job lo baja a '1' (ESPERANDO) cuando la cola queda
 * vacia (self-healing).
 *
 * IMPORTANTE: preserva '0' (DETENIDO, pausa manual desde el UI). Si un
 * operador paro el motor a mano, encolar mensajes NO debe reactivarlo —
 * salen de esa pausa solo con "Iniciar motor". Por eso el UPDATE lleva
 * `AND valor <> '0'`.
 *
 * Simple UPDATE sin INSERT-if-missing: la fila esta sembrada por la
 * migracion 20260725_1600_parametros_evolution_mensajes_enviar.sql (y su
 * refresh en 20260725_1700_..._3estados.sql). Si por alguna razon no
 * existe, la tarjeta "Motor" del listado va a mostrar "—" y el cron
 * seguira corriendo — ningun caso catastrofico, solo se pierde el
 * wake-on-demand.
 */
function marcarColaEvolutionConPendientes(PDO $pdo): void {
    $st = $pdo->prepare(
        "UPDATE parametros SET valor = '2'
          WHERE variable = 'evolution.mensajes.enviar' AND valor <> '0'"
    );
    $st->execute();
}

/**
 * Pausa manual del motor: escribe '0' en la bandera. El worker se saltea
 * todas las corridas hasta que alguien haga iniciarMotorEvolution().
 * encolarEvolutionMensaje() respeta el '0' (los mensajes nuevos quedan
 * pendientes en DB pero no despiertan al motor).
 *
 * Llamado desde cloud/api/evolutionmensajes_motor.php (menu hamburguesa
 * del listado > "Detener motor"), gateado por el permiso propio
 * `plataformas.evolution.mensajes.motor`.
 */
function detenerMotorEvolution(PDO $pdo): void {
    $st = $pdo->prepare("UPDATE parametros SET valor = '0' WHERE variable = 'evolution.mensajes.enviar'");
    $st->execute();
}

/**
 * Reanuda el motor: escribe '2'. El proximo tick del cron procesara los
 * pendientes que se hayan acumulado durante la pausa. Si en realidad no
 * hay pendientes, el self-heal del worker lo bajara a '1' (ESPERANDO)
 * al terminar la primera corrida.
 */
function iniciarMotorEvolution(PDO $pdo): void {
    $st = $pdo->prepare("UPDATE parametros SET valor = '2' WHERE variable = 'evolution.mensajes.enviar'");
    $st->execute();
}
