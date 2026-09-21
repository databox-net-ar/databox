# `/v4/mercadopago/rechazado`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/rechazado.md>

Callback de retorno del Checkout Pro para el caso **rechazado**: tarjeta
denegada, fondos insuficientes, checkout abandonado con error. Lo abre el
navegador del comprador; no es una llamada server-to-server.

Marca el pago como `'R'` y devuelve al comprador a la URL de `retorno` de su
sistema.

```
GET https://api.databox.net.ar/v4/mercadopago/rechazado?collection_id=&payment_id=&status=&external_reference=&...
```

No hay que configurarlo en ningún lado: lo declara [`procesar`](procesar.md)
como `back_urls.failure` al crear la preferencia.

## Qué escribe

| Columna de `mercadopagopagos` | Valor |
| ----------------------------- | ----- |
| `estado` | `'R'` |
| `operacion` | El `payment_id` de la query string |

Igual que [`pendiente`](pendiente.md): escribir `'R'` desde el navegador es
seguro porque no acredita nada, y [`webhook`](webhook.md) tiene la última
palabra. Un pago que el comprador reintenta y aprueba termina en `'A'` por el
webhook aunque este callback lo haya dejado en `'R'`.

Después de escribir, **limpia la sesión** del pago en curso.

## Cómo identifica el pago

1. `external_reference` de la query string.
2. Si no vino, el `pagoId` de la sesión.

## Respuestas

| Código | Cuerpo |
| ------ | ------ |
| 302 | `Location: <retorno del pago>` |
| 404 | `Error inesperado. Inténtelo nuevamente mas tarde.` |
| 500 | `El pago se registró, pero el sistema de origen no dejó una URL de retorno válida.` |

Texto legible, no JSON: del otro lado hay una persona mirando el navegador.

## Parámetros que manda Mercado Pago

`collection_id`, `collection_status`, `payment_id`, `status`,
`external_reference`, `payment_type`, `merchant_order_id`, `site_id`,
`processing_mode`, `merchant_account_id`.

Sólo se usan `external_reference` y `payment_id`. Los otros ocho se pierden —
ver el quirk en
[`pendiente`](pendiente.md#quirk-heredado-los-parámetros-de-retorno-no-se-guardan).

## Migración desde `/v2/mercadopago/rechazado`

Nada que hacer: la URL la declara `procesar` en las `back_urls`, y esa ya
apunta a `/v4/`.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagopagos` | Lectura y escritura de `estado` / `operacion`. |
