# `/v4/mercadopago/webhook`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/webhook.md>

Endpoint **server-to-server** que llama Mercado Pago para notificar cambios. Es
la fuente de verdad del estado final de pagos, suscripciones y débitos: los
callbacks de navegador ([`aprobado`](aprobado.md), [`pendiente`](pendiente.md),
[`rechazado`](rechazado.md)) no acreditan nada, éste sí.

```
POST https://api.databox.net.ar/v4/mercadopago/webhook?cta=<uuid de la cuenta>
GET  https://api.databox.net.ar/v4/mercadopago/webhook?cta=...   -> 200 OK (sonda)
```

Se configura en el panel de vendedor de Mercado Pago, en *Tus integraciones >
Webhooks*. El `?cta=` es lo que le dice al servicio de qué cuenta es la
notificación.

## Quién lo llama

**Superficie externa.** Lo llama Mercado Pago, server-to-server. Es el endpoint
más importante del módulo: cerró **15.530 de los 15.617** pagos aprobados que
hay en la base.

De los siete endpoints del módulo, sólo **tres** los invoca un tercero. Los
otros cuatro son plomería interna del circuito del navegador: nadie los
configura ni los escribe a mano.

| Endpoint | Lo llama | Se entera de la URL por |
| -------- | -------- | ----------------------- |
| **[`pagar`](pagar.md)** | El navegador del comprador | El link que arma el sistema origen |
| **[`webhook`](webhook.md)** | Mercado Pago, server-to-server | Se carga a mano en el panel de vendedor de MP |
| **[`suscripcionCrear`](suscripcionCrear.md)** | El servidor del sistema origen | Esta documentación |
| [`procesar`](procesar.md) | *interno* — el JS de la página de `pagar` | El `fetch()` del HTML |
| [`aprobado`](aprobado.md) · [`pendiente`](pendiente.md) · [`rechazado`](rechazado.md) | *interno* — el navegador, redirigido por Mercado Pago | Las `back_urls` de la preferencia |

> **"Interno" es por integración, no por exposición.** Los cuatro son URLs
> públicas y sin autenticación, alcanzables desde internet por cualquiera. Por
> eso [`aprobado`](aprobado.md) no escribe el estado del pago: si lo hiciera,
> cualquiera acreditaría una factura abriendo esa URL con el
> `external_reference` correcto. Este webhook sí escribe, y por eso **nunca
> toma el dato del body** — lo relee de la API de Mercado Pago.

**Al migrar de `/v2/` a `/v4/` sólo hay que tocar esos tres.** Éste es el que
más fácil se olvida, porque no se cambia en el código sino en el panel de
vendedor de Mercado Pago, cuenta por cuenta.

## Autenticación

**Ninguna, y es deliberado.**

La notificación sólo dice *"algo cambió, andá a buscarlo"*. El dato real
**nunca se toma del body**: se relee de la API de Mercado Pago con el
`accessToken` de la cuenta. Alguien que invente un POST a esta URL sólo logra
que el servicio le pregunte a Mercado Pago por un id que no existe.

`mercadopagocuentas` tiene columnas `webhookKey` / `webhookKeyTesting` para la
firma `x-signature`, pero el legacy nunca las usó y el port tampoco: activarlas
ahora rechazaría las notificaciones de todas las cuentas que las tienen vacías.

## Por qué siempre contesta 200

Mercado Pago **reintenta** toda notificación que no cierre con 2xx. Si este
endpoint devolviera 500 porque el sistema origen está caído, Mercado Pago
reenviaría la misma notificación durante horas y cada reintento volvería a
procesar el mismo cobro.

Por eso todo lo que puede fallar hacia afuera (la imputación) es *best-effort*
y queda registrado en `mercadopagoregistros` en vez de romper la respuesta.

Los errores de **este servicio** sí quedan expuestos: el shutdown handler de
`_lib/log.php` los manda a `sucesos` (panel > Herramientas > Visor de sucesos,
origen `v4/mercadopago.webhook`).

## Contrato de respuesta

Sobre legacy, no el `{ok,data}` de la casa:

```json
{ "respuesta": { "codigo": 200, "mensaje": "OK" } }
```

| Código | Cuándo |
| ------ | ------ |
| 200 | POST procesado, GET (sonda), o `cta` desconocido. |
| 405 | Cualquier método que no sea GET ni POST. |

## Selector por `type`

Todas las notificaciones quedan en `mercadopagoregistros` **antes** de
procesarse: si el procesamiento revienta, la notificación cruda ya está
guardada y se puede reconstruir a mano.

| `type` | Qué es | Registros que deja |
| ------ | ------ | ------------------ |
| `payment` | Pago manual del botón | `P` |
| `subscription_preapproval` | Cambio de estado de una suscripción | `I`, `S` |
| `subscription_authorized_payment` | Débito recurrente | `I`, `D`, `I` |
| *(cualquier otro)* | No reconocido | `N` |

Tipos de `mercadopagoregistros.tipo`: `P` pago · `S` suscripción · `D` débito ·
`I` info · `N` no reconocido.

### `payment` — pago manual

Body típico:

```json
{ "action": "payment.created", "type": "payment", "data": { "id": "174292830468" } }
```

1. Registra la notificación cruda con tipo `P`.
2. `GET https://api.mercadopago.com/v1/payments/{data.id}` con el `accessToken`
   de la cuenta.
3. De esa respuesta salen `external_reference` (= id interno del pago) y
   `status`.
4. Actualiza la fila de `mercadopagopagos`:

   | `status` de Mercado Pago | `estado` |
   | ------------------------ | -------- |
   | `approved` | `A` |
   | *(cualquier otro)* | `R` |

   Además setea `finalizado`, `operacion`, `notificacion` y `propiedades`.
5. Si el pago quedó aprobado y la cuenta tiene `imputacion`, dispara el
   callback (ver abajo).

Si el `external_reference` **no matchea** ningún pago, se da de alta una fila
nueva en `mercadopagopagos` con la cuenta y las propiedades. Es el caso de un
cobro iniciado fuera del botón: link de pago, QR, o cobro manual desde la app de
Mercado Pago. Aparece en el listado de Pagos del panel, sin factura asociada.

> **El token es siempre `accessToken` (producción)**, aunque la cuenta esté en
> `modo='1'` (testing). Es así en el legacy y se mantiene: una cuenta de testing
> con este webhook no resuelve, y cambiarlo rompería las que hoy sí resuelven.

### `subscription_preapproval` — cambio de estado de una suscripción

1. Registra `I` (`Suscripción actualizada`) y `S` (notificación cruda).
2. Busca la suscripción por `data.id` = `mercadopagosuscripciones.uuid`.
3. Relee el preapproval con `GET /preapproval/{uuid}`, usando el `accessToken`
   de **la cuenta de la suscripción** (no la del `?cta=`).
4. Mapea la respuesta a las columnas y sella las marcas de tiempo **por
   transición**, no por estado final:

   | Transición | Columna que se sella |
   | ---------- | -------------------- |
   | `pending` → `authorized` | `iniciada` |
   | `authorized` → `paused` | `pausada` |
   | `paused` → `authorized` | `reactivada` |
   | `pending`/`authorized`/`paused` → `cancelled` | `finalizada` |

   Que sea por transición es lo que hace útiles esas columnas: una suscripción
   ya `authorized` que vuelve a notificar `authorized` no re-sella `iniciada`.
5. Dispara la imputación si la cuenta la tiene configurada.

### `subscription_authorized_payment` — débito recurrente

1. Registra `I` (`Débito registrado`), `D` (notificación cruda) y luego `I` con
   la respuesta de la API.
2. `GET https://api.mercadopago.com/authorized_payments/{data.id}`.
3. Da de alta la fila en `mercadopagodebitos`, mapeando el estado:

   | `payment.status` | `estado` |
   | ---------------- | -------- |
   | `approved` | `A` |
   | `rejected` | `R` |
   | `pending` | `P` |
   | `cancelled` | `C` |
   | `refunded` | `F` |
   | *(otro)* | `''` |

   La suscripción se resuelve por `preapproval_id` de la respuesta.
4. Dispara la imputación **sólo si el débito quedó aprobado**.

## Imputación — el callback al sistema origen

Cada cuenta tiene una URL `imputacion`. Cuando el webhook confirma un
movimiento, le pega un `GET`:

| Evento | Query |
| ------ | ----- |
| Pago aprobado | `?mod=pago&fac={factura}&ope={operacion}` |
| Suscripción actualizada | `?mod=suscripcion&sus={uuid de la suscripción}&ref=` |
| Débito aprobado | `?mod=debito&sus={uuid del débito}&ref={referencia}` |

Es el mecanismo por el que el sistema del comercio se entera de forma confiable
de que el movimiento ya está acreditado.

Es best-effort y con timeout: si el sistema origen está caído, se registra el
fallo en `mercadopagoregistros` y la respuesta al webhook sigue siendo 200. El
legacy usaba `file_get_contents()` sin timeout — un sistema origen que no
cerraba la conexión colgaba el worker de Apache hasta los 60 s del
`default_socket_timeout`.

### ⚠ Quirks heredados de la imputación

Las dos filas marcadas arriba están **mal**, y se replican a propósito porque
los sistemas origen ya consumen esos links tal cual. Cambiarlas es un cambio de
contrato hacia afuera, no una corrección interna.

1. **Suscripción: `ref` viaja vacío.** El legacy arma ese link con
   `$mMercadopagoDebito->referencia` — un objeto sin cargar en esa rama, cuya
   propiedad vale `''`. Debería ser la referencia de la *suscripción*.
2. **Débito: `sus` lleva el uuid del DÉBITO, no el de la suscripción.** Es la
   cadena aleatoria de 16 caracteres que se acaba de generar para la fila del
   débito. El nombre del parámetro sugiere otra cosa.

Ambos están marcados con `QUIRK LEGACY` en [`webhook.php`](webhook.php), con la
línea exacta que habría que cambiar si se decide corregirlos.

## Migración desde `/v2/mercadopago/webhook`

Cambiar la URL en el panel de vendedor de Mercado Pago:

```
https://api.databox.net.ar/v2/mercadopago/webhook?cta=<uuid>
                          ↓
https://api.databox.net.ar/v4/mercadopago/webhook?cta=<uuid>
```

> **Una cuenta, un webhook.** Mientras el legacy siga publicado, los dos
> microservicios escriben sobre las mismas filas. El webhook de una cuenta debe
> apuntar a **uno** de los dos, nunca a los dos: con ambos configurados, cada
> notificación se procesaría dos veces y la imputación se dispararía duplicada.

Una diferencia hacia afuera del contrato: **con `cta` desconocido ahora se
registra la notificación y se contesta 200.** El legacy seguía igual con una
cuenta vacía, le pegaba a Mercado Pago sin token, recibía un 401 y terminaba
insertando una fila en `mercadopagopagos` con `cuenta=0` y el error adentro de
`propiedades`.

Y una protección agregada: si la relectura del preapproval falla (401, 404,
timeout), **no se escribe**. En el legacy esa respuesta vacía pasaba igual por
`traducir()` y el `modificar()` borraba el `uuid` de la fila — la suscripción
quedaba huérfana y ninguna notificación posterior la volvía a encontrar.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagocuentas` | Lectura: `accessToken`, `imputacion`. |
| `mercadopagopagos` | Escritura: actualiza o da de alta. |
| `mercadopagosuscripciones` | Escritura: actualiza estado y marcas de tiempo. |
| `mercadopagodebitos` | Escritura: alta por cada débito. |
| `mercadopagoregistros` | Escritura: log crudo de todo. |
