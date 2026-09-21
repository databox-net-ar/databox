# `/v4/mercadopago/suscripcionCrear`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/mercadopago/suscripcionCrear.md>

Alta de una **suscripción** (preapproval de Mercado Pago) desde un sistema
externo. Es el único endpoint del microservicio pensado para que lo llame un
servidor y no un navegador — el resto son páginas, redirects o el webhook.

```
POST https://api.databox.net.ar/v4/mercadopago/suscripcionCrear
```

> El nombre del archivo va en **camelCase** a propósito. El resto del árbol v4
> usa minúsculas (`mensajes`, `etiquetas`), pero la URL pública del legacy es
> `/v2/mercadopago/suscripcionCrear` y en Linux las rutas distinguen
> mayúsculas. Renombrarlo obligaría a cada cliente a cambiar más que el número
> de versión, que es justo lo que este port quiere evitar.

## Quién lo llama

**Superficie externa.** Lo llama el servidor del sistema origen, con apikey.

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

**Éste es el único endpoint del módulo que pide apikey**, y no es casualidad:
es el único al que lo llama un servidor, o sea lo único que puede guardar un
secreto. A [`pagar`](pagar.md) lo abre un navegador y a [`webhook`](webhook.md)
lo llama Mercado Pago; ninguno de los dos puede sostener una credencial.

**Al migrar de `/v2/` a `/v4/` sólo hay que tocar esos tres**, y éste sólo si
el cliente da de alta suscripciones.

## Autenticación

Acepta **las dos formas**. Un cliente que hoy llama al legacy cambia `/v2/` por
`/v4/` y nada más; cuando quiera pasarse a Bearer, lo hace sin esperar un
deploy de este lado.

```
POST /v4/mercadopago/suscripcionCrear?apikey=<apikey>     ← forma legacy
Authorization: Bearer <apikey>                            ← forma del stack nuevo
```

Se valida contra `aplicaciones.apikey` con `habilitada='1'`, la misma tabla que
el resto del stack. Cualquier apikey habilitada pasa — no hay scope por
endpoint. Cada llamada incrementa `aplicaciones.usos` (el legacy no lo hacía;
se agrega para que el ABM de aplicaciones muestre actividad igual que con el
resto de los endpoints v4).

> Cuando la apikey viaja en la query string, se **tapa** antes de que el
> registro de errores escriba la URI en `sucesos.detalle`. Sin eso, cada 4xx
> dejaría la credencial escrita en el Visor de sucesos del panel.

## Contrato de respuesta

**Éste no es el `{ok,data}` de la casa** — es el del legacy, porque los
clientes ya lo parsean:

- **200** → el objeto de la suscripción **pelado, sin sobre**.
- **≠ 200** → `{"respuesta":{"codigo":603,"mensaje":"Falta cuenta"}}`

```json
{
  "uuid": "2c938084726fca480172750000000000",
  "cuenta": "114",
  "nombre": "Juan Pérez",
  "celular": "2644123456",
  "correo": "juan@ejemplo.com",
  "referencia": "SOC-00412",
  "concepto": "Abono mensual",
  "monto": "4500.00",
  "periodo": "months",
  "frecuencia": "1",
  "pruebaPeriodo": "months",
  "pruebaFrecuencia": "0",
  "destino": "https://mi-sistema/suscripcion/ok"
}
```

Las trece claves salen siempre, en ese orden, y **todos los valores viajan como
string** — incluidos `cuenta` y `monto`. Es como los devuelve el legacy y hay
clientes que comparan contra strings.

## Body

| Campo | Obligatorio | Descripción |
| ----- | :---------: | ----------- |
| `cuenta` | sí | UUID de la cuenta en `mercadopagocuentas`. |
| `correo` | sí | Email del pagador. Lo exige Mercado Pago. |
| `destino` | sí | `back_url`: a dónde vuelve el suscriptor después del alta. |
| `nombre` | no | Nombre del suscriptor. Se guarda local, no viaja a Mercado Pago. |
| `celular` | no | Ídem. |
| `referencia` | no | `external_reference` interno. Vuelve en la imputación. |
| `concepto` | no | Motivo que ve el pagador (`reason`). |
| `monto` | no | Monto por ciclo, en ARS. |
| `periodo` | no | `days` \| `months`. Default `months`. |
| `frecuencia` | no | Cada cuántos `periodo` cobrar. Default `1`. |
| `pruebaPeriodo` | no | Free trial: unidad. Default `months`. |
| `pruebaFrecuencia` | no | Free trial: cantidad. Default `0` = sin trial. |

Ejemplo:

```bash
curl -X POST 'https://api.databox.net.ar/v4/mercadopago/suscripcionCrear' \
  -H 'Authorization: Bearer <apikey>' \
  -H 'Content-Type: application/json' \
  -d '{
        "cuenta": "abc123",
        "correo": "juan@ejemplo.com",
        "destino": "https://mi-sistema/suscripcion/ok",
        "nombre": "Juan Pérez",
        "referencia": "SOC-00412",
        "concepto": "Abono mensual",
        "monto": 4500,
        "periodo": "months",
        "frecuencia": 1
      }'
```

## Códigos

| Código | Mensaje | Cuándo |
| ------ | ------- | ------ |
| 200 | — | Alta procesada. Devuelve el objeto pelado. |
| 405 | `Metodo no permitido` | No es POST. |
| 601 | `Falta apikey` | Ni `?apikey=` ni Bearer. |
| 602 | `Apikey invalida` | Desconocida o `habilitada != '1'`. |
| 603 | `Falta cuenta` | Falta `cuenta`, o el uuid no existe. |
| 604 | `Falta correo` | Falta `correo`. |
| 610 | `Falta destino` | Falta `destino`. |

> **Los códigos 6xx no son códigos HTTP válidos.** El legacy los escribe igual
> en el status line con `header('HTTP/1.1 603 ')` y Apache los normaliza a
> **500** en la línea de estado, dejando el código real sólo en
> `respuesta.codigo`. El port reproduce exactamente eso — verificado contra el
> contenedor legacy. **Leé `respuesta.codigo`, no el status HTTP.**
>
> El orden de validación también es el del legacy: primero la apikey, después
> el método. Un GET sin apikey devuelve 601, no 405.

## Qué hace internamente

1. Registra `I` (`Suscripción registrada`) en `mercadopagoregistros`.
2. `POST https://api.mercadopago.com/preapproval` con el `accessToken` de la
   cuenta.
3. Mapea la respuesta a las columnas y da de alta la fila en
   `mercadopagosuscripciones`.
4. Relee la fila y devuelve las trece claves.

`free_trial` va en `null` cuando `pruebaFrecuencia` es `0` — así lo espera
Mercado Pago; mandar `{"frequency":0}` da 400.

> **El token es siempre `accessToken` (producción)**, aunque la cuenta esté en
> `modo='1'` (testing). Es así en el legacy y se mantiene.

A partir del alta, los cambios de estado de la suscripción (autorizada,
pausada, reactivada, cancelada) y sus débitos llegan por
[`webhook`](webhook.md).

## ⚠ Quirk heredado: un alta rechazada igual devuelve 200

Si Mercado Pago rechaza el preapproval, la respuesta de error no tiene `id`: la
fila se inserta con `uuid` vacío y estado `''`, y el endpoint contesta **200
con los campos en blanco**.

Se replica porque hay clientes que ya conviven con eso. **La forma de
detectarlo del lado del cliente es la de siempre: si `uuid` viene vacío, el alta
no se hizo.**

El motivo real queda en `mercadopagoregistros` — una fila `N` con la respuesta
cruda de Mercado Pago y una `I` que nombra la cuenta y el correo.

## Migración desde `/v2/mercadopago/suscripcionCrear`

Cambiar `v2` por `v4` en la URL. Mismo body, mismos códigos, misma respuesta,
misma apikey por query string.

Única diferencia hacia afuera: un `cuenta` que **existe como parámetro pero no
en la base** ahora devuelve 603 en vez de seguir adelante e insertar una
suscripción inservible. 603 ya significa "problema con `cuenta`", así que no le
agrega un caso nuevo a ningún cliente.

## Tablas

| Tabla | Rol |
| ----- | --- |
| `aplicaciones` | Lectura: validación de apikey. Escritura: contador `usos`. |
| `mercadopagocuentas` | Lectura: `accessToken`. |
| `mercadopagosuscripciones` | Escritura: alta de la suscripción. |
| `mercadopagoregistros` | Escritura: log del alta y de los rechazos. |
