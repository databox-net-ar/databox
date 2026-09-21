# `/v4/mercadopago/pendiente`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/pendiente.md>

Callback de retorno del Checkout Pro para el caso **pendiente**: transferencia,
cupón de pago fácil, tarjeta en revisión. Lo abre el navegador del comprador;
no es una llamada server-to-server.

Marca el pago como `'P'` y devuelve al comprador a la URL de `retorno` de su
sistema.

```
GET https://api.databox.net.ar/v4/mercadopago/pendiente?collection_id=&payment_id=&status=&external_reference=&...
```

No hay que configurarlo en ningún lado: lo declara [`procesar`](procesar.md)
como `back_urls.pending` al crear la preferencia.

## Qué escribe

| Columna de `mercadopagopagos` | Valor |
| ----------------------------- | ----- |
| `estado` | `'P'` |
| `operacion` | El `payment_id` de la query string |

Escribir desde un callback de navegador se acepta acá porque **`'P'` no
acredita plata**: si la notificación real termina siendo otra,
[`webhook`](webhook.md) la corrige con lo que diga la API de Mercado Pago. Por
eso [`aprobado`](aprobado.md) sí es de sólo lectura.

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

## ⚠ Quirk heredado: los parámetros de retorno no se guardan

El legacy arma un JSON con esos diez parámetros y lo asigna a
`$mMercadopagoPago->respuesta`. **Esa columna no existe** en
`mercadopagopagos`, y el `modificar()` del framework sólo escribe las columnas
declaradas en el modelo: la asignación se descarta en silencio.

O sea que hoy, en producción, esos parámetros se pierden. El port replica el
comportamiento observable —se escriben `operacion` y `estado`, nada más— en vez
de "arreglarlo", porque arreglarlo implica decidir dónde guardarlos y eso
cambia lo que ve el panel.

Si se quieren recuperar, lo natural es una fila en `mercadopagoregistros` con
tipo `'I'`: una línea en `mpRetornoCerrar()`
([`_lib/retorno.php`](_lib/retorno.php)).

No es una pérdida grave: el payload completo y verificado del pago lo trae
[`webhook`](webhook.md) y queda en `mercadopagopagos.propiedades`.

## Migración desde `/v2/mercadopago/pendiente`

Nada que hacer: la URL la declara `procesar` en las `back_urls`, y esa ya
apunta a `/v4/`.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagopagos` | Lectura y escritura de `estado` / `operacion`. |
