# v4 · aws

Microservicios de ingesta externos para el vertical **AWS SES** (correo
masivo transaccional). Cada endpoint es un unico archivo `.php` que sirve
todos los verbos HTTP del recurso — sin frameworks ni router aparte.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). El `.htaccess` local mapea URLs sin extension al
archivo `.php` correspondiente, asi que ambos son equivalentes:

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

| Campo          | Tipo    | Obligatorio | Notas                                                                                          |
| -------------- | ------- | :---------: | ---------------------------------------------------------------------------------------------- |
| `proyecto_id`  | int     | si          | FK `proyectos.id`.                                                                             |
| `canal_id`     | int     | si          | FK `aws_canales.id` — determina la cuenta/region SES usada para firmar.                        |
| `remite`       | string  | si          | Email del remitente (ej. `no-reply@dominio.com`).                                              |
| `destino`      | string  | si          | Email del destinatario.                                                                        |
| `asunto`       | string  | si          | Subject del mail.                                                                              |
| `cuerpo`       | string  | si          | Body del mail. Formato indicado por `formato` (default `html` segun el sender).                |
| `plantilla_id` | int     | no          | FK `datarocket_plantillas.id`. Meramente informativo (el body ya va renderizado).              |
| `remitente`    | string  | no          | Nombre visible del remitente (`"Nombre" <email>`).                                             |
| `destinatario` | string  | no          | Nombre visible del destinatario.                                                               |
| `prioridad`    | int     | no          | Rango 1..5 (5 = envia primero). El sender ordena la cola por este campo.                       |
| `formato`      | string  | no          | `html` \| `text`.                                                                              |
| `adjunto`      | string  | no          | Ruta / URL del adjunto (segun convencion del sender).                                          |
| `tags`         | string  | no          | Tags libres para filtrar en el ABM.                                                            |
| `programado`   | string  | no          | `YYYY-MM-DD HH:MM[:SS]`. Si esta seteado, el sender no lo toma hasta esa fecha.                |

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
    "proyecto_id": 12,
    "canal_id": 3,
    "remite": "no-reply@databox.net.ar",
    "remitente": "Databox",
    "destino": "cliente@ejemplo.com",
    "destinatario": "Juan Perez",
    "asunto": "Bienvenido",
    "cuerpo": "<p>Hola Juan</p>",
    "formato": "html",
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
