<?php
// api/lib/datarocket_interacciones.php
// Helper para dar de alta filas en `datarocket_interacciones` desde los
// canalizadores encolarAwsMensaje() (cloud/api/lib/aws_mensajes.php) y
// encolarEvolutionMensaje() (cloud/api/lib/evolution_mensajes.php).
//
// El ABM del panel (cloud/api/datarocketinteracciones.php) es de solo lectura:
// las interacciones se acumulan cada vez que se encola un mensaje CON opt-in
// explicito (ver debeRegistrarProspecto() al pie). La tabla las lee para
// reconstruir el historial de prospecto.
//
// Best-effort: si el INSERT falla no bloquea el encolamiento del mensaje —
// swallow silencioso, se devuelve null. El mensaje ya quedo en la cola, que
// es el efecto que el caller pidio; perder una linea de historial no debe
// tumbar el envio.
//
// Tabla destino: `datarocket_interacciones` (schema en db/schema.sql y
// migracion 20260727_1900_crear_datarocket_interacciones.sql).

require_once __DIR__ . '/../db.php';

// Valores del catalogo `estados`, campos `datarocket_interaccion_sentido` y
// `datarocket_interaccion_canal` (migracion 20260817_1000). Se declaran aca
// para que el helper no dependa de una query al catalogo en el camino caliente
// del encolado.
const DR_INT_SENTIDOS = ['entrante', 'saliente', 'interna'];
const DR_INT_CANALES  = ['correo', 'whatsapp', 'telegram', 'sms', 'web',
                         'telefono', 'presencial'];

/**
 * Registra un alta en `datarocket_interacciones`. Devuelve el id insertado o
 * null si se salteo (prospecto ausente) o hubo un error interno.
 *
 * @param ?int    $prospectoId  FK a `datarocket_prospectos.id`. Si es null/<=0
 *                             se saltea el INSERT (interaccion sin prospecto
 *                             no aporta nada al historial).
 * @param string  $sentido     Direccion: 'entrante' | 'saliente' | 'interna'.
 *                             Reemplaza al viejo `tipo`, junto con $canal
 *                             (migracion 20260817_1000).
 * @param ?string $canal       Medio: 'correo' | 'whatsapp' | 'telegram' |
 *                             'sms' | 'web' | 'telefono' | 'presencial'. NULL
 *                             cuando no hubo comunicacion (notas internas) o no
 *                             se sabe por donde entro.
 * @param ?string $asunto      Asunto del mensaje, para la etiqueta corta del
 *                             listado. Se trunca a 500 chars. NULL en los
 *                             canales que no tienen asunto (WhatsApp, Telegram).
 * @param ?string $mensaje     Cuerpo completo del mensaje. Sin truncar: la
 *                             columna es MEDIUMTEXT.
 * @param ?string $fecha       Datetime 'Y-m-d H:i:s'. Si es null se usa NOW()
 *                             en America/Argentina/Buenos_Aires.
 * @param ?int    $proyectoId  FK a `proyectos.id` (columna agregada por la
 *                             migracion 20260828_2300). Es el proyecto BAJO EL
 *                             QUE SE MANDO el mensaje, congelado: las campanas
 *                             lo exigen y se lo pasan a los tres encoladores en
 *                             el payload, y hasta esa migracion se perdia en el
 *                             camino — la columna Proyecto del ABM salia por
 *                             JOIN contra la oportunidad, y los envios de
 *                             campana no tienen oportunidad, asi que se veian
 *                             todos con guion.
 *
 *                             Va ultimo y con default para no romper a ningun
 *                             caller que llame con la firma vieja: perder el
 *                             proyecto es peor que antes, pero tumbar el
 *                             encolado seria mucho peor.
 */
function registrarInteraccionMensaje(
    PDO $pdo,
    ?int $prospectoId,
    string $sentido,
    ?string $canal,
    ?string $asunto,
    ?string $mensaje,
    ?string $fecha = null,
    ?int $proyectoId = null
): ?int {
    if ($prospectoId === null || $prospectoId <= 0) return null;

    // Un sentido fuera del catalogo seria un bug del caller, no un dato del
    // usuario. Se normaliza a 'saliente' en vez de tirar: este helper es
    // best-effort y no debe tumbar el encolado del mensaje.
    if (!in_array($sentido, DR_INT_SENTIDOS, true)) $sentido = 'saliente';
    if ($canal !== null && !in_array($canal, DR_INT_CANALES, true)) $canal = null;

    if ($fecha === null) {
        $fecha = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->format('Y-m-d H:i:s');
    }

    $asunto  = drIntNullableStr($asunto, 500);
    $mensaje = drIntNullableStr($mensaje, 0);

    // Un 0 o un negativo no son un proyecto: se normalizan a NULL antes de
    // llegar a la FK, que los rechazaria y — por el catch de abajo — haria
    // desaparecer la interaccion entera en silencio.
    if ($proyectoId !== null && $proyectoId <= 0) $proyectoId = null;

    try {
        $st = $pdo->prepare("
            INSERT INTO datarocket_interacciones
                (fecha, prospecto_id, proyecto_id, sentido, canal, asunto, mensaje)
            VALUES
                (:fecha, :prospecto_id, :proyecto_id, :sentido, :canal, :asunto, :mensaje)
        ");
        $st->execute([
            ':fecha'       => $fecha,
            ':prospecto_id' => $prospectoId,
            ':proyecto_id' => $proyectoId,
            ':sentido'     => $sentido,
            ':canal'       => $canal,
            ':asunto'      => $asunto,
            ':mensaje'     => $mensaje,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable) {
        return null;
    }
}

// ----------------------------------------------------------------------------
// Bandera de opt-in (`registrar_prospecto`)
// ----------------------------------------------------------------------------

// Formas de "verdadero" que aceptamos en el flag. Cubre el JSON de los
// microservicios v4 (bool), el form-encoded de un cliente HTTP viejo ("1") y
// el vocabulario de los flags legacy del stack ('S'/'si').
const DR_INT_FLAG_VERDADERO = ['1', 'true', 'si', 'sí', 's', 'on', 'yes', 'y'];

/**
 * Decide si un encolamiento debe tocar Datarocket — o sea, resolver/dar de alta
 * el prospecto y registrar la interaccion en su historial.
 *
 * Por DEFECTO NO. Encolar un mensaje es un acto de envio, no de CRM: la enorme
 * mayoria de los envios transaccionales (recuperos de clave, alertas, avisos de
 * sistema) no representan un prospecto comercial y ensuciaban
 * `datarocket_prospectos` con una ficha por cada destinatario. El caller que si
 * quiera alimentar el historial tiene que pedirlo explicitamente mandando
 * `registrar_prospecto` en el payload.
 *
 * Lo consumen encolarAwsMensaje() y encolarEvolutionMensaje(); ambos lo leen
 * del payload CRUDO, antes de sanitizar, porque `registrar_prospecto` no es una
 * columna de las tablas de mensajes y los sanitizadores descartan todo lo que
 * no lo sea.
 *
 * Cualquier valor fuera de la lista blanca (incluido el flag ausente) -> false.
 */
function debeRegistrarProspecto(array $datos): bool {
    if (!array_key_exists('registrar_prospecto', $datos)) return false;
    $v = $datos['registrar_prospecto'];
    if ($v === null)  return false;
    if (is_bool($v))  return $v;
    if (is_int($v))   return $v === 1;
    if (is_array($v)) return false;
    return in_array(mb_strtolower(trim((string)$v)), DR_INT_FLAG_VERDADERO, true);
}

// $max <= 0 significa "sin limite" — lo usa `mensaje`, que va a un MEDIUMTEXT.
function drIntNullableStr(mixed $v, int $max): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($max > 0 && mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}
