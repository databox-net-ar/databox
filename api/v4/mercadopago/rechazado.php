<?php
/**
 * api/v4/mercadopago/rechazado.php
 *
 *   GET /v4/mercadopago/rechazado?collection_id=&payment_id=&status=&...
 *
 * Callback de retorno del Checkout Pro para el caso rechazado. Lo abre el
 * NAVEGADOR del comprador; no es server-to-server.
 *
 * Marca el pago como 'R' y devuelve al comprador a la URL de `retorno` de su
 * sistema. Igual que `pendiente`: escribir 'R' desde el navegador es seguro
 * porque no acredita nada, y `webhook` tiene la ultima palabra.
 *
 * Port de `databox-api/v2/mercadopago/rechazado.php`.
 */

require_once __DIR__ . '/_lib/retorno.php';
require_once dirname(__DIR__) . '/_lib/log.php';

v4InitLog('v4/mercadopago.rechazado');

mpRetornoCerrar(mpRetornoPago(), 'R');
