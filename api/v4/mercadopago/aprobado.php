<?php
/**
 * api/v4/mercadopago/aprobado.php
 *
 * @interno  Su URL la emite `procesar` en las `back_urls`; nadie la configura.
 *           Fuera del navegador de Documentacion; el circuito esta explicado
 *           en pagar.md.
 *
 *   GET /v4/mercadopago/aprobado?collection_id=&payment_id=&status=&...
 *
 * Callback de retorno del Checkout Pro para el caso aprobado. Lo abre el
 * NAVEGADOR del comprador cuando Mercado Pago lo devuelve por `auto_return`;
 * no es una llamada server-to-server.
 *
 * NO cambia el estado del pago. Es intencional y viene del legacy: quien marca
 * 'A' es `webhook`, que verifica el cobro contra la API de Mercado Pago con el
 * accessToken de la cuenta. Aca solo se identifica el pago y se devuelve al
 * comprador a la URL de `retorno` que su sistema declaro al abrir el boton.
 *
 * Si se marcara el pago desde aca, cualquiera podria acreditar una factura
 * abriendo esta URL con el `external_reference` correcto.
 *
 * Port de `databox-api/v2/mercadopago/aprobado.php` (el bloque que actualizaba
 * el estado ya estaba comentado ahi; no se porto).
 */

require_once __DIR__ . '/_lib/retorno.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.aprobado');

// null = no se toca la fila. false = no se limpia la sesion (ver mpRetornoCerrar).
mpRetornoCerrar(mpRetornoPago(), null, false);
