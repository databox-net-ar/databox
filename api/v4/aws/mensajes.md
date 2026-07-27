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
    "estado": "pendiente",
    "fecha": "2026-07-25 14:32:07",
    "encolado": "2026-07-25 14:32:07",
    "programado": null
  }
}
```

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

### `GET /v4/aws/mensajes?id=N` — Consultar estado

Devuelve el estado actual del mensaje N encolado, para que el cliente
pueda hacer polling sin acceso directo a la BD.

**Respuesta (200):**

```json
{
  "ok": true,
  "data": {
    "id": 14512,
    "canal_id": 3,
    "remite": "no-reply@databox.net.ar",
    "remitente": "Databox",
    "destino": "cliente@ejemplo.com",
    "destinatario": "Juan Perez",
    "asunto": "Bienvenido",
    "estado": "enviado",
    "estado_label": "Enviado",
    "error": null,
    "fecha": "2026-07-25 14:32:07",
    "encolado": "2026-07-25 14:32:07",
    "programado": null,
    "enviado": "2026-07-25 14:32:14",
    "demora": 7
  }
}
```

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

**Errores:**

| Codigo | Motivo                                            |
| ------ | ------------------------------------------------- |
| 400    | `id` ausente o no positivo.                       |
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
