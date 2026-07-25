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

// Mapa columna -> regla del sanitizador. Reusado por sanitizeAwsMsgPayload
// (INSERT completo) y sanitizeAwsMsgPartialPayload (UPDATE parcial).
const AWS_MSG_SANITIZERS = [
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
    'formato'      => 'str:10',
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
// el sender: sin proyecto/canal no puede firmar contra SES, sin
// remite/destino/asunto/cuerpo no puede armar el email.
const AWS_MSG_REQUERIDOS_CREATE = [
    'proyecto_id' => 'Proyecto',
    'canal_id'    => 'Canal',
    'remite'      => 'Remite',
    'destino'     => 'Destino',
    'asunto'      => 'Asunto',
    'cuerpo'      => 'Cuerpo',
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
    $p = sanitizeAwsMsgPayload($datos);

    // Validar obligatorios antes de tocar la BD.
    $faltantes = [];
    foreach (AWS_MSG_REQUERIDOS_CREATE as $col => $label) {
        if ($p[$col] === null) $faltantes[] = $label;
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

    $sql = "
        INSERT INTO aws_mensajes
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
    if ($p['estado'] === 'pendiente') marcarColaAwsConPendientes($pdo);

    return $id;
}

// ----------------------------------------------------------------------------
// Sanitizacion (compartida con handleUpdate del endpoint)
// ----------------------------------------------------------------------------

// Sanitiza TODAS las columnas — los faltantes quedan NULL. Usado por
// encolarAwsMensaje al crear. No hay variante partial porque los mensajes
// encolados no se editan (ver comentario en cloud/api/awsmensajes.php).
function sanitizeAwsMsgPayload(array $in): array {
    $out = [];
    foreach (AWS_MSG_SANITIZERS as $col => $rule) {
        $out[$col] = applyAwsMsgSanitizer($rule, $in[$col] ?? null);
    }
    return $out;
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
