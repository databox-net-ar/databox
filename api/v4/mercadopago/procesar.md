# `/v4/mercadopago/procesar`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/procesar.md>

Endpoint AJAX que crea la **Preference** del Checkout Pro. Lo invoca la propia
página de [`pagar`](pagar.md) cuando el comprador aprieta *Continuar*; devuelve
el `id` de la preferencia, que el front le pasa a
`mercadopago.checkout({preference:{id}})` para renderizar el botón de Mercado
Pago.

No es un endpoint de integración: no está pensado para que lo llame un sistema
externo, sino el HTML que sirve `pagar`.

```
POST https://api.databox.net.ar/v4/mercadopago/procesar
```

## Autenticación

**Ninguna, y está bien.** Lo llama un navegador: una apikey acá sería una
apikey impresa en el HTML.

Lo que autoriza la llamada es la **sesión** creada por [`pagar`](pagar.md) — de
ahí salen el `accessToken` de la cuenta y el `pagoId`. Sin sesión no hay con
qué firmar y la llamada se rechaza con 409.

Por eso tampoco se confía en el body para saber a quién cobrarle: el body sólo
aporta título, cantidad y precio (lo que el comprador ve en pantalla), y el
`external_reference` sale **siempre** de la sesión.

## Body

```json
{ "description": "Factura 001-275587", "quantity": "1", "price": "4891.58" }
```

| Campo | Descripción |
| ----- | ----------- |
| `description` | Título del ítem en el checkout. |
| `quantity` | Cantidad. El botón siempre manda `1`. |
| `price` | Precio unitario. |

## Respuesta

Objeto **pelado**, sin el sobre `{ok,data}` del resto del stack — es el
contrato del legacy y el front ya lo parsea así:

```json
{ "id": "1234567890-abcd-efgh-ijkl-mnopqrstuvwx" }
```

| Código | Cuerpo | Cuándo |
| ------ | ------ | ------ |
| 200 | `{"id":"<preference_id>"}` | Preferencia creada. |
| 405 | `{"id":null,"error":"Metodo no permitido"}` | No es POST. |
| 409 | `{"id":null,"error":"Sesión de pago no iniciada o vencida..."}` | No hay sesión de `pagar`. |
| 502 | `{"id":null,"error":"<mensaje de Mercado Pago>"}` | Mercado Pago rechazó la preferencia. |

La clave `error` es agregada: el legacy devuelve `{"id":null}` sin explicación y
el front muestra "Unexpected error". El front sólo mira `.id`, así que `error`
es información extra para diagnosticar desde la consola del navegador sin
romperle el parseo a nadie.

Cuando la preferencia se rechaza, el cuerpo completo de Mercado Pago queda en
`mercadopagoregistros` con tipo `'I'` — ahí está el motivo real
(`auto_return invalid`, `invalid access token`, monto fuera de rango...).

## Qué se le manda a Mercado Pago

`POST https://api.mercadopago.com/checkout/preferences`:

```json
{
  "items": [{ "title": "...", "quantity": 1, "unit_price": 4891.58 }],
  "back_urls": {
    "success": "https://api.databox.net.ar/v4/mercadopago/aprobado",
    "failure": "https://api.databox.net.ar/v4/mercadopago/rechazado",
    "pending": "https://api.databox.net.ar/v4/mercadopago/pendiente"
  },
  "auto_return": "approved",
  "external_reference": "<id interno del pago>"
}
```

El `external_reference` es la única pista que después trae la notificación del
[`webhook`](webhook.md) para saber qué factura se cobró.

## Base pública y desarrollo

Las `back_urls` tienen que ser URLs que **Mercado Pago pueda resolver desde
afuera**: con `auto_return=approved`, una `success` inalcanzable hace que
rechace la preferencia entera.

Orden de resolución de la base (`mpBasePublica()`):

1. `MP_PUBLIC_BASE` del `.env` — para apuntar a un túnel en desarrollo.
2. `https://api.databox.net.ar` si `APP_ENV=production`.
3. `http://localhost:8114` en desarrollo.

En desarrollo, sin `MP_PUBLIC_BASE`, el HTML del botón se puede ver pero el
checkout real no completa. Es lo esperado.

## Migración desde `/v2/mercadopago/procesar`

No hay nada que tocar del lado del cliente: este endpoint lo llama el HTML que
sirve [`pagar`](pagar.md), y ese HTML ya apunta a `/v4/`.

Diferencia interna: el legacy usa el SDK oficial (`MercadoPago\SDK` +
`$preference->save()`). Acá es un POST con curl, porque el árbol `api/` no
tiene composer y el body que arma el SDK para este caso son cuatro claves. El
legacy además ruteaba por `REQUEST_URI` con un `switch` que arrastraba dos
ramas muertas del ejemplo de Mercado Pago (`/feedback` y un server de archivos
estáticos); no se portaron.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagoregistros` | Escritura: sólo cuando Mercado Pago rechaza la preferencia. |
