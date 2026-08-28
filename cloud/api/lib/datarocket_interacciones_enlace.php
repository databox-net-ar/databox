<?php
/**
 * api/lib/datarocket_interacciones_enlace.php
 * Token firmado y URL de la ficha publica de una interaccion pendiente.
 *
 * PARA QUE
 * --------
 * El aviso de pendientes (jobs/datarocket_interacciones_avisar_pendientes.php)
 * le manda un WhatsApp al responsable por cada consulta sin responder. El
 * mensaje se basta a si mismo para contestar, pero para MARCARLA como atendida
 * habia que entrar al panel, buscarla en el ABM y usar el menu contextual. Este
 * enlace corta ese camino: abre la ficha completa del prospecto y ofrece un solo
 * boton.
 *
 *   https://cloud.databox.net.ar/datarocket/interacciones/?t=1041.mrb8k.<firma>
 *
 * La pagina que lo consume es cloud/datarocket/interacciones/index.php y es
 * PUBLICA: no pide login, porque quien la abre lo hace desde el WhatsApp en el
 * celular y no tiene sesion del panel ahi.
 *
 * POR QUE UN TOKEN Y NO EL ID PELADO
 * ----------------------------------
 * Sin sesion, lo unico que separa esa ficha del mundo es la URL. Con `?id=1041`
 * cualquiera puede recorrer el padron entero cambiando el numero — la ficha trae
 * nombre, telefono, correo y domicilio de una persona real. Con firma HMAC, un
 * token solo abre la interaccion para la que se emitio y no se puede fabricar
 * sin APP_KEY_CLOUD.
 *
 * FORMATO
 * -------
 *   <id>.<vencimiento en base36>.<firma>
 *
 * La firma es HMAC-SHA256 de "<proposito>|<id>.<venc>" con APP_KEY_CLOUD (la
 * misma clave que firma los JWT del panel), recortada a 27 caracteres base64url
 * = 162 bits. De sobra contra fuerza bruta y deja la URL corta, que importa: se
 * lee y se toca en la pantalla de un celular.
 *
 * El `<proposito>` adentro del HMAC hace que un token de esta familia no valga
 * en ninguna otra que use la misma clave, presente o futura.
 *
 * VENCIMIENTO
 * -----------
 * Va DENTRO del token, asi que no hace falta tabla de control: verificar es
 * recalcular la firma. El precio es que un token emitido no se puede revocar
 * antes de tiempo — por eso el default son 30 dias y no "para siempre". Un
 * enlace vivo es un mensaje de WhatsApp que queda en el historial del telefono
 * de alguien; que caduque acota cuanto tiempo esa ficha sigue siendo alcanzable
 * para quien tenga acceso a ese chat.
 *
 * Un token vencido NO es un error: la pagina lo distingue de uno invalido y
 * dice que el enlace caduco, en vez de dar un 403 que parece una falla.
 */

require_once __DIR__ . '/../db.php';        // trae env.php -> APP_KEY_CLOUD
require_once __DIR__ . '/parametros.php';

// Parametros runtime. Los siembra drIntEnlaceConfig() en la primera corrida
// para que aparezcan en Herramientas > Editor de parametros sin crearlos a mano.
const DR_INT_ENLACE_PARAM_BASE = 'datarocket.interacciones.enlace.base';
const DR_INT_ENLACE_PARAM_DIAS = 'datarocket.interacciones.enlace.dias';

// La base es la URL PUBLICA de la pagina, no la interna del contenedor: este
// link lo abre una persona desde su celular, fuera de la red del servidor.
const DR_INT_ENLACE_BASE_DEFAULT = 'https://cloud.databox.net.ar/datarocket/interacciones/';
const DR_INT_ENLACE_DIAS_DEFAULT = 30;

// Etiqueta de dominio del HMAC: separa esta familia de tokens de cualquier otra
// firmada con APP_KEY_CLOUD. Cambiarla invalida todos los enlaces emitidos.
const DR_INT_ENLACE_PROPOSITO = 'datarocket_interacciones_ficha_v1';

// Caracteres base64url de firma que viajan en el token. 27 * 6 = 162 bits.
const DR_INT_ENLACE_SIG_LEN = 27;

/**
 * Base / dias configurados, sembrando los parametros si no existen.
 * Devuelve ['base' => string, 'dias' => int].
 */
function drIntEnlaceConfig(PDO $pdo): array {
    parametroAsegurar($pdo, DR_INT_ENLACE_PARAM_BASE, DR_INT_ENLACE_BASE_DEFAULT,
        'URL publica de la ficha de interaccion que se manda por WhatsApp en el aviso de pendientes. Tiene que terminar en barra.');
    parametroAsegurar($pdo, DR_INT_ENLACE_PARAM_DIAS, (string) DR_INT_ENLACE_DIAS_DEFAULT,
        'Dias que sigue sirviendo el enlace de la ficha de interaccion antes de vencer. El vencimiento viaja firmado dentro del token: cambiar esto solo afecta a los enlaces nuevos.');

    $base = trim((string) parametroLeer($pdo, DR_INT_ENLACE_PARAM_BASE, DR_INT_ENLACE_BASE_DEFAULT));
    if ($base === '') $base = DR_INT_ENLACE_BASE_DEFAULT;
    // Sin la barra final, `?t=` se pegaria al ultimo segmento del path.
    if (!str_ends_with($base, '/')) $base .= '/';

    $dias = (int) parametroLeer($pdo, DR_INT_ENLACE_PARAM_DIAS, (string) DR_INT_ENLACE_DIAS_DEFAULT);
    if ($dias < 1) $dias = DR_INT_ENLACE_DIAS_DEFAULT;

    return ['base' => $base, 'dias' => $dias];
}

/** Firma base64url recortada del payload `<id>.<venc36>`. */
function drIntEnlaceFirmar(string $payload): string {
    if (!defined('APP_KEY_CLOUD') || (string) APP_KEY_CLOUD === '') {
        throw new RuntimeException('APP_KEY_CLOUD no esta definida: no se puede firmar el enlace de la ficha.');
    }
    $raw = hash_hmac('sha256', DR_INT_ENLACE_PROPOSITO . '|' . $payload, (string) APP_KEY_CLOUD, true);
    return substr(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 0, DR_INT_ENLACE_SIG_LEN);
}

/** Token para una interaccion, valido por $dias dias desde ahora. */
function drIntEnlaceToken(int $interaccionId, int $dias = DR_INT_ENLACE_DIAS_DEFAULT): string {
    $exp     = time() + ($dias * 86400);
    $payload = $interaccionId . '.' . base_convert((string) $exp, 10, 36);
    return $payload . '.' . drIntEnlaceFirmar($payload);
}

/** URL completa de la ficha publica de una interaccion. */
function drIntEnlaceUrl(string $base, int $interaccionId, int $dias = DR_INT_ENLACE_DIAS_DEFAULT): string {
    return $base . '?t=' . drIntEnlaceToken($interaccionId, $dias);
}

/**
 * Verifica un token.
 *
 *   null                                        -> firma invalida o mal formado
 *   ['id' => N, 'exp' => ts, 'vencido' => bool] -> firma OK
 *
 * El vencimiento se devuelve en vez de rechazarse para que la pagina pueda
 * decir "este enlace caduco" en lugar de "no existe": son dos situaciones
 * distintas para quien lo toca, y confundirlas manda a alguien a reportar un
 * bug que no existe.
 */
function drIntEnlaceVerificar(string $token): ?array {
    $token = trim($token);
    if ($token === '') return null;

    $partes = explode('.', $token);
    if (count($partes) !== 3) return null;
    [$id, $exp36, $sig] = $partes;

    if ($id === '' || !ctype_digit($id))        return null;
    if ($exp36 === '' || !ctype_alnum($exp36))  return null;

    // hash_equals sobre la firma recalculada: comparacion en tiempo constante,
    // que es lo que evita filtrar la firma correcta byte a byte midiendo cuanto
    // tarda el rechazo.
    $esperada = drIntEnlaceFirmar($id . '.' . $exp36);
    if (!hash_equals($esperada, $sig)) return null;

    $exp = (int) base_convert(strtolower($exp36), 36, 10);
    return [
        'id'      => (int) $id,
        'exp'     => $exp,
        'vencido' => $exp > 0 && time() >= $exp,
    ];
}
