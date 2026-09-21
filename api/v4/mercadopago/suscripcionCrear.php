<?php
/**
 * api/v4/mercadopago/suscripcionCrear.php
 *
 *   POST /v4/mercadopago/suscripcionCrear?apikey=<apikey>    (JSON body)
 *   POST /v4/mercadopago/suscripcionCrear                    (Authorization: Bearer <apikey>)
 *
 * Alta de una suscripcion (preapproval de Mercado Pago) desde un sistema
 * externo. Es el UNICO endpoint del microservicio pensado para que lo llame un
 * servidor y no un navegador.
 *
 * Port de `databox-api/v2/mercadopago/suscripcionCrear.php`.
 *
 * ---------------------------------------------------------------------------
 * EL NOMBRE DEL ARCHIVO VA EN camelCase A PROPOSITO
 * ---------------------------------------------------------------------------
 * El resto del arbol v4 usa minusculas (`mensajes.php`, `etiquetas.php`), pero
 * la URL publica del legacy es `/v2/mercadopago/suscripcionCrear` y en Linux
 * las rutas distinguen mayusculas. Renombrarlo obligaria a cada cliente a
 * cambiar mas que el numero de version, que es justo lo que este port quiere
 * evitar.
 *
 * ---------------------------------------------------------------------------
 * AUTENTICACION: LAS DOS FORMAS
 * ---------------------------------------------------------------------------
 * Acepta el `?apikey=` del legacy y el `Authorization: Bearer` del stack nuevo
 * (ver mpAutorizarAplicacion en _lib/mercadopago.php). Un cliente que hoy
 * llama al legacy cambia `/v2/` por `/v4/` y nada mas; cuando quiera pasarse a
 * Bearer, lo hace sin esperar un deploy de este lado.
 *
 * ---------------------------------------------------------------------------
 * CONTRATO DE RESPUESTA (legacy, no el de la casa)
 * ---------------------------------------------------------------------------
 *   200 -> el objeto de la suscripcion PELADO, sin sobre:
 *          {"uuid":"...","cuenta":"114","nombre":"...", ...}
 *   !=200 -> {"respuesta":{"codigo":603,"mensaje":"Falta cuenta"}}
 *
 * Todos los valores del 200 viajan como STRING, incluidos `cuenta` y `monto`.
 * Es como los devuelve el legacy y hay clientes que comparan contra strings.
 *
 * Codigos: 405 metodo, 601 falta apikey, 602 apikey invalida, 603 falta cuenta,
 * 604 falta correo, 610 falta destino. No son codigos HTTP validos y viajan
 * igual en el status line, como en el legacy.
 */

require_once __DIR__ . '/_lib/mercadopago.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.suscripcionCrear');

// El orden importa: primero la apikey (601/602) y recien despues el metodo
// (405), igual que el legacy. Un cliente que manda GET sin apikey recibe 601,
// no 405.
v4LogApp(mpAutorizarAplicacion());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mpRevelar(405, 'Metodo no permitido');
}

$body = file_get_contents('php://input') ?: '';

$cuentaUuid       = (string)mpJsonLeer($body, 'cuenta');
$nombre           = (string)mpJsonLeer($body, 'nombre');
$celular          = (string)mpJsonLeer($body, 'celular');
$correo           = (string)mpJsonLeer($body, 'correo');
$referencia       = (string)mpJsonLeer($body, 'referencia');
$concepto         = (string)mpJsonLeer($body, 'concepto');
$monto            = mpJsonLeer($body, 'monto');
$periodo          = (string)mpJsonLeer($body, 'periodo');
$frecuencia       = mpJsonLeer($body, 'frecuencia');
$pruebaPeriodo    = (string)mpJsonLeer($body, 'pruebaPeriodo');
$pruebaFrecuencia = mpJsonLeer($body, 'pruebaFrecuencia');
$destino          = (string)mpJsonLeer($body, 'destino');

// Los tres obligatorios, en el mismo orden de chequeo que el legacy.
if ($cuentaUuid === '') mpRevelar(603, 'Falta cuenta');
if ($correo === '')     mpRevelar(604, 'Falta correo');
if ($destino === '')    mpRevelar(610, 'Falta destino');

$cuenta = mpCuentaPorUuid($cuentaUuid);
// El legacy sigue con una cuenta vacia y termina insertando una suscripcion
// inservible. 603 es el codigo que ya significa "problema con `cuenta`", asi
// que reusarlo no le agrega un caso nuevo a ningun cliente.
if ($cuenta === null) mpRevelar(603, 'Falta cuenta');

mpRegistrar(MP_REG_INFO, 'Suscripción registrada');

// Defaults de `mcMercadopagoSuscripcion::nuevo()`. Aplican cuando el body no
// los trae; despues `mpSuscripcionTraducir()` los pisa con lo que haya
// respondido Mercado Pago.
if ($periodo === '')       $periodo = 'months';
if ($pruebaPeriodo === '') $pruebaPeriodo = 'months';
if ($frecuencia === '')       $frecuencia = 1;
if ($pruebaFrecuencia === '') $pruebaFrecuencia = 0;

// Token de PRODUCCION siempre, tambien para cuentas en modo testing. Es lo que
// hace el legacy; ver la nota de modo testing/produccion en _lib/mercadopago.php.
$propiedades = mpApiSuscripcionAgregar(
    (string)$cuenta['accessToken'],
    $correo, $referencia, $concepto, $monto,
    $periodo, $frecuencia, $pruebaPeriodo, $pruebaFrecuencia, $destino
);

// QUIRK LEGACY — un alta rechazada por Mercado Pago igual devuelve 200.
// `traducir()` no encuentra `id` en la respuesta de error, la fila se inserta
// con `uuid` vacio y estado '' y el endpoint contesta 200 con los campos en
// blanco. Se replica: hay clientes que ya conviven con eso. La forma de
// detectarlo del lado del cliente es la de siempre — si `uuid` viene vacio, el
// alta no se hizo. El motivo real queda en `mercadopagoregistros`.
if ((string)mpJsonLeer($propiedades, 'id') === '') {
    mpRegistrar(MP_REG_DESCONOCIDO, $propiedades);
    mpRegistrar(MP_REG_INFO, 'Alta de suscripcion rechazada por Mercado Pago (cuenta '
        . (string)$cuenta['uuid'] . ', correo ' . $correo . ')');
}

$traducido = mpSuscripcionTraducir($propiedades, 'pending');

$suscripcionId = mpSuscripcionAlta($traducido + [
    'cuenta'     => (int)$cuenta['id'],
    'nombre'     => $nombre,
    'celular'    => $celular,
    'correo'     => $correo,
    'referencia' => $referencia,
]);

$suscripcion = mpSuscripcionPorId($suscripcionId) ?? [];

// Mismas trece claves, en el mismo orden, y todas como string — es el objeto
// que el legacy arma campo por campo con `$oJson->escribir()`.
$salida = [];
foreach (['uuid', 'cuenta', 'nombre', 'celular', 'correo', 'referencia',
          'concepto', 'monto', 'periodo', 'frecuencia', 'pruebaPeriodo',
          'pruebaFrecuencia', 'destino'] as $campo) {
    $salida[$campo] = (string)($suscripcion[$campo] ?? '');
}

mpRevelar(200, 'OK', $salida);
