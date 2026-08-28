<?php
// api/v4/_lib/aws_bounce.php
// Clasificacion de los bounces de SES: ¿este rebote es DEFINITIVO?
//
// ---------------------------------------------------------------------------
// POR QUE VIVE ACA Y NO ADENTRO DEL WEBHOOK
// ---------------------------------------------------------------------------
// Es la unica funcion que contesta esa pregunta en todo el sistema. Lo que
// devuelve queda congelado en `aws_eventos.permanente` al momento de ingestar
// el evento, y de ahi lo lee el motor de campanas para dar de baja de la lista
// (drcaBajasPorRebote en cloud/api/lib/datarocket_campanas_expandir.php).
//
// Adentro de `api/v4/aws/eventos.php` no se podia ni invocar desde afuera: ese
// archivo es un endpoint, se ejecuta al incluirlo. Una regla que decide bajas
// automaticas tiene que poder probarse.
//
// ---------------------------------------------------------------------------
// LA REGLA
// ---------------------------------------------------------------------------
// Dos senales, y la segunda puede contradecir a la primera:
//
//   1. `bounceType` de SES: 'Permanent' (la casilla no existe) o 'Transient'
//      (buzon lleno, servidor caido, dominio que no resuelve).
//   2. El enhanced status code que devolvio el SMTP del otro lado. Por RFC 3463
//      la primera cifra es la clase del fallo: 5 = permanente, 4 = transitorio.
//
// Cuando se contradicen gana el status code. El caso real que obligo a escribir
// esto: SES clasifica los fallos de resolucion de dominio como 'Transient' —
// por las dudas de que el DNS este caido un rato — pero un dominio mal tipeado
// ('@live.con' en vez de '@live.com') no va a resolver nunca. El servidor ya
// habia contestado `550 5.4.4 Invalid domain`: el 5 dice que es definitivo.
//
// Con el filtro viejo, que solo miraba `bounceType = 'Permanent'`, esa
// direccion rebotaba campana tras campana sin darse de baja jamas (incidente
// prod 2026-08-28, lista #139: dos campanas al mismo destino muerto).
//
// Lo que NO hace: graduar complaints. Cualquier denuncia de spam da de baja sin
// necesidad de mirar nada mas, asi que esa decision no pasa por aca.

/**
 * @param array $sesMsg El evento SES decodificado (el contenido de SNS.Message),
 *                      con la forma {eventType, mail:{...}, bounce:{...}}.
 * @return bool true si volver a escribirle a esa direccion no tiene sentido.
 */
function aws_evt_bounce_permanente(array $sesMsg): bool {
    $b = $sesMsg['bounce'] ?? [];

    if ((string)($b['bounceType'] ?? '') === 'Permanent') return true;

    // Si hubiera varios destinatarios alcanza con que UNO sea 5.x.x. En la
    // practica los mensajes de campana llevan un destinatario cada uno.
    foreach (($b['bouncedRecipients'] ?? []) as $r) {
        $status = trim((string)($r['status'] ?? ''));
        if ($status !== '' && $status[0] === '5') return true;
    }
    return false;
}
