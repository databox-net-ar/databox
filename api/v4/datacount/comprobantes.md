# POST /v4/datacount/comprobantes

Da de alta un comprobante en `datacount_comprobantes` (+ sus renglones en
`datacount_comprobantes_renglones`) usando el **id de talonario** como unica
referencia estructural. `proyecto`, `empresa`, `tipo`, `punto` y `fiscal`
se heredan del talonario — el caller no los pisa.

## Flujo asincronico

**El endpoint no llama a AFIP.** Solo persiste el comprobante en estado
`'2'` (Pendiente) y devuelve `201` inmediatamente. La autorizacion y la
notificacion viven en dos cron jobs separados:

1. **Alta (este endpoint)** — inserta el comprobante en estado `'2'`. Si el
   caller mando `webhook_url`, ademas queda con `webhook_estado='pendiente'`.
   La response `201` trae el snapshot recien creado con `cae`, `cae_vto`,
   `cbte_nro`, `serie`, `autorizado` y `caeres` en `null` — todavia no se
   autorizo.
2. **Autorizacion** — el cron `cloud/jobs/datacount_comprobantes_autorizar.php`
   levanta los `fiscal='1' AND estado='2'` y los autoriza contra AFIP. Si
   sale OK, deja el comprobante en `'3'` con `cae`, `cae_vto`, `serie` y
   `autorizado` seteados. Si sale mal, aplica su politica de reintento /
   circuit breaker segun corresponda (transitorio vs permanente).
3. **Notificacion** — el cron `cloud/jobs/datacount_comprobantes_notificar.php`
   levanta los `estado='3' AND webhook_estado='pendiente' AND webhook_url IS NOT NULL`
   y hace `POST` JSON al `webhook_url` del caller con el snapshot final. Sigue
   reintentando hasta que el receptor devuelva `2xx`.

El caller **no espera CAE en la response de este endpoint** — para conocer
el resultado final debe recibir el webhook o consultar el comprobante en el
ABM por `id` / `uuid`.

## Webhook post-autorizacion

Si el caller pasa `webhook_url` en el body, el cron de notificacion hace un
`POST` JSON a esa URL una vez que el comprobante logre autorizarse contra
AFIP. Esto evita que el caller tenga que pollear el ABM para conciliar el
resultado.

### Request que recibe el receptor

- **Metodo**: `POST`
- **URL**: la que se paso en `webhook_url` (tal cual, con query string y
  path que hubiera).
- **Headers**:
  - `Content-Type: application/json; charset=utf-8`
  - `Accept: application/json`
  - `User-Agent: databox/datacount-comprobantes-webhook`
- **Body**: JSON con el snapshot final del comprobante ya autorizado
  (mismos nombres de campos y tipos que el `data` de la response `201` de
  este endpoint, pero con los campos de CAE poblados).

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
| `webhook_estado`  | string                  | Al momento del disparo esta en `'pendiente'`; el cron lo pasa a `'completado'` recien despues de recibir la response 2xx. **No es indicativo del intento actual.** |
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
  `webhook_estado` en `'pendiente'` para que el cron lo levante en el
  proximo tick.
- **El body de la response se descarta** — solo importa el status code.
  El receptor puede devolver `204 No Content` o un JSON con lo que sea.

### Estado del webhook

La columna `webhook_estado` de `datacount_comprobantes` refleja el estado:

- `'pendiente'` — se cargo `webhook_url` pero el cron todavia no logro
  notificar (comprobante sin autorizar aun, o disparo previo fallido).
- `'completado'` — el receptor devolvio HTTP 2xx.
- `NULL` — el caller no configuro webhook.

### Comportamiento

- **Independiente del alta**: el disparo del webhook lo hace exclusivamente
  el cron `cloud/jobs/datacount_comprobantes_notificar.php`. Ni este
  endpoint ni el cron de autorizacion lo disparan directamente. Cubre por
  igual los comprobantes dados de alta por el v4, por el cron y por el
  boton "Autorizar" del ABM.
- **Retry automatico**: los `'pendiente'` los reintenta el cron hasta que
  el receptor devuelva 2xx.
- **Idempotencia**: el receptor puede recibir el mismo POST varias veces
  para el mismo comprobante (cada reintento del cron dispara uno). Usar
  `id` o `uuid` como clave de deduplicacion.
- **Timeouts**: conexion 3s, total 8s. Sigue hasta 3 redirects.
- **No hay auth** en el POST al webhook — el caller debe usar una URL con
  token/secret en el path o query si necesita autenticar el origen.

## URL

```
POST https://api.databox.net.ar/v4/datacount/comprobantes
```

La ruta va **sin extension**: la resuelve el `.htaccess` de `api/`, que cubre
todo el arbol con un rewrite interno al `.php`.

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
| `estado`        | string(1)    | no          | Default: `'2'` (Pendiente) — el cron de autorizacion lo levanta. Pasar `'1'` (Preparacion) para dejarlo como borrador sin encolarlo. |
| `webhook_url`   | string(500)  | no          | URL a la que el cron de notificacion hara POST JSON cuando el comprobante logre autorizarse contra AFIP (ver seccion "Webhook post-autorizacion"). Si viene, `webhook_estado` arranca en `'pendiente'`. |

`neto`, `iva` y `total` se **recalculan siempre server-side** desde
`renglones` — cualquier valor en el body para esas claves se ignora.

## Response

### 201 Created

Siempre `201` cuando el alta prospera. El comprobante queda en `estado='2'`
(Pendiente); los campos de CAE (`cae`, `cae_vto`, `cbte_nro`, `serie`,
`autorizado`, `caeres`) salen en `null` — la autorizacion la resuelve el
cron mas tarde.

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
    "estado": "2",
    "registrado": "2026-08-01 10:15:34",
    "webhook_url": "https://mi-app.ejemplo.com/hooks/facturas?token=abc123",
    "webhook_estado": "pendiente",
    "cae": null,
    "cae_vto": null,
    "cbte_nro": null,
    "serie": null,
    "autorizado": null,
    "caeres": null
  }
}
```

Si el caller no mando `webhook_url`, ambos campos `webhook_url` y
`webhook_estado` salen en `null`.

## Errores

| Codigo | Cuando |
|--------|--------|
| 400    | Falta `talonario`, `renglones` vacio, `concepto` invalido. |
| 401    | Bearer ausente / apikey desconocida / aplicacion deshabilitada. |
| 404    | Talonario inexistente. |
| 405    | Metodo distinto de POST. |
| 500    | Excepcion no controlada (se propaga el mensaje). |

El endpoint **no devuelve 422** por fallos AFIP — ya no hay autorizacion en
la request. Los rechazos de AFIP los maneja el cron de autorizacion y quedan
reflejados en `caeres` / `sucesos` del ABM.

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
