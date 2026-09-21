<?php
/**
 * api/v4/mercadopago/procesar.php
 *
 *   POST /v4/mercadopago/procesar     (JSON body)  -> {"id": "<preference_id>"}
 *
 * Endpoint AJAX que invoca la propia pagina del boton (`pagar`) cuando el
 * comprador aprieta "Continuar". Crea la Preference en Mercado Pago y devuelve
 * su id, que el front le pasa a `mercadopago.checkout({preference:{id}})`.
 *
 * Port de `databox-api/v2/mercadopago/procesar.php`.
 *
 * ---------------------------------------------------------------------------
 * NO TIENE APIKEY, Y ESTA BIEN
 * ---------------------------------------------------------------------------
 * Lo llama un navegador, no un servidor: una apikey aca seria una apikey
 * impresa en el HTML. Lo que autoriza la llamada es la SESION creada por
 * `pagar` — de ahi salen el `accessToken` de la cuenta y el `pagoId`. Sin
 * sesion no hay con que firmar y la llamada se rechaza.
 *
 * Por eso tampoco se confia en el body para saber a quien cobrarle: el body
 * solo aporta titulo, cantidad y precio (lo que el usuario ve en pantalla), y
 * el `external_reference` sale SIEMPRE de la sesion.
 *
 * ---------------------------------------------------------------------------
 * DIFERENCIA CON EL LEGACY
 * ---------------------------------------------------------------------------
 * El legacy usa el SDK oficial (`MercadoPago\SDK::setAccessToken()` +
 * `$preference->save()`). Aca es un POST con curl a `/checkout/preferences`
 * (ver mpApiPreferenciaCrear en _lib/mercadopago.php): el arbol `api/` no
 * tiene composer y el body que arma el SDK para este caso son cuatro claves.
 *
 * El legacy ademas ruteaba por `REQUEST_URI` con un `switch` que arrastraba
 * dos ramas muertas del ejemplo de Mercado Pago (`/feedback` y un server de
 * archivos estaticos). No se portaron: a este archivo solo se llega por su
 * propia ruta.
 *
 * Las `back_urls` apuntan a los endpoints de ESTE microservicio (v4). Es el
 * unico lugar donde habia un host hardcodeado en el legacy; aca sale de
 * mpBasePublica().
 */

require_once __DIR__ . '/_lib/mercadopago.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.procesar');

header('Content-Type: application/json; charset=utf-8');

// Respuesta del legacy: objeto pelado `{"id": ...}`, sin sobre. El front solo
// mira `.id`, asi que el `error` opcional es informacion extra para poder
// diagnosticar desde la consola del navegador sin romper a nadie.
$responder = static function (?string $id, ?string $error = null): never {
    $salida = ['id' => $id];
    if ($error !== null) $salida['error'] = $error;
    echo json_encode($salida, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    $responder(null, 'Metodo no permitido');
}

$accessToken = mpSesionLeer('cuentaAccessToken');
$pagoId      = (int)mpCero(mpSesionLeer('pagoId'));

// Sin sesion no hay pago en curso. El legacy llamaba igual a Mercado Pago con
// el token vacio y devolvia `{"id":null}` sin explicacion; el front mostraba
// "Unexpected error". Mismo resultado visible, con el motivo adentro.
if ($accessToken === '' || $pagoId <= 0) {
    http_response_code(409);
    $responder(null, 'Sesión de pago no iniciada o vencida. Volvé a abrir el botón de pago.');
}

$cuerpo = file_get_contents('php://input') ?: '';
$datos  = json_decode($cuerpo, true);
if (!is_array($datos)) $datos = [];

$preferencia = mpApiPreferenciaCrear(
    $accessToken,
    (string)($datos['description'] ?? ''),
    $datos['quantity'] ?? 1,
    $datos['price']    ?? 0,
    // `external_reference` = id interno del pago. Es la unica pista que trae
    // despues la notificacion del webhook para saber que factura se cobro.
    (string)$pagoId,
    [
        'success' => mpUrl('aprobado'),
        'failure' => mpUrl('rechazado'),
        'pending' => mpUrl('pendiente'),
    ]
);

$id = (string)mpJsonLeer($preferencia, 'id');

if ($id === '') {
    // La preferencia no se creo. Queda en el log crudo con el cuerpo completo
    // de Mercado Pago, que es donde esta el motivo real (`auto_return invalid`,
    // `invalid access token`, monto fuera de rango...).
    mpRegistrar(MP_REG_INFO, 'Preferencia rechazada (pago ' . $pagoId . '): ' . $preferencia);
    http_response_code(502);
    $responder(null, (string)(mpJsonLeer($preferencia, 'message') ?: 'Mercado Pago rechazó la preferencia'));
}

$responder($id);
