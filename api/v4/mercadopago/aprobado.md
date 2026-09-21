# `/v4/mercadopago/aprobado`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/aprobado.md>

Callback de retorno del Checkout Pro para el caso **aprobado**. Lo abre el
navegador del comprador cuando Mercado Pago lo devuelve por `auto_return`; no
es una llamada server-to-server.

Su único trabajo es identificar el pago y **devolver al comprador** a la URL de
`retorno` que su sistema declaró al abrir el botón.

```
GET https://api.databox.net.ar/v4/mercadopago/aprobado?collection_id=&payment_id=&status=&external_reference=&...
```

No hay que configurarlo en ningún lado: lo declara [`procesar`](procesar.md)
como `back_urls.success` al crear la preferencia.

## No cambia el estado del pago

Es intencional y viene del legacy. Quien marca `'A'` es
[`webhook`](webhook.md), que verifica el cobro contra la API de Mercado Pago
con el `accessToken` de la cuenta.

Si se marcara el pago desde acá, **cualquiera podría acreditar una factura**
abriendo esta URL con el `external_reference` correcto. Un callback de
navegador no es fuente de verdad de nada: los parámetros los pone quien abre la
URL.

[`pendiente`](pendiente.md) y [`rechazado`](rechazado.md) sí escriben, porque
ninguno de los dos acredita plata.

## Cómo identifica el pago

1. `external_reference` de la query string — el id interno que `procesar` le
   declaró a Mercado Pago.
2. Si no vino, el `pagoId` de la sesión.

Ese respaldo importa: cuando el comprador vuelve por el `auto_return` de un
pago aprobado, Mercado Pago no siempre incluye `external_reference`.

Por eso este endpoint **no limpia la sesión** (los otros dos sí). No parece
deliberado en el legacy, pero se respeta: con la sesión viva, un `aprobado` sin
`external_reference` todavía encuentra el pago, y hay sistemas origen que
dependen de eso sin saberlo.

## Respuestas

| Código | Cuerpo |
| ------ | ------ |
| 302 | `Location: <retorno del pago>` |
| 404 | `Error inesperado. Inténtelo nuevamente mas tarde.` |
| 500 | `El pago se registró, pero el sistema de origen no dejó una URL de retorno válida.` |

Texto legible, no JSON: del otro lado hay una persona mirando el navegador.

El `retorno` se validó como `^https?://` en [`pagar`](pagar.md) antes de
guardarse, así que el `Location` no puede ser un `javascript:`. Se re-chequea
igual — la fila pudo haberse editado a mano desde el ABM del panel entre medio.

## Parámetros que manda Mercado Pago

`collection_id`, `collection_status`, `payment_id`, `status`,
`external_reference`, `payment_type`, `merchant_order_id`, `site_id`,
`processing_mode`, `merchant_account_id`.

De todos ellos sólo se usa `external_reference`. Ver el quirk en
[`pendiente`](pendiente.md#quirk-heredado-los-parámetros-de-retorno-no-se-guardan).

## Migración desde `/v2/mercadopago/aprobado`

Nada que hacer: la URL la declara `procesar` en las `back_urls`, y esa ya
apunta a `/v4/`.

El bloque que actualizaba el estado ya estaba comentado en el legacy; no se
portó.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagopagos` | Sólo lectura. |
