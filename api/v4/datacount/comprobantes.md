# POST /v4/datacount/comprobantes

Da de alta un comprobante en `datacount_comprobantes` (+ sus renglones en
`datacount_comprobantes_renglones`) usando el **id de talonario** como unica
referencia estructural. `proyecto`, `empresa`, `tipo`, `punto` y `fiscal`
se heredan del talonario — el caller no los pisa.

## Autorizacion sincronica

Si el talonario es fiscal (`fiscal='1'`) y el comprobante queda en estado
`'2'` (default), el microservicio **autoriza el comprobante contra AFIP en
la misma request** delegando en la lib compartida
`cloud/api/lib/datacount_comprobantes_autorizar.php::dccAutAutorizar()` —
mismo canal que usa el cron batch y el boton "Autorizar" del ABM.

- **OK**: el comprobante queda en estado `'3'` con `cae`, `cae_vto`,
  `serie` (= `cbte_nro`), `autorizado` y `caeres` seteados; la response
  HTTP 201 los incluye en `data`.
- **Error**: el comprobante queda persistido en estado `'2'` con el
  mensaje en `caeres` como evidencia. La response es HTTP 422 con el
  mensaje + el id/uuid para que el caller pueda referenciarlo despues.
  Si el error es **permanente** (rechazo AFIP, cert vencido, empresa mal
  configurada, etc.), ademas se detiene el motor automatico
  (`parametros.datacount.comprobantes.autorizar='0'`) — un fallo
  sistemico romperia igual al cron del proximo minuto. Los transitorios
  (AFIP/Apache caidos, timeouts) NO detienen el motor: el cron los
  reintenta solo en el proximo tick.

Cuando NO se intenta autorizar (talonario no fiscal, o el caller pisa el
`estado` con `'1'` para dejarlo en borrador), el comprobante se devuelve
tal como fue creado, con `cae`/`cae_vto`/`cbte_nro`/etc. en `null`.

## Webhook post-autorizacion

Si el caller pasa `webhook_url` en el body, el microservicio hace un `POST`
JSON a esa URL **apenas obtiene el CAE**. Esto evita que el caller tenga
que pollear el ABM para conciliar el resultado.

### Request que recibe el receptor

- **Metodo**: `POST`
- **URL**: la que se paso en `webhook_url` (tal cual, con query string y
  path que hubiera).
- **Headers**:
  - `Content-Type: application/json; charset=utf-8`
  - `Accept: application/json`
  - `User-Agent: databox/datacount-comprobantes-webhook`
- **Body**: JSON con el mismo shape que el `data` de la response 201 del
  endpoint (mismo objeto, mismos nombres de campos, mismos tipos). El
  receptor no tiene que distinguir si el disparo vino del alta online o
  del cron de retry — el shape es identico.

Campos del body JSON:

| Campo             | Tipo                    | Detalle |
|-------------------|-------------------------|---------|
| `id`              | int                     | Id interno del comprobante en `datacount_comprobantes`. |
| `uuid`            | string                  | UUID publico del comprobante. |
| `talonario`       | int \| null             | Id del talonario. |
| `proyecto`        | int \| null             | Heredado del talonario. |
| `empresa`         | int \| null             | Heredado del talonario. |
| `tipo`            | string(2)               | FA/FB/FC/FM/NA/NB/NC/NM. |
| `punto`           | int \| null             | Punto de venta. |
| `fiscal`          | string(1)               | Siempre `'1'` cuando llega webhook (los no-fiscales no autorizan). |
| `concepto`        | int \| null             | 1=Productos, 2=Servicios, 3=Prod+Serv. |
| `emision`         | date (`YYYY-MM-DD`)     | Fecha de emision. |
| `vencimiento`     | date (`YYYY-MM-DD`)     | Fecha de vencimiento. |
| `neto`            | string (decimal 2)      | Neto gravado. Formato `"100.00"`. |
| `iva`             | string (decimal 2)      | IVA total. Formato `"21.00"`. |
| `total`           | string (decimal 2)      | Total. Formato `"121.00"`. |
| `estado`          | string(1)               | Siempre `'3'` (Autorizado) cuando llega webhook. |
| `registrado`      | datetime                | Cuando se dio de alta en la BD (`YYYY-MM-DD HH:MM:SS`). |
| `webhook_url`     | string                  | Eco de la URL configurada. |
| `webhook_estado`  | string                  | Al momento del disparo puede ser `'pendiente'` (primer intento) o `'pendiente'` en reintentos previos fallidos. **No es indicativo del intento actual** — el estado final se actualiza recien despues de recibir la response. |
| `cae`             | string(14)              | CAE otorgado por AFIP. |
| `cae_vto`         | string (`YYYYMMDD`)     | Vencimiento del CAE (formato AFIP, sin guiones). |
| `cbte_nro`        | int                     | Numero de comprobante autorizado por AFIP. |
| `serie`           | int                     | Mismo valor que `cbte_nro`. |
| `autorizado`      | datetime                | Cuando se registro el CAE (`YYYY-MM-DD HH:MM:SS`). |
| `caeres`          | string                  | Mensaje descriptivo, ej. `"OK CAE 76234567890123 (vto 20260811)"`. |

Ejemplo del body que llega al receptor:

```json
{
  "id": 24682,
  "uuid": "3a1b7c9d02",
  "talonario": 42,
  "proyecto": 5,
  "empresa": 12,
  "tipo": "FA",
  "punto": 1,
  "fiscal": "1",
  "concepto": 3,
  "emision": "2026-08-01",
  "vencimiento": "2026-08-08",
  "neto": "100.00",
  "iva": "21.00",
  "total": "121.00",
  "estado": "3",
  "registrado": "2026-08-01 10:15:34",
  "webhook_url": "https://mi-app.ejemplo.com/hooks/facturas?token=abc123",
  "webhook_estado": "pendiente",
  "cae": "76234567890123",
  "cae_vto": "20260811",
  "cbte_nro": 4271,
  "serie": 4271,
  "autorizado": "2026-08-01 10:15:35",
  "caeres": "OK CAE 76234567890123 (vto 20260811)"
}
```

### Response que espera el receptor

- **Cualquier HTTP 2xx** marca el webhook como `'completado'` y el
  comprobante deja de aparecer en el pool de reintentos.
- **Cualquier otra cosa** (HTTP != 2xx, timeout, DNS, cURL error) deja
  `webhook_estado` en `'pendiente'` para que el cron de retry lo levante
  en el proximo tick.
- **El body de la response se descarta** — solo importa el status code.
  El receptor puede devolver `204 No Content` o un JSON con lo que sea.

### Estado del webhook

La columna `webhook_estado` de `datacount_comprobantes` refleja el estado:

- `'pendiente'` — se cargo `webhook_url` pero todavia no se disparo o el
  disparo fallo (timeout, HTTP != 2xx, cURL error, comprobante sin
  autorizar aun).
- `'completado'` — el receptor devolvio HTTP 2xx.
- `NULL` — el caller no configuro webhook.

### Comportamiento

- **Best-effort**: si el POST al webhook falla, el comprobante ya quedo
  autorizado y la response HTTP al caller original sigue siendo 201. El
  fallo se registra como suceso `'alerta'` con el motivo y
  `webhook_estado` queda en `'pendiente'`.
- **Retry automatico**: los `'pendiente'` los levanta el cron
  `cloud/jobs/datacount_comprobantes_notificar.php`, que reintenta el POST
  cada corrida hasta que el receptor devuelva 2xx. Esto cubre tambien los
  comprobantes autorizados por el cron `datacount_comprobantes_autorizar`
  o por el boton "Autorizar" del ABM (que no pasan por el disparo online
  del v4).
- **Idempotencia**: el receptor puede recibir el mismo POST varias veces
  para el mismo comprobante (cada reintento del cron dispara uno). Usar
  `id` o `uuid` como clave de deduplicacion.
- **Timeouts**: conexion 3s, total 8s. Sigue hasta 3 redirects.
- **No hay auth** en el POST al webhook — el caller debe usar una URL con
  token/secret en el path o query si necesita autenticar el origen.

## Auth

Bearer con `apikey` de la tabla `aplicaciones` (mismo esquema que el resto
de los microservicios `/v4`).

```
Authorization: Bearer <apikey>
```

## Request

`Content-Type: application/json`

| Campo           | Tipo         | Obligatorio | Detalle |
|-----------------|--------------|-------------|---------|
| `talonario`     | int          | si          | id de `datacount_talonarios`. De aca salen `proyecto`, `empresa`, `tipo`, `punto`, `fiscal`. |
| `renglones`     | array        | si          | Al menos 1 item. Cada item: `{ cantidad, unitario, iva, detalle?, articulo?, orden?, estado? }`. `iva` es la alicuota en % (ej. `21`). |
| `cliente`       | int          | no          | id interno del cliente. |
| `razon`         | string(250)  | no          | Razon social. |
| `condicion`     | string(2)    | no          | Condicion IVA. |
| `cuit`          | string(50)   | no          | CUIT / DNI. |
| `domicilio`     | string(250)  | no          | |
| `correo`        | string(100)  | no          | |
| `celular`       | string(100)  | no          | |
| `emision`       | date         | no          | `YYYY-MM-DD`. Default: hoy (America/Argentina/Buenos_Aires). |
| `vencimiento`   | date         | no          | `YYYY-MM-DD`. Default: hoy + 7. |
| `concepto`      | int (1/2/3)  | no          | AFIP: 1=Productos, 2=Servicios, 3=Prod+Serv. Default: 3. |
| `asociado`      | int          | no          | Id del comprobante que se acredita. **Obligatorio para notas de credito** (NA/NB/NC/NM): AFIP exige `cbtes_asoc` y se arma con el `tipo`/`punto`/`serie` del asociado, que debe estar autorizado (`estado='3'`, fiscal, factura). |
| `contrato`      | int          | no          | |
| `medio`         | int          | no          | |
| `observaciones` | string(2000) | no          | |
| `comentarios`   | string(2000) | no          | |
| `estado`        | string(1)    | no          | Default: `'2'` (Pendiente) — dispara la autorizacion sincronica. Pasar `'1'` (Preparacion) para dejarlo como borrador sin llamar a AFIP. |
| `webhook_url`   | string(500)  | no          | URL a la que hacer POST JSON cuando el comprobante logre autorizarse contra AFIP (ver seccion "Webhook post-autorizacion"). Si viene, `webhook_estado` arranca en `'pendiente'`. |

`neto`, `iva` y `total` se **recalculan siempre server-side** desde
`renglones` — cualquier valor en el body para esas claves se ignora.

## Response

### 201 Created — autorizado OK

```json
{
  "ok": true,
  "data": {
    "id": 24682,
    "uuid": "3a1b7c9d02",
    "talonario": 42,
    "proyecto": 5,
    "empresa": 12,
    "tipo": "FA",
    "punto": 1,
    "fiscal": "1",
    "concepto": 3,
    "emision": "2026-08-01",
    "vencimiento": "2026-08-08",
    "neto": "100.00",
    "iva": "21.00",
    "total": "121.00",
    "estado": "3",
    "registrado": "2026-08-01 10:15:34",
    "webhook_url": "https://mi-app.ejemplo.com/hooks/facturas",
    "webhook_estado": "completado",
    "cae": "76234567890123",
    "cae_vto": "20260811",
    "cbte_nro": 4271,
    "serie": 4271,
    "autorizado": "2026-08-01 10:15:35",
    "caeres": "OK CAE 76234567890123 (vto 20260811)"
  }
}
```

Si el caller no mando `webhook_url`, ambos campos `webhook_url` y
`webhook_estado` salen en `null`. Si mando `webhook_url` pero el POST al
receptor fallo (timeout, HTTP != 2xx), la response al caller original
sigue siendo 201 pero `webhook_estado` queda en `'pendiente'`.

### 201 Created — sin autorizacion (no fiscal o estado != '2')

Los campos `cae`, `cae_vto`, `cbte_nro`, `serie`, `autorizado`, `caeres`
salen en `null`, `estado` refleja lo que se pidio (default `'2'`). El
webhook **no se dispara** en este caso — se dispara sola cuando el
comprobante obtiene el CAE, cosa que aca no paso.

### 422 Unprocessable Entity — autorizacion AFIP fallo

El comprobante **quedo creado** en estado `'2'`; `data` trae el mismo
payload que la 201, con `caeres` = mensaje del error. `fuente` indica si
la falla fue en la validacion previa (`'validacion'`) o en el
microservicio ARCA / AFIP (`'microservicio'`).

```json
{
  "ok": false,
  "error": "(10015) Fecha del comprobante posterior a la fecha de proceso",
  "fuente": "microservicio",
  "data": {
    "id": 24682,
    "uuid": "3a1b7c9d02",
    "estado": "2",
    "caeres": "(10015) Fecha del comprobante posterior a la fecha de proceso",
    "cae": null,
    "cae_vto": null,
    "cbte_nro": null,
    "...": "..."
  }
}
```

## Errores

| Codigo | Cuando |
|--------|--------|
| 400    | Falta `talonario`, `renglones` vacio, `concepto` invalido. |
| 401    | Bearer ausente / apikey desconocida / aplicacion deshabilitada. |
| 404    | Talonario inexistente. |
| 405    | Metodo distinto de POST. |
| 422    | Comprobante creado pero autorizacion AFIP fallo (transitorio o permanente). Ver `data.id` para reintentar. Un error permanente **detiene el motor automatico** (`parametros.datacount.comprobantes.autorizar='0'`). |
| 500    | Excepcion no controlada (se propaga el mensaje). |

## Ejemplo

```bash
curl -X POST https://api.databox.net.ar/v4/datacount/comprobantes \
     -H "Authorization: Bearer XXXXXXXX" \
     -H "Content-Type: application/json" \
     -d '{
       "talonario": 42,
       "razon": "ACME SRL",
       "cuit": "20111111112",
       "condicion": "RI",
       "concepto": 2,
       "webhook_url": "https://mi-app.ejemplo.com/hooks/facturas?token=abc123",
       "renglones": [
         {"cantidad": 1, "detalle": "Servicio mensual", "unitario": 100, "iva": 21}
       ]
     }'
```
