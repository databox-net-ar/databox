<?php
// api/lib/datarocket_interacciones.php
// Helper para dar de alta filas en `datarocket_interacciones` desde los
// canalizadores encolarAwsMensaje() (cloud/api/lib/aws_mensajes.php) y
// encolarEvolutionMensaje() (cloud/api/lib/evolution_mensajes.php).
//
// El ABM del panel (cloud/api/datarocketinteracciones.php) es de solo lectura:
// las interacciones se acumulan automaticamente cada vez que se encola un
// mensaje. La tabla las lee para reconstruir el historial de contacto.
//
// Best-effort: si el INSERT falla no bloquea el encolamiento del mensaje —
// swallow silencioso, se devuelve null. El mensaje ya quedo en la cola, que
// es el efecto que el caller pidio; perder una linea de historial no debe
// tumbar el envio.
//
// Tabla destino: `datarocket_interacciones` (schema en db/schema.sql y
// migracion 20260727_1900_crear_datarocket_interacciones.sql).

require_once __DIR__ . '/../db.php';

/**
 * Registra un alta en `datarocket_interacciones`. Devuelve el id insertado o
 * null si se salteo (contacto ausente) o hubo un error interno.
 *
 * @param ?int    $contactoId  FK a `datarocket_contactos.id`. Si es null/<=0
 *                             se saltea el INSERT (interaccion sin contacto
 *                             no aporta nada al historial).
 * @param string  $tipo        Categoria (ej. 'correo_enviado', 'whatsapp_enviado').
 * @param string  $origen      Tabla del mensaje: 'aws_mensajes' | 'evolution_mensajes'.
 * @param int     $mensajeId   ID de la fila en la tabla `origen`.
 * @param ?string $descripcion Texto libre para mostrar en el listado (asunto
 *                             del correo, preview del cuerpo del WhatsApp,
 *                             etc.). Se trunca a 500 chars.
 * @param ?string $fecha       Datetime 'Y-m-d H:i:s'. Si es null se usa NOW()
 *                             en America/Argentina/Buenos_Aires.
 */
function registrarInteraccionMensaje(
    PDO $pdo,
    ?int $contactoId,
    string $tipo,
    string $origen,
    int $mensajeId,
    ?string $descripcion,
    ?string $fecha = null
): ?int {
    if ($contactoId === null || $contactoId <= 0) return null;

    if ($fecha === null) {
        $fecha = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                 ->format('Y-m-d H:i:s');
    }

    $descripcion = drIntNullableStr($descripcion, 500);

    try {
        $st = $pdo->prepare("
            INSERT INTO datarocket_interacciones
                (fecha, contacto_id, tipo, origen, mensaje_id, descripcion)
            VALUES
                (:fecha, :contacto_id, :tipo, :origen, :mensaje_id, :descripcion)
        ");
        $st->execute([
            ':fecha'       => $fecha,
            ':contacto_id' => $contactoId,
            ':tipo'        => $tipo,
            ':origen'      => $origen,
            ':mensaje_id'  => $mensajeId,
            ':descripcion' => $descripcion,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable) {
        return null;
    }
}

function drIntNullableStr(mixed $v, int $max): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}
