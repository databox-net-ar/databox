<?php
/**
 * api/lib/datarocket_listas_baja_enlace.php
 * Token firmado y URL de la pagina publica de baja de lista.
 *
 * PARA QUE
 * --------
 * Todo correo masivo tiene que ofrecer una forma de dejar de recibirlo, y tiene
 * que funcionar sin login: quien lo abre es un destinatario, no un usuario del
 * panel. Este es el enlace que va al pie del mail y que resuelve
 * www/datarocket/suscripcion/index.php.
 *
 *   https://www.databox.net.ar/datarocket/suscripcion/?t=4211.87.mrb8k.<firma>
 *
 * Es el mismo esquema que api/lib/datarocket_interacciones_enlace.php — HMAC
 * con APP_KEY_CLOUD, vencimiento adentro del token, sin tabla de control — pero
 * con su propio proposito, para que un token de una familia no valga en la
 * otra. Se duplica la mecanica y no la implementacion: son 30 lineas de
 * firma/verificacion y factorizarlas obligaria a un helper generico
 * parametrizado por proposito y por cantidad de campos, que es mas dificil de
 * leer que las dos versiones.
 *
 * QUE LLEVA EL TOKEN
 * ------------------
 *   <prospecto_id>.<lista_id>.<vencimiento en base36>.<firma>
 *
 * Los dos ids, porque la baja es de UNA persona en UNA lista: el mismo
 * destinatario puede estar en varias y darse de baja de la que le llego no
 * puede sacarlo de las otras. No se usa el id del renglon del padron
 * (`datarocket_campanas_mensajes`) aunque seria un solo numero: ese renglon se
 * borra en cascada cuando se elimina la campana, y el enlace de un mail viejo
 * dejaria de funcionar. `prospecto_id` + `lista_id` sobreviven a la campana.
 *
 * VENCIMIENTO LARGO A PROPOSITO
 * -----------------------------
 * 365 dias contra los 30 de la ficha de interaccion. Un aviso de pendientes se
 * atiende hoy o no se atiende; un mail comercial se puede abrir meses despues,
 * y encontrarse con "este enlace caduco" en vez de poder darse de baja es
 * exactamente lo que empuja a la gente a apretar "esto es spam" — que ademas de
 * ser peor para la persona, nos hunde la reputacion del dominio.
 *
 * EL RIESGO DE ESTE TOKEN ES BAJO
 * -------------------------------
 * A diferencia de la ficha de interaccion, que expone datos personales de un
 * tercero, acá lo unico que se puede hacer con un token robado es dar de baja a
 * alguien de una lista. Es molesto, no es una fuga: la pagina no muestra
 * telefono ni domicilio, y la baja se revierte desde el ABM de listas. La firma
 * esta igual, para que nadie pueda dar de baja a media base iterando ids.
 */

require_once __DIR__ . '/../db.php';        // trae env.php -> APP_KEY_CLOUD
require_once __DIR__ . '/parametros.php';

const DR_LBAJA_PARAM_BASE = 'datarocket.listas.baja.enlace.base';
const DR_LBAJA_PARAM_DIAS = 'datarocket.listas.baja.enlace.dias';

// URL PUBLICA de la pagina. Vive en www/ y no en cloud/ por lo mismo que la
// ficha de interaccion: es anonima y cloud es el dominio del panel.
const DR_LBAJA_BASE_DEFAULT = 'https://www.databox.net.ar/datarocket/suscripcion/';
const DR_LBAJA_DIAS_DEFAULT = 365;

// Etiqueta de dominio del HMAC. Distinta de la de la ficha de interaccion: un
// token de alla no abre una baja y viceversa, aunque los firme la misma clave.
const DR_LBAJA_PROPOSITO = 'datarocket_listas_baja_v1';

const DR_LBAJA_SIG_LEN = 27;

// Valor de `datarocket_lista_baja_motivo` con el que queda registrada la baja.
// Lo siembra la migracion 20260828_2500. Es su propio motivo y no 'manual':
// una baja que pidio el destinatario y una que hizo un operador desde el ABM
// son dos cosas distintas, y la diferencia importa para saber por que se achica
// una lista.
const DR_LBAJA_MOTIVO = 'solicitada';

// Y el de `datarocket_lista_alta_motivo`, para la vuelta: la misma pagina ofrece
// volver a suscribirse al que ya no esta en la lista (el que se dio de baja por
// error no tiene que escribir un mail para volver). Lo siembra la migracion
// 20260829_0200.
//
// Mismo criterio que la baja, y por eso el mismo valor en los dos catalogos: una
// suscripcion que pidio el destinatario desde el pie de un correo no es el alta
// manual de un operador desde el ABM. Distinguirlas es lo que permite responder
// cuantos se arrepintieron — el numero que dice si el enlace de baja se esta
// tocando por error.
//
// Vive en este archivo, que se llama `_baja_`, porque lo que gobierna es el
// enlace: el token es uno solo y sirve para las dos direcciones. Renombrar el
// archivo arrastraria los `require_once` de la pagina publica y del motor de
// campanas sin cambiar nada de lo que hace.
const DR_LALTA_MOTIVO = 'solicitada';

/**
 * Base / dias configurados, sembrando los parametros si no existen.
 * Devuelve ['base' => string, 'dias' => int].
 */
function drListaBajaConfig(PDO $pdo): array {
    parametroAsegurar($pdo, DR_LBAJA_PARAM_BASE, DR_LBAJA_BASE_DEFAULT,
        'URL publica de la pagina de baja de lista que se enlaza al pie de los correos de campana. Tiene que terminar en barra.');
    parametroAsegurar($pdo, DR_LBAJA_PARAM_DIAS, (string) DR_LBAJA_DIAS_DEFAULT,
        'Dias que sigue sirviendo el enlace de baja antes de vencer. Conviene que sea largo: un correo se puede abrir meses despues, y un enlace de baja vencido empuja a marcar como spam.');

    $base = trim((string) parametroLeer($pdo, DR_LBAJA_PARAM_BASE, DR_LBAJA_BASE_DEFAULT));
    if ($base === '') $base = DR_LBAJA_BASE_DEFAULT;
    if (!str_ends_with($base, '/')) $base .= '/';

    $dias = (int) parametroLeer($pdo, DR_LBAJA_PARAM_DIAS, (string) DR_LBAJA_DIAS_DEFAULT);
    if ($dias < 1) $dias = DR_LBAJA_DIAS_DEFAULT;

    return ['base' => $base, 'dias' => $dias];
}

function drListaBajaFirmar(string $payload): string {
    if (!defined('APP_KEY_CLOUD') || (string) APP_KEY_CLOUD === '') {
        throw new RuntimeException('APP_KEY_CLOUD no esta definida: no se puede firmar el enlace de baja.');
    }
    $raw = hash_hmac('sha256', DR_LBAJA_PROPOSITO . '|' . $payload, (string) APP_KEY_CLOUD, true);
    return substr(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 0, DR_LBAJA_SIG_LEN);
}

/** Token de baja de $prospectoId en $listaId, valido por $dias dias. */
function drListaBajaToken(int $prospectoId, int $listaId, int $dias = DR_LBAJA_DIAS_DEFAULT): string {
    $exp     = time() + ($dias * 86400);
    $payload = $prospectoId . '.' . $listaId . '.' . base_convert((string) $exp, 10, 36);
    return $payload . '.' . drListaBajaFirmar($payload);
}

/** URL completa de la pagina de baja. */
function drListaBajaUrl(string $base, int $prospectoId, int $listaId, int $dias = DR_LBAJA_DIAS_DEFAULT): string {
    return $base . '?t=' . drListaBajaToken($prospectoId, $listaId, $dias);
}

/**
 * Verifica un token de baja.
 *
 *   null -> firma invalida o mal formado
 *   ['prospecto_id' => N, 'lista_id' => M, 'exp' => ts, 'vencido' => bool]
 *
 * Igual que en la ficha de interaccion, el vencimiento se devuelve en vez de
 * rechazarse: "este enlace caduco" y "este enlace no existe" son dos mensajes
 * distintos para quien lo toca.
 */
function drListaBajaVerificar(string $token): ?array {
    $token = trim($token);
    if ($token === '') return null;

    $partes = explode('.', $token);
    if (count($partes) !== 4) return null;
    [$pid, $lid, $exp36, $sig] = $partes;

    if ($pid === ''   || !ctype_digit($pid))    return null;
    if ($lid === ''   || !ctype_digit($lid))    return null;
    if ($exp36 === '' || !ctype_alnum($exp36))  return null;

    // hash_equals: comparacion en tiempo constante, para no filtrar la firma
    // correcta byte a byte midiendo cuanto tarda el rechazo.
    $esperada = drListaBajaFirmar($pid . '.' . $lid . '.' . $exp36);
    if (!hash_equals($esperada, $sig)) return null;

    $exp = (int) base_convert(strtolower($exp36), 36, 10);
    return [
        'prospecto_id' => (int) $pid,
        'lista_id'     => (int) $lid,
        'exp'          => $exp,
        'vencido'      => $exp > 0 && time() >= $exp,
    ];
}
