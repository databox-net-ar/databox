<?php
/**
 * api/v4/mercadopago/pendiente.php
 *
 *   GET /v4/mercadopago/pendiente?collection_id=&payment_id=&status=&...
 *
 * Callback de retorno del Checkout Pro para el caso pendiente (transferencia,
 * cupon de pago facil, tarjeta en revision). Lo abre el NAVEGADOR del
 * comprador; no es server-to-server.
 *
 * Marca el pago como 'P' y devuelve al comprador a la URL de `retorno` de su
 * sistema. Escribir desde un callback de navegador se acepta aca porque 'P' no
 * acredita plata: si la notificacion real termina siendo otra, `webhook` la
 * corrige con lo que diga la API de Mercado Pago.
 *
 * Port de `databox-api/v2/mercadopago/pendiente.php`.
 */

require_once __DIR__ . '/_lib/retorno.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.pendiente');

mpRetornoCerrar(mpRetornoPago(), 'P');
