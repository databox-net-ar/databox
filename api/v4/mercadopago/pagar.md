# `/v4/mercadopago/pagar`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/pagar.md>

Página del **botón de pago** (Checkout Pro de Mercado Pago). Es la puerta de
entrada del circuito de cobro manual: el sistema origen manda al comprador acá
con los datos de la factura y, cuando termina, Mercado Pago lo devuelve al
sistema origen.

Es el único endpoint del microservicio que le contesta **HTML a una persona**.
Todos los demás son JSON o redirects.

Se accede vía el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extensión** — el `.htaccess` de `api/`
la resuelve contra el `.php` correspondiente:

```
GET https://api.databox.net.ar/v4/mercadopago/pagar
```

## Quién lo llama

**Superficie externa.** Lo abre el navegador del comprador, desde un link que
arma el sistema origen.

El microservicio publica siete endpoints, pero **la superficie de integración
son tres** — los únicos que invoca un tercero:

| Endpoint | Lo llama | Se entera de la URL por |
| -------- | -------- | ----------------------- |
| **[`pagar`](pagar.md)** | El navegador del comprador | El link que arma el sistema origen |
| **[`webhook`](webhook.md)** | Mercado Pago, server-to-server | Se carga a mano en el panel de vendedor de MP |
| **[`suscripcionCrear`](suscripcionCrear.md)** | El servidor del sistema origen | Esta documentación |

Los otros cuatro (`procesar`, `aprobado`, `pendiente`, `rechazado`) son
plomería del circuito del navegador: nadie los llama ni los configura a mano, y
**no figuran en el navegador de Documentación** — están marcados `@interno` en
su `.php`. Lo que hay que saber de ellos está más abajo, en
[El circuito interno](#el-circuito-interno).

**Al migrar de `/v2/` a `/v4/` sólo hay que tocar esos tres.** Los cuatro
internos no requieren acción de nadie: las `back_urls` se regeneran en cada
preferencia y el `fetch()` del HTML ya apunta a `/v4/`.

## Autenticación

**Ninguna.** Lo abre el navegador del comprador desde un link que le mandó el
sistema origen; pedirle una apikey sería imprimirla en el HTML.

Lo que hace las veces de credencial es el `cta` (uuid de la cuenta) más los
datos de la factura. Quien tenga el link puede abrir la página y generar un
intento de pago — no puede cobrar, ni leer datos de otra cuenta, ni acreditar
nada.

## Parámetros (query string)

| Param | Obligatorio | Descripción |
| ----- | :---------: | ----------- |
| `cta` | sí | UUID de la cuenta en `mercadopagocuentas`. Determina logo, nombre y credenciales. |
| `fct` | no | ID interno de la factura del sistema origen. Vuelve en la imputación como `fac=`. |
| `mnt` | no | Monto a cobrar. |
| `cpt` | no | Concepto / descripción que ve el comprador. |
| `ret` | sí | URL absoluta (`http://` o `https://`) a la que se devuelve al comprador al terminar. |

Ejemplo:

```
https://api.databox.net.ar/v4/mercadopago/pagar?cta=abc123&fct=24070&mnt=4891.58&cpt=Factura+001-275587&ret=https://mi-sistema/ok
```

## Respuestas

| Código | Cuerpo |
| ------ | ------ |
| 200 | La página HTML del botón. |
| 400 | `Parámetro "ret" inválido: debe ser una URL absoluta (https://...).` |
| 404 | `Cuenta de Mercado Pago no encontrada. Revisá el parámetro "cta".` |

Los errores son texto legible, no JSON: del otro lado hay una persona mirando
el navegador.

## Este GET escribe

Cada visita **da de alta una fila en `mercadopagopagos`** con estado `'I'`
(iniciado). El `id` de esa fila es el `external_reference` que `procesar` le
declara a Mercado Pago, y es lo único que después permite correlacionar la
notificación del [`webhook`](webhook.md) con la factura del sistema origen.

Va contra la regla de la casa de que un GET no modifica. Se mantiene a
propósito: es la firma del microservicio legacy y cambiarla obligaría a tocar
todos los sistemas que hoy linkean acá.

Efecto práctico: **recargar la página crea otro intento de pago**, y la enorme
mayoría de los intentos nunca avanza: sobre ~2.000 filas por mes, unas 800
terminan aprobadas. El resto queda en `'I'`.

Esas filas abandonadas **no las limpia este microservicio**: las barre un job
horario del legacy, `databox-api/robot/mercadopagoAbandonar.php`
(`30 * * * *` en `databox-api/linux/crontab`), que pasa a `'B'` todo lo que
quedó en `'I'` entre hace 24 h y hace 1 h:

```sql
update mercadopagopagos set estado='B'
 where iniciado between <ayer> and <hace 1 hora> and estado='I'
```

Por eso en la base hay 27.404 filas en `'B'` y **una sola** en `'I'`. Ese job
vive fuera de `v2/mercadopago/` y **no está portado a v4** — sigue corriendo
desde el contenedor legacy vía `https://api.databox.net.ar/robot/mercadopagoAbandonar`,
que nginx rutea al legacy. Si algún día se apaga el legacy, hay que portarlo
como tarea del Programador de tareas del panel.

Estados de `mercadopagopagos`: `I` iniciado · `A` aprobado · `R` rechazado ·
`P` pendiente · `B` abandonado (lo pone el job, no el microservicio).

Defaults de la fila nueva:

| Columna | Valor |
| ------- | ----- |
| `uuid` | 16 caracteres `[0-9A-Za-z]` al azar (no es un UUID; ver [`_lib/mercadopago.php`](_lib/mercadopago.php)) |
| `iniciado` | ahora |
| `finalizado` | `1500-01-01 00:00:00` (centinela de "todavía no") |
| `estado` | `'I'` |

## Sesión y credenciales

La página guarda en la sesión PHP (`pagoId`, `cuentaId`, `facturaId`,
`facturaMonto`, `cuentaNombre`, `cuentaPublicKey`, `cuentaAccessToken`) lo que
`procesar` necesita para firmar la preferencia.

El par de credenciales sale del `modo` de la cuenta:

| `mercadopagocuentas.modo` | Credenciales |
| ------------------------- | ------------ |
| `'1'` — testing | `publicKeyTesting` + `accessTokenTesting` |
| `'2'` — producción | `publicKey` + `accessToken` |

**Sólo la `publicKey` baja al navegador** — es pública por definición,
identifica al vendedor en el SDK JS. El `accessToken` se queda en la sesión y
lo usa `procesar` del lado del servidor.

La cookie de sesión se llama `DBXMP` (no `PHPSESSID`), con path
`/v4/mercadopago/` y `SameSite=Lax`. Los tres detalles importan:

- **Nombre propio**: el panel cloud, el legacy y este microservicio comparten
  dominio de segundo nivel; con `PHPSESSID` una sesión pisaría a la otra.
- **`SameSite=Lax` y no `Strict`**: el comprador vuelve del checkout por una
  navegación cross-site. Con `Strict` la cookie no viaja en ese retorno y
  `aprobado` pierde el `pagoId`.

## El circuito interno

Los cuatro endpoints que siguen no tienen documentación propia y no aparecen en
el navegador: no son superficie de integración, nadie los llama ni los
configura. Esto es lo que hay que saber si algo del circuito falla.

```
 sistema origen ──> /pagar ──(fetch)──> /procesar ──> crea la Preference
                                                            │
                                                            ▼
                                                  Checkout de Mercado Pago
                                                            │
                          ┌─────────────────────────────────┼──────────────────┐
                          ▼                                 ▼                  ▼
                     /aprobado                         /pendiente         /rechazado
                          │                                 │                  │
                          └────────── redirect al `ret` del sistema origen ────┘

 en paralelo y por su cuenta:  Mercado Pago ──POST──> /webhook   (acredita)
```

### `POST /procesar`

Lo llama el `fetch()` del HTML que sirve esta página. Crea la Preference con
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

Devuelve `{"id":"<preference_id>"}` pelado. **No pide apikey y está bien**: lo
llama un navegador, y una apikey acá sería una apikey impresa en el HTML. Lo
que autoriza la llamada es la sesión que creó `pagar` — de ahí salen el
`accessToken` y el `pagoId`. El body sólo aporta título, cantidad y precio; el
`external_reference` sale **siempre** de la sesión, nunca del body.

| Código | Cuerpo | Cuándo |
| ------ | ------ | ------ |
| 200 | `{"id":"..."}` | Preferencia creada. |
| 405 | `{"id":null,"error":"Metodo no permitido"}` | No es POST. |
| 409 | `{"id":null,"error":"Sesión de pago no iniciada o vencida..."}` | No hay sesión de `pagar`. |
| 502 | `{"id":null,"error":"<mensaje de Mercado Pago>"}` | Mercado Pago rechazó la preferencia. |

Cuando la rechaza, el cuerpo completo de Mercado Pago queda en
`mercadopagoregistros` con tipo `'I'` — ahí está el motivo real
(`auto_return invalid`, `invalid access token`, monto fuera de rango).

**Base pública.** Las `back_urls` tienen que ser alcanzables desde afuera: con
`auto_return=approved`, una `success` que Mercado Pago no resuelve hace que
rechace la preferencia entera. Se resuelve en este orden:

1. `MP_PUBLIC_BASE` del `.env` — para apuntar a un túnel en desarrollo.
2. `https://api.databox.net.ar` si `APP_ENV=production`.
3. `http://localhost:8114` en desarrollo.

En desarrollo, sin `MP_PUBLIC_BASE`, el HTML del botón se ve pero el checkout
real no completa. Es lo esperado.

### `GET /aprobado` · `/pendiente` · `/rechazado`

Son **redirects del navegador**, no llamadas server-to-server: Mercado Pago
manda al comprador de vuelta con el resultado en la query string
(`collection_id`, `payment_id`, `status`, `external_reference`,
`merchant_order_id`, `payment_type`, `site_id`, `processing_mode`,
`collection_status`, `merchant_account_id`).

Cualquiera puede abrirlas a mano con los parámetros que quiera, **así que no
son fuente de verdad de nada**:

| Endpoint | Escribe | Por qué |
| -------- | ------- | ------- |
| `aprobado` | **nada** | Marcar `'A'` acá dejaría que el navegador declare un cobro: cualquiera acreditaría una factura abriendo la URL con el `external_reference` correcto. Quien marca `'A'` es [`webhook`](webhook.md), que verifica contra la API de Mercado Pago. |
| `pendiente` | `estado='P'`, `operacion` | `'P'` no acredita plata; si la notificación real es otra, el webhook la corrige. |
| `rechazado` | `estado='R'`, `operacion` | Ídem. Un pago que el comprador reintenta y aprueba termina en `'A'` por el webhook. |

Los tres identifican el pago por `external_reference` y, si no vino, por el
`pagoId` de la sesión. Ese respaldo importa: en el `auto_return` de un pago
aprobado, Mercado Pago no siempre incluye `external_reference`. Por eso
`aprobado` **no limpia la sesión** y los otros dos sí — una asimetría del
legacy que se respetó.

Respuestas: `302` al `ret` del pago; `404` `Error inesperado. Inténtelo
nuevamente mas tarde.` si no se identifica el pago; `500` si la fila no tiene
un `retorno` válido. Texto legible, no JSON: del otro lado hay una persona.

### ⚠ Quirk heredado: los parámetros de retorno no se guardan

El legacy arma un JSON con esos diez parámetros y lo asigna a
`$mMercadopagoPago->respuesta`. **Esa columna no existe** en
`mercadopagopagos`, y el `modificar()` del framework sólo escribe las columnas
declaradas en el modelo: la asignación se descarta en silencio. O sea que hoy,
en producción, esos parámetros se pierden.

El port replica el comportamiento observable —se escriben `operacion` y
`estado`, nada más— en vez de "arreglarlo", porque arreglarlo implica decidir
dónde guardarlos y eso cambia lo que ve el panel. Si se quieren recuperar, lo
natural es una fila en `mercadopagoregistros` con tipo `'I'`: una línea en
`mpRetornoCerrar()` ([`_lib/retorno.php`](_lib/retorno.php)).

No es una pérdida grave: el payload completo y verificado del pago lo trae
[`webhook`](webhook.md) y queda en `mercadopagopagos.propiedades`.

## Migración desde `/v2/mercadopago/pagar`

Cambiar `v2` por `v4` en el link. Mismos parámetros, mismo HTML, mismo
comportamiento.

Dos diferencias, ambas hacia afuera del contrato:

1. **`cta` desconocido ahora corta con 404.** El legacy armaba igual la página
   (sin logo ni nombre) y dejaba un pago con `cuenta=0` que nunca se podía
   imputar. Un `cta` válido se comporta exactamente igual que antes.
2. **El HTML escapa lo que interpola.** El legacy inyecta `cpt` —que viene de
   la query string— crudo en el HTML y en el JS: un XSS reflejado a un link de
   distancia. El texto que se ve es idéntico; lo único que cambia es que un
   `<script>` en el concepto ahora se muestra como texto.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `mercadopagocuentas` | Lectura: logo, nombre, credenciales, modo. |
| `mercadopagopagos` | Escritura: una fila por visita. |
