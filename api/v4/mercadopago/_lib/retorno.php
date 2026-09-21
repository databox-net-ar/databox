<?php
/**
 * api/v4/mercadopago/_lib/retorno.php
 *
 * Logica compartida de los tres callbacks de retorno del Checkout Pro
 * (`aprobado`, `pendiente`, `rechazado`).
 *
 * ---------------------------------------------------------------------------
 * QUE SON ESTOS CALLBACKS
 * ---------------------------------------------------------------------------
 * Son REDIRECTS DEL NAVEGADOR, no llamadas server-to-server: Mercado Pago
 * manda al comprador de vuelta a una de las tres `back_urls` con el resultado
 * en la query string. Cualquiera puede abrirlas a mano con los parametros que
 * quiera, asi que NO son fuente de verdad de nada. La fuente de verdad del
 * estado final es `webhook`, que si es server-to-server y verifica contra la
 * API de Mercado Pago con el accessToken de la cuenta.
 *
 * Por eso `aprobado` no marca el pago como aprobado — solo devuelve al
 * comprador a su sistema. Es el comportamiento del legacy y es el correcto:
 * marcar 'A' aca seria dejar que el navegador declare un cobro.
 *
 * `pendiente` y `rechazado` si escriben ('P' y 'R'), porque ninguno de los dos
 * acredita plata: el peor caso de un abuso es marcar como rechazado un pago
 * que despues el webhook va a corregir.
 *
 * ---------------------------------------------------------------------------
 * QUIRK LEGACY — LOS PARAMETROS DE RETORNO NO SE GUARDAN
 * ---------------------------------------------------------------------------
 * El legacy arma un JSON con los diez parametros que manda Mercado Pago
 * (`collection_id`, `payment_id`, `status`, `merchant_order_id`, ...) y lo
 * asigna a `$mMercadopagoPago->respuesta`. Esa columna NO EXISTE en
 * `mercadopagopagos`, y el `modificar()` del framework solo escribe las
 * columnas declaradas en el modelo: la asignacion se descarta en silencio.
 *
 * O sea que hoy, en produccion, esos parametros se pierden. El port replica el
 * comportamiento observable (se escriben `operacion` y `estado`, nada mas) en
 * vez de "arreglarlo", porque arreglarlo implica decidir donde guardarlos y
 * eso cambia lo que ve el panel. Si se quiere recuperarlos, lo natural es una
 * fila en `mercadopagoregistros` con tipo 'I' — una linea en mpRetornoCerrar().
 */

declare(strict_types=1);

require_once __DIR__ . '/mercadopago.php';

/**
 * Los diez parametros con los que Mercado Pago vuelve al navegador. Se leen
 * con la misma semantica que `$oFormulario->getpost()`: GET primero, POST como
 * respaldo, '' cuando falta.
 */
const MP_RETORNO_PARAMS = [
    'collection_id', 'collection_status', 'payment_id', 'status',
    'external_reference', 'payment_type', 'merchant_order_id', 'site_id',
    'processing_mode', 'merchant_account_id',
];

/** Lee un parametro de retorno (GET, luego POST). */
function mpRetornoParam(string $nombre): string {
    $valor = (string)($_GET[$nombre] ?? '');
    if ($valor === '') $valor = (string)($_POST[$nombre] ?? '');
    return $valor;
}

/** Los diez parametros de retorno como array asociativo. */
function mpRetornoParams(): array {
    $salida = [];
    foreach (MP_RETORNO_PARAMS as $p) $salida[$p] = mpRetornoParam($p);
    return $salida;
}

/**
 * Resuelve que pago es el que esta volviendo.
 *
 * Primero el `external_reference` de la query string — que es el id interno que
 * `procesar` le declaro a Mercado Pago — y si no vino, el `pagoId` de la
 * sesion. Ese respaldo importa: cuando el comprador vuelve por el `auto_return`
 * de un pago aprobado, Mercado Pago no siempre incluye `external_reference`.
 *
 * Corta con la pagina de error del legacy si no hay forma de identificarlo.
 */
function mpRetornoPago(): array {
    $id = (int)mpCero(mpRetornoParam('external_reference'));
    if ($id === 0) $id = (int)mpCero(mpSesionLeer('pagoId'));

    $pago = $id > 0 ? mpPagoPorId($id) : null;
    if ($pago === null) {
        mpCortarNavegador('Error inesperado. Inténtelo nuevamente mas tarde.', 404);
    }
    return $pago;
}

/**
 * Cierra el circuito del navegador: actualiza el pago si corresponde, limpia la
 * sesion y devuelve al comprador a la URL de `retorno` que el sistema origen
 * declaro al abrir el boton.
 *
 * `$estado` null = no se toca la fila (caso `aprobado`: lo resuelve el webhook).
 *
 * `$limpiar` replica una asimetria del legacy: `pendiente` y `rechazado`
 * vacian la sesion antes de redirigir, `aprobado` no. No parece deliberado,
 * pero se respeta — con la sesion viva, un `aprobado` sin
 * `external_reference` todavia encuentra el pago, y hay sistemas origen que
 * dependen de eso sin saberlo.
 *
 * `retorno` se valido como `^https?://` en `pagar` antes de guardarse, asi que
 * el Location no puede ser un `javascript:`. Se re-chequea igual: la fila pudo
 * haberse editado a mano desde el ABM del panel entre medio.
 */
function mpRetornoCerrar(array $pago, ?string $estado, bool $limpiar = true): never {
    if ($estado !== null) {
        mpPagoActualizar((int)$pago['id'], [
            'operacion' => mpRetornoParam('payment_id'),
            'estado'    => $estado,
        ]);
    }

    if ($limpiar) mpSesionLimpiar();

    $retorno = (string)($pago['retorno'] ?? '');
    if (!preg_match('#^https?://#i', $retorno)) {
        mpCortarNavegador('El pago se registró, pero el sistema de origen no dejó una URL de retorno válida.', 500);
    }

    header('Location: ' . $retorno);
    exit;
}
