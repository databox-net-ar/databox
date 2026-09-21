<?php
/**
 * api/v4/mercadopago/webhook.php
 *
 *   POST /v4/mercadopago/webhook?cta=<uuid de la cuenta>
 *   GET  /v4/mercadopago/webhook?cta=...   -> 200 OK (sonda de Mercado Pago)
 *
 * Endpoint SERVER-TO-SERVER que llama Mercado Pago para notificar cambios. Es
 * la fuente de verdad del estado final de pagos, suscripciones y debitos: los
 * callbacks de navegador (`aprobado`/`pendiente`/`rechazado`) no acreditan
 * nada, este si.
 *
 * Port de `databox-api/v2/mercadopago/webhook.php`.
 *
 * ---------------------------------------------------------------------------
 * POR QUE SIEMPRE CONTESTA 200
 * ---------------------------------------------------------------------------
 * Mercado Pago reintenta toda notificacion que no cierre con 2xx. Si este
 * endpoint devolviera 500 porque el sistema origen esta caido, Mercado Pago
 * reenviaria la misma notificacion durante horas y cada reintento volveria a
 * procesar el mismo cobro. Por eso todo lo que puede fallar hacia afuera (la
 * imputacion) es best-effort y queda registrado en `mercadopagoregistros` en
 * vez de romper la respuesta.
 *
 * Lo que si queda expuesto son los errores de ESTE servicio: el shutdown
 * handler de `_lib/log.php` los manda a `sucesos` (Visor de sucesos del panel).
 *
 * ---------------------------------------------------------------------------
 * QUE NO VALIDA
 * ---------------------------------------------------------------------------
 * No hay apikey ni verificacion de firma: la notificacion solo dice "algo
 * cambio, andá a buscarlo". El dato real NUNCA se toma del body — se relee de
 * la API de Mercado Pago con el accessToken de la cuenta. Alguien que invente
 * un POST a esta URL solo logra que el servicio le pregunte a Mercado Pago por
 * un id que no existe.
 *
 * `mercadopagocuentas` tiene columnas `webhookKey` / `webhookKeyTesting` para
 * la firma `x-signature`, pero el legacy nunca las uso y el port no las usa
 * tampoco — activarlas ahora rechazaria notificaciones de cuentas que las
 * tienen vacias.
 *
 * ---------------------------------------------------------------------------
 * SELECTOR POR `type`
 * ---------------------------------------------------------------------------
 *   payment                        -> pago manual del boton
 *   subscription_preapproval       -> cambio de estado de una suscripcion
 *   subscription_authorized_payment-> debito recurrente de una suscripcion
 *   (cualquier otro)               -> se registra crudo con tipo 'N'
 *
 * Todas las notificaciones quedan en `mercadopagoregistros` ANTES de
 * procesarse. Si el procesamiento revienta, la notificacion cruda ya esta
 * guardada y se puede reconstruir a mano.
 */

require_once __DIR__ . '/_lib/mercadopago.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.webhook');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET es la sonda con la que Mercado Pago comprueba que la URL existe al
// configurarla en el panel del vendedor. No hace nada, pero tiene que contestar.
if ($metodo === 'GET')  mpResponder(200, 'OK');
if ($metodo !== 'POST') mpResponder(405, 'Method Not Allowed');

$cuenta       = mpCuentaPorUuid(trim((string)($_GET['cta'] ?? $_POST['cta'] ?? '')));
$notificacion = file_get_contents('php://input') ?: '';
$tipo         = (string)mpJsonLeer($notificacion, 'type');

// DIVERGENCIA DELIBERADA CON EL LEGACY: con un `cta` desconocido el legacy
// seguia igual con una cuenta vacia — le pegaba a Mercado Pago sin token,
// recibia un 401 y terminaba insertando una fila en `mercadopagopagos` con
// cuenta=0 y el error adentro de `propiedades`. Aca se registra la
// notificacion (que es lo unico rescatable) y se contesta 200 para que
// Mercado Pago no reintente.
if ($cuenta === null) {
    mpRegistrar(MP_REG_DESCONOCIDO, $notificacion);
    mpRegistrar(MP_REG_INFO, 'Notificacion sin cuenta: cta=' . (string)($_GET['cta'] ?? ''));
    mpResponder(200, 'OK');
}

switch ($tipo) {

    case 'payment':
        webhookPago($cuenta, $notificacion);
        break;

    case 'subscription_preapproval':
        webhookSuscripcion($cuenta, $notificacion);
        break;

    case 'subscription_authorized_payment':
        webhookDebito($cuenta, $notificacion);
        break;

    default:
        mpRegistrar(MP_REG_DESCONOCIDO, $notificacion);
        break;
}

mpResponder(200, 'OK');

// ---------------------------------------------------------------------------
// `payment` — pago manual del boton
// ---------------------------------------------------------------------------
//
// El body solo trae `data.id` (el id del pago EN Mercado Pago). Con eso se
// consulta `/v1/payments/{id}`, de donde salen las dos cosas que importan:
// `external_reference` (= el id interno del pago, que `procesar` declaro al
// crear la preferencia) y `status`.
//
// OJO: el token que se usa aca es `accessToken` (produccion) aunque la cuenta
// este en modo testing. Es asi en el legacy y se mantiene — una cuenta de
// testing con este webhook no resuelve, y cambiarlo romperia las que hoy si
// resuelven.

function webhookPago(array $cuenta, string $notificacion): void {
    mpRegistrar(MP_REG_PAGO, $notificacion);

    $operacion   = (string)mpJsonLeer($notificacion, 'data', 'id');
    $propiedades = mpApiPagoObtener((string)$cuenta['accessToken'], $operacion);

    $pagoId = (int)mpCero(mpJsonLeer($propiedades, 'external_reference'));
    $status = (string)mpJsonLeer($propiedades, 'status');

    // Sin `external_reference` que matchee no hay factura que imputar: es un
    // cobro iniciado fuera del boton (link de pago, QR, cobro manual desde la
    // app de Mercado Pago). Se deja asentado igual para que aparezca en el
    // listado de Pagos del panel.
    $pago = $pagoId > 0 ? mpPagoPorId($pagoId) : null;
    if ($pago === null) {
        mpPagoAlta([
            'cuenta'       => (int)$cuenta['id'],
            'notificacion' => $notificacion,
            'propiedades'  => $propiedades,
        ]);
        return;
    }

    $estado = ($status === 'approved') ? 'A' : 'R';

    mpPagoActualizar((int)$pago['id'], [
        'finalizado'   => mpAhora(),
        'operacion'    => $operacion,
        'estado'       => $estado,
        'notificacion' => $notificacion,
        'propiedades'  => $propiedades,
    ]);

    // Imputacion: le avisa al sistema origen que la factura quedo cobrada.
    // Solo cuando el pago quedo aprobado.
    if ((string)$cuenta['imputacion'] !== '' && $estado === 'A') {
        mpImputar($cuenta['imputacion']
            . '?mod=pago&fac=' . rawurlencode((string)$pago['factura'])
            . '&ope=' . rawurlencode($operacion));
    }
}

// ---------------------------------------------------------------------------
// `subscription_preapproval` — cambio de estado de una suscripcion
// ---------------------------------------------------------------------------

function webhookSuscripcion(array $cuenta, string $notificacion): void {
    mpRegistrar(MP_REG_INFO, 'Suscripción actualizada');
    mpRegistrar(MP_REG_SUSCRIPCION, $notificacion);

    $uuid        = (string)mpJsonLeer($notificacion, 'data', 'id');
    $suscripcion = mpSuscripcionPorUuid($uuid);
    if ($suscripcion === null) {
        mpRegistrar(MP_REG_INFO, 'Suscripcion desconocida: ' . $uuid);
        return;
    }

    // La cuenta de la suscripcion manda sobre el `cta` de la query string: una
    // suscripcion pertenece a la cuenta con la que se dio de alta, y es esa
    // credencial la que puede leerla. Asi lo hace el legacy.
    $cuentaSus = mpCuentaPorId((int)$suscripcion['cuenta']) ?? $cuenta;

    $propiedades = mpApiSuscripcionObtener((string)$cuentaSus['accessToken'],
                                           (string)$suscripcion['uuid']);

    // DIVERGENCIA DELIBERADA CON EL LEGACY: `traducir()` escribe `uuid` y
    // `estado` con lo que venga en la respuesta. Si la API contesta un error
    // (401, 404, timeout), esos campos salen vacios y el `modificar()` del
    // legacy BORRA el uuid de la fila — la suscripcion queda huerfana y ninguna
    // notificacion posterior la vuelve a encontrar. Aca se exige que la
    // respuesta sea un preapproval de verdad antes de escribir.
    if ((string)mpJsonLeer($propiedades, 'id') === '') {
        mpRegistrar(MP_REG_DESCONOCIDO, $propiedades);
        mpRegistrar(MP_REG_INFO, 'Lectura de suscripcion fallida, no se actualiza: ' . $uuid);
        return;
    }

    mpSuscripcionActualizar(
        (int)$suscripcion['id'],
        mpSuscripcionTraducir($propiedades, (string)$suscripcion['estado'])
    );

    // QUIRK LEGACY — `ref` va VACIO.
    // El legacy arma este link con `$mMercadopagoDebito->referencia`, que en
    // esta rama es un objeto sin cargar: la propiedad vale ''. Deberia ser la
    // referencia de la SUSCRIPCION. Se replica tal cual porque los sistemas
    // origen ya reciben este link asi y varios resuelven por `sus`; mandarles
    // de golpe un `ref` con contenido cambiaria lo que hoy consumen.
    // Para cambiarlo: `(string)$suscripcion['referencia']` en lugar del ''.
    if ((string)$cuentaSus['imputacion'] !== '') {
        mpImputar($cuentaSus['imputacion']
            . '?mod=suscripcion&sus=' . rawurlencode((string)$suscripcion['uuid'])
            . '&ref=');
    }
}

// ---------------------------------------------------------------------------
// `subscription_authorized_payment` — debito recurrente
// ---------------------------------------------------------------------------

function webhookDebito(array $cuenta, string $notificacion): void {
    mpRegistrar(MP_REG_INFO, 'Débito registrado');
    mpRegistrar(MP_REG_DEBITO, $notificacion);

    $debitoMpId  = (string)mpJsonLeer($notificacion, 'data', 'id');
    $propiedades = mpApiDebitoObtener((string)$cuenta['accessToken'], $debitoMpId);

    // El legacy guarda tambien la respuesta cruda en el log. Es redundante con
    // la columna `propiedades` del debito, pero es el unico rastro cuando la
    // lectura falla y el debito queda con estado ''.
    mpRegistrar(MP_REG_INFO, $propiedades);

    $campos = mpDebitoTraducir($propiedades);

    $uuid   = mpUuid();
    $estado = (string)$campos['estado'];

    mpDebitoAlta($campos + [
        'uuid'   => $uuid,
        'cuenta' => (int)$cuenta['id'],
    ]);

    // QUIRK LEGACY — `sus` lleva el uuid del DEBITO, no el de la suscripcion.
    // `$mMercadopagoDebito->uuid` es la cadena aleatoria de 16 caracteres que
    // se acaba de generar para la fila del debito. El nombre del parametro
    // sugiere la suscripcion, pero lo que viaja es esto, y asi lo reciben hoy
    // los sistemas origen. Se replica.
    // Para cambiarlo: resolver la suscripcion por `$campos['suscripcion']` y
    // mandar su `uuid`.
    if ((string)$cuenta['imputacion'] !== '' && $estado === 'A') {
        mpImputar($cuenta['imputacion']
            . '?mod=debito&sus=' . rawurlencode($uuid)
            . '&ref=' . rawurlencode((string)$campos['referencia']));
    }
}
