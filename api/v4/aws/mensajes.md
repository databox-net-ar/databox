# `/v4/aws/mensajes`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/aws/mensajes.md>

Microservicio de ingesta y consulta de estado de mensajes **AWS SES**
(correo masivo transaccional). Un unico archivo `.php`
([mensajes.php](mensajes.php)) que sirve todos los verbos HTTP del
recurso — sin framework ni router aparte.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). El `.htaccess` local mapea URLs sin extension al
archivo `.php` correspondiente, asi que ambas formas son equivalentes:

```
POST https://api.databox.net.ar/v4/aws/mensajes
POST https://api.databox.net.ar/v4/aws/mensajes.php
```

## Autenticacion

Bearer estatico contra `aplicaciones.apikey` (misma tabla que el resto
del stack). El header debe llegar como:

```
Authorization: Bearer <apikey>
```

Cualquier apikey habilitada pasa — no hay scope por endpoint. Cada
llamada exitosa incrementa `aplicaciones.usos` (best-effort).

Errores devueltos por el auth:

| Codigo | Cuerpo                                            |
| ------ | ------------------------------------------------- |
| 401    | `{"ok": false, "error": "Bearer token ausente"}`  |
| 401    | `{"ok": false, "error": "API key desconocida"}`   |
| 401    | `{"ok": false, "error": "Aplicacion deshabilitada"}` |

## Contrato de respuesta

Todas las respuestas siguen el shape unificado del stack:

```json
{ "ok": true,  "data": <payload> }
{ "ok": false, "error": "<mensaje>" }
```

Body-in y body-out son JSON `utf-8` (`Content-Type: application/json`).

## Endpoints

| Metodo | Path                              | Uso                                                       |
| ------ | --------------------------------- | --------------------------------------------------------- |
| POST   | `/v4/aws/mensajes`                | Encola un mensaje nuevo.                                  |
| GET    | `/v4/aws/mensajes`                | Listado con filtros (query string). Incluye `resultado`.  |
| GET    | `/v4/aws/mensajes?id=N`           | Estado actual del mensaje N (incluye `uuid` y `resultado`). |

### `POST /v4/aws/mensajes` — Encolar mensaje

Encola un mail para que el sender worker (`cloud/jobs/aws_mensajes_enviar.php`)
lo despache via SES en la proxima corrida del cron.

Delega la insercion al punto UNICO de entrada
`encolarAwsMensaje()` (`cloud/api/lib/aws_mensajes.php`) — la misma
funcion que usa el ABM cloud. Cualquier caller que quiera meter mensajes
en la cola pasa por ahi, asi las reglas de sanitizacion, obligatorios y
wake-on-demand del motor quedan consistentes.

**Body:**

| Campo             | Tipo         | Obligatorio     | Notas                                                                                                                                |
| ----------------- | ------------ | :-------------: | ------------------------------------------------------------------------------------------------------------------------------------ |
| `proyecto_slug`   | string       | si              | Se resuelve contra `proyectos.slug`. Slug inexistente -> 400.                                                                        |
| `canal_slug`      | string       | si              | Se resuelve contra `aws_canales.slug` — determina la cuenta/region SES usada para firmar. Slug inexistente -> 400.                   |
| `destino`         | string       | si              | Email del destinatario.                                                                                                              |
| `plantilla_slug`  | string       | no*             | Se resuelve contra `datarocket_plantillas.slug`. Cuando viene, expande al mensaje (ver seccion **Plantillas**). Slug inexistente -> 400. |
| `remite`          | string       | si sin plantilla | Email del remitente (ej. `no-reply@dominio.com`). Con plantilla lo aporta ella y se ignora si viene aca.                            |
| `asunto`          | string       | si sin plantilla | Subject. Con plantilla, se inyecta como `{asunto}` dentro del subject de la plantilla (ver **Plantillas**).                         |
| `cuerpo`          | string       | si sin plantilla | Body. Con plantilla, se inyecta como `{cuerpo}` dentro del cuerpo de la plantilla (ver **Plantillas**).                             |
| `variables`       | object       | no              | Diccionario opcional de reemplazos custom. Cada `{"clave":"valor"}` se aplica como `str_replace('{clave}', 'valor')` en el subject y el cuerpo (utiles solo con plantilla). |
| `remitente`       | string       | no              | Nombre visible del remitente. Con plantilla lo aporta ella.                                                                          |
| `destinatario`    | string       | no              | Nombre visible del destinatario.                                                                                                     |
| `prioridad`       | int          | no              | Rango 1..5 (5 = envia primero). El sender ordena la cola por este campo.                                                             |
| `formato`         | string       | no              | `html` \| `texto`. Con plantilla lo aporta ella (mapea `H` -> `html`, `T` -> `texto`).                                               |
| `adjunto`         | string       | no              | Ruta / URL del adjunto. Con plantilla lo aporta ella.                                                                                |
| `tags`            | string       | no              | Tags libres para filtrar en el ABM.                                                                                                  |
| `programado`      | string       | no              | `YYYY-MM-DD HH:MM[:SS]`. Si esta seteado, el sender no lo toma hasta esa fecha.                                                      |

> Tambien se aceptan las variantes `_id` (`proyecto_id`, `canal_id`, `plantilla_id`) para
> callers que ya tienen el FK numerico a mano. Si vienen ambos, el `_slug` gana.

### Plantillas

Cuando el body trae `plantilla_slug` (o `plantilla_id`), la plantilla se
expande **antes** del INSERT — el mensaje queda persistido con los campos ya
renderizados y el sender solo despacha lo que ve en `aws_mensajes`. Semantica
identica al legacy v3 (`databox_legacy/databox-api/v3/awsses/mensajes`):

- `remitente`, `remite`, `formato`, `adjunto` los aporta la plantilla y **pisan**
  lo que venga en el body.
- `asunto` = `str_replace('{asunto}', <body.asunto>, <plantilla.asunto>)`.
- `cuerpo` = `str_replace('{cuerpo}', <body.cuerpo>, <plantilla.cuerpo>)`.
- Si viene `variables`, cada `{clave}` se reemplaza en el asunto y el cuerpo
  resultantes (util para nombres, codigos, links personalizados, etc.).
- `destino`, `destinatario`, `prioridad`, `tags`, `programado` siempre vienen
  del body — la plantilla no los aporta.

Campos system-managed (los setea el encolador, no aceptar en el body): `fecha`,
`encolado`, `estado`, `error`, `enviado`, `demora`.

**Respuesta (201):**

```json
{
  "ok": true,
  "data": {
    "id": 14512,
    "uuid": null,
    "estado": "pendiente",
    "fecha": "2026-07-25 14:32:07",
    "encolado": "2026-07-25 14:32:07",
    "programado": null
  }
}
```

`uuid` (SES MessageId) sale `null` al encolar — lo pobla el sender worker
cuando SES acepta el mail. Se devuelve igual en el 201 para que el shape
del POST sea identico al del GET; para leer el UUID final, consultar el
mensaje con `GET ?id=N` una vez que el sender lo despache.

**Errores tipicos:**

| Codigo | Motivo                                                                                          |
| ------ | ----------------------------------------------------------------------------------------------- |
| 400    | Body no es JSON valido / faltan obligatorios (mensaje detalla que campos).                      |
| 401    | Bearer ausente o apikey invalida (ver seccion Autenticacion).                                   |
| 405    | Metodo no soportado (solo GET/POST).                                                            |
| 500    | Error inesperado (probable fallo de DB).                                                        |

**Ejemplo `curl`:**

```bash
curl -X POST https://api.databox.net.ar/v4/aws/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "databox",
    "canal_slug": "databox",
    "plantilla_slug": "databox",
    "destino": "cliente@ejemplo.com",
    "destinatario": "Juan Perez",
    "asunto": "Bienvenido",
    "cuerpo": "<p>Hola Juan</p>",
    "variables": { "nombre": "Juan", "codigo": "AB-123" },
    "tags": "onboarding"
  }'
```

### `GET /v4/aws/mensajes` — Listado

Devuelve un listado paginado de mensajes de la cola / historial de AWS SES,
filtrable por cualquier combinacion de columnas. **Es el endpoint natural
para buscar por `resultado`** — asincronico, se puebla desde las
notificaciones SNS ([/v4/aws/eventos](eventos.md)).

**Query params (todos opcionales, combinables con `AND`):**

| Parametro      | Tipo   | Notas                                                                                    |
| -------------- | ------ | ---------------------------------------------------------------------------------------- |
| `codigo`       | int    | Filtra por `id` exacto.                                                                  |
| `proyecto_id`  | int    | Match exacto contra `proyecto_id`.                                                       |
| `canal_id`     | int    | Match exacto contra `canal_id`.                                                          |
| `plantilla_id` | int    | Match exacto contra `plantilla_id`.                                                      |
| `estado`       | string | Match exacto contra `estado` (`pendiente`, `enviando`, `enviado`, `anulado`, `error`).   |
| `resultado`    | string | Match exacto contra `resultado` (`entregado`, `abierto`, `cliqueado`, `spam`, `rebotado`, `rechazado`, o vacio para "sin notificacion SNS todavia"). |
| `uuid`         | string | Match exacto contra `uuid` (SES MessageId). Util para reconciliar contra logs de SES/SNS. |
| `desde`        | date   | `YYYY-MM-DD`. Filtra `fecha >= '<desde> 00:00:00'`.                                      |
| `hasta`        | date   | `YYYY-MM-DD`. Filtra `fecha <= '<hasta> 23:59:59'`.                                      |
| `q`            | string | Busqueda difusa: `LIKE '%<q>%'` sobre `destinatario`, `destino`, `asunto`, `remitente`, `remite`, `tags`. |
| `order_by`     | string | Default `id`. Whitelist: `id`, `fecha`, `proyecto_id`, `canal_id`, `plantilla_id`, `destino`, `asunto`, `estado`, `resultado`, `enviado`, `demora`. Valor fuera de la lista cae a `id`. |
| `dir`          | string | `asc` \| `desc`. Default `desc`.                                                         |
| `limite`       | int    | Default `100`. Clampeado a `[1, 1000]`.                                                  |

**Respuesta (200):**

```json
{
  "ok": true,
  "data": {
    "total": 2,
    "items": [
      {
        "id": 14513,
        "uuid": "0100019a8b7c6d5e-...",
        "canal_id": 3,
        "proyecto_id": 1,
        "plantilla_id": null,
        "contacto_id": 148286,
        "remite": "no-reply@databox.net.ar",
        "remitente": "Databox",
        "destino": "cliente@ejemplo.com",
        "destinatario": "Juan Perez",
        "asunto": "Bienvenido",
        "estado": "enviado",
        "estado_label": "Enviado",
        "resultado": "rebotado",
        "resultado_label": "Rebotado",
        "error": null,
        "fecha": "2026-07-25 14:32:07",
        "encolado": "2026-07-25 14:32:07",
        "programado": null,
        "enviado": "2026-07-25 14:32:14",
        "demora": 7
      }
    ]
  }
}
```

`total` es la cantidad de filas devueltas (post-`LIMIT`), no el total absoluto
en la tabla — mismo criterio que el resto del `v4/`.

**Ejemplo `curl`** — listar los ultimos 50 rebotes de un canal:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/aws/mensajes?canal_id=3&resultado=rebotado&limite=50&order_by=fecha&dir=desc"
```

### `GET /v4/aws/mensajes?id=N` — Consultar estado

Devuelve el estado actual del mensaje N encolado, para que el cliente
pueda hacer polling sin acceso directo a la BD.

**Respuesta (200):**

```json
{
  "ok": true,
  "data": {
    "id": 14512,
    "uuid": "0100019a8b7c6d5e-abcdef12-3456-7890-abcd-ef1234567890-000000",
    "canal_id": 3,
    "remite": "no-reply@databox.net.ar",
    "remitente": "Databox",
    "destino": "cliente@ejemplo.com",
    "destinatario": "Juan Perez",
    "asunto": "Bienvenido",
    "estado": "enviado",
    "estado_label": "Enviado",
    "resultado": "entregado",
    "resultado_label": "Entregado",
    "error": null,
    "fecha": "2026-07-25 14:32:07",
    "encolado": "2026-07-25 14:32:07",
    "programado": null,
    "enviado": "2026-07-25 14:32:14",
    "demora": 7
  }
}
```

`uuid` es el **SES MessageId** que devuelve AWS cuando acepta el mail. Lo
setea el sender worker en el momento del envio, asi que sale `null` mientras
el mensaje esta `pendiente`/`enviando`, y se popula al pasar a `enviado`.
Es la clave con la que las notificaciones asincronicas de SNS
([/v4/aws/eventos](eventos.md)) cruzan de vuelta contra este mensaje.

`estado` viene de la tabla `aws_mensajes` (varchar 20) y tiene 5 valores
posibles alineados con el catalogo `estados.campo = 'aws_mensaje_estado'`:

| valor       | label     | Significado                                                                        |
| ----------- | --------- | ---------------------------------------------------------------------------------- |
| `pendiente` | Pendiente | En cola, esperando al sender.                                                      |
| `enviando`  | Enviando  | Lock optimista del sender (transitorio — dura lo que dura la request contra SES). |
| `enviado`   | Enviado   | Aceptado por SES; `enviado` y `demora` (segundos) quedan poblados.                 |
| `anulado`   | Anulado   | Cancelado antes de despachar (via ABM > "Anular").                                 |
| `error`     | Error     | SES rechazo el envio; `error` trae el mensaje devuelto por AWS.                    |

`estado_label` se agrega por conveniencia — la fuente de verdad es `estado`.

`resultado` es el **desenlace end-to-end** del mensaje una vez despachado —
se popula de forma **asincronica** desde las notificaciones SNS de SES
(webhook [/v4/aws/eventos](eventos.md)). Empieza en `null` (mientras SES no
haya emitido ningun evento) y avanza segun los callbacks que llegan. Nunca
hace downgrade: una vez que el mensaje llego a `rebotado` no vuelve a
`entregado`. Los seis valores posibles (alineados con `estados.campo = 'aws_mensaje_resultado'`):

| valor       | label     | Significado                                                                                        |
| ----------- | --------- | -------------------------------------------------------------------------------------------------- |
| `entregado` | Entregado | SES confirmo la entrega al MTA del destino (evento SNS `Delivery`).                                |
| `abierto`   | Abierto   | El destinatario abrio el mail (evento `Open` — solo se registra la primera vez).                   |
| `cliqueado` | Cliqueado | El destinatario hizo click en un link (evento `Click`).                                            |
| `spam`      | Spam      | El destinatario marco el mail como spam (evento `Complaint`, terminal).                            |
| `rebotado`  | Rebotado  | Bounce definitivo (mailbox lleno, direccion invalida, etc.). Evento `Bounce`, terminal.            |
| `rechazado` | Rechazado | SES rechazo el envio antes de intentarlo (contenido, reputacion, etc.). Evento `Reject`, terminal. |

`resultado_label` se agrega por conveniencia — la fuente de verdad es `resultado`.

Recomendacion de polling: consultar cada 30-60s hasta que `estado` sea
terminal (`enviado`/`error`/`anulado`). Para saber si efectivamente llego,
seguir consultando hasta que `resultado` sea distinto de `null` (o dar por
"entregado" pasado un timeout razonable si SES nunca devuelve evento — ese
caso es raro pero puede pasar si el TopicArn de SNS no esta bien atado).

**Errores:**

| Codigo | Motivo                                            |
| ------ | ------------------------------------------------- |
| 401    | Bearer ausente o apikey invalida.                 |
| 404    | Mensaje no encontrado.                            |

## Motor del cron (wake-on-demand)

Encolar con `estado='pendiente'` (99% de los casos) sube
`parametros.aws.mensajes.enviar` a `'2'` (ENVIANDO) — el sender worker
lee ese flag y procesa la cola en la proxima corrida minutal. El propio
worker lo baja a `'1'` (ESPERANDO) cuando la cola queda vacia
(self-healing).

Si el operador puso el motor en `'0'` (DETENIDO, desde el ABM), encolar
mensajes NO lo reactiva — los mensajes quedan en la cola pero no
despiertan al motor. Salir de ese estado requiere accion manual
("Iniciar motor" en el ABM cloud).

## Espejo con evolution

Este microservicio es espejo estructural de
`api/v4/evolution/mensajes.php` (WhatsApp via Evolution API). Ambos:

- Delegan el INSERT al `encolar<Vertical>Mensaje()` del lib compartido.
- Exponen `POST` (encolar) + `GET ?id=N` (status).
- Devuelven el mismo shape `{ok, data|error}` con codigos 201/200/400/401/404/405/500.

Si se toca la firma de una funcion o el shape de una respuesta, mantener
ambos en linea.
