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
(iniciado). El `id` de esa fila es el `external_reference` que
[`procesar`](procesar.md) le declara a Mercado Pago, y es lo único que después
permite correlacionar la notificación del [`webhook`](webhook.md) con la
factura del sistema origen.

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
[`procesar`](procesar.md) necesita para firmar la preferencia.

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
  [`aprobado`](aprobado.md) pierde el `pagoId`.

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
