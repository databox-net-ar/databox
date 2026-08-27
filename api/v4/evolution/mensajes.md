# /v4/evolution/mensajes

> URL pública de esta documentación: <https://api.databox.net.ar/v4/evolution/mensajes.md>

Microservicio de ingesta de mensajes de WhatsApp vía Evolution API. Sirve dos
casos de uso:

- **Encolar** un mensaje en la cola de envío de Evolution.
- **Consultar** el estado actual de un mensaje ya encolado.

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo
vía HTTP). La UI de administración interna (panel cloud → Plataformas →
Evolution → Mensajes) usa su propio endpoint `cloud/api/evolutionmensajes.php`.
Ambos caminos escriben en la misma tabla (`evolution_mensajes`) y — desde el
commit `e32fdfe` (2026-07-25) — pasan por la misma función compartida
`encolarEvolutionMensaje()` ([cloud/api/lib/evolution_mensajes.php](../../../cloud/api/lib/evolution_mensajes.php)),
garantizando reglas idénticas de sanitización, obligatorios y defaults.

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/evolution/mensajes`

| Método | Path                          | Uso                            |
|--------|-------------------------------|--------------------------------|
| POST   | `/v4/evolution/mensajes`      | Encolar un mensaje.            |
| GET    | `/v4/evolution/mensajes?id=N` | Consultar estado del mensaje N.|

Cualquier otro método devuelve `405 Metodo no soportado`. La ruta va **sin
extensión**: la resuelve el `.htaccess` de `api/`, que cubre todo el árbol con
un rewrite interno al `.php`.

---

## Autenticación

Bearer con la `apikey` de una fila de `aplicaciones` (misma tabla que usa el
resto del stack).

```
Authorization: Bearer <APIKEY>
```

Reglas:

- Sin header → `401 Bearer token ausente`.
- Apikey inexistente → `401 API key desconocida`.
- Aplicación con `habilitada != '1'` → `401 Aplicacion deshabilitada`.
- El contador `aplicaciones.usos` se incrementa por request (best-effort; un
  fallo en el UPDATE no tira el request).

Apache no siempre propaga `Authorization` — el handler chequea
`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` y como último recurso
`getallheaders()`.

---

## POST — encolar un mensaje

Content-Type: `application/json; charset=utf-8`.

### Body

Campos que acepta el sanitizador (ver [cloud/api/lib/evolution_mensajes.php](../../../cloud/api/lib/evolution_mensajes.php)
constante `EVO_MSG_SANITIZERS`):

| Campo             | Tipo         | Obligatorio | Notas                                                                                                                    |
|-------------------|--------------|-------------|--------------------------------------------------------------------------------------------------------------------------|
| `proyecto_slug`   | string       | Sí¹         | Slug de `proyectos.slug` (ej. `"vigicom"`). Se resuelve al id correspondiente antes del INSERT.                          |
| `proyecto_id`     | int          | Sí¹         | Alternativa numérica a `proyecto_slug`. FK a `proyectos.id`. Alias legacy: `proyecto`.                                   |
| `canal_slug`      | string       | Sí¹         | Slug de `evolution_canales.slug` (ej. `"vigicom-bot"`). Corresponde al instance name del bot en Evolution API.           |
| `canal_id`        | int          | Sí¹         | Alternativa numérica a `canal_slug`. FK a `evolution_canales.id`. Alias legacy: `canal`.                                 |
| `remite`          | string(255)  | Sí²         | Número emisor (ej. `5491133445566`).                                                                                     |
| `destino`         | string(255)  | Sí          | Número destinatario en E.164 sin `+`.                                                                                    |
| `cuerpo`          | string       | Sí²         | Texto del mensaje. Cuando viene plantilla, se ignora y se usa el `cuerpo` de la plantilla.                               |
| `plantilla_slug`  | string       | No          | Slug de `datarocket_plantillas.slug` (ej. `"1DV1ZH"`). Dispara el merge de plantilla (ver más abajo).                    |
| `plantilla_id`    | int          | No          | Alternativa numérica a `plantilla_slug`. FK a `datarocket_plantillas.id`. Alias legacy: `plantilla`.                     |
| `variables`       | object       | No          | Diccionario `{clave: valor}` para sustituir placeholders `{clave}` en `cuerpo` / `asunto` de la plantilla. Ver abajo.    |
| `remitente`      | string(255)  | No          | Nombre humano del emisor (para display).                                  |
| `destinatario`   | string(255)  | No          | Nombre humano del destinatario.                                           |
| `prioridad`      | int (1–5)    | No          | Default `3` (Media). `5` = Muy Alta (sale primero), `1` = Muy Baja.       |
| `asunto`         | string(255)  | No          | WhatsApp no tiene subject; el sender lo antepone en negrita al cuerpo.    |
| `formato`        | string(20)   | No          | Default `'texto'`. Otros: `'imagen'`, `'video'`, `'audio'`, `'ubicacion'`, `'contacto'`. Ver la tabla de abajo. |
| `adjunto`        | string(500)  | No          | Qué lleva depende del `formato` — ver la tabla de abajo.                  |
| `tags`           | string(255)  | No          | Etiquetas libres para segmentación / búsqueda.                            |
| `fecha`          | datetime     | No          | `YYYY-MM-DD HH:MM[:SS]`. Default: `NOW()` en `America/Argentina/Buenos_Aires`. |
| `encolado`       | datetime     | No          | Default: mismo valor que `fecha`.                                         |
| `programado`     | datetime     | No          | Cuándo debe salir. Default: mismo valor que `fecha` (envío inmediato).    |

¹ **Slug o id, no ambos**: Para `proyecto`, `canal` y `plantilla` podés pasar el
identificador humano (`_slug`) o el numérico (`_id`). Si mandás los dos, el slug
gana y el id se ignora. Slug inexistente → `400 <X> con slug '<slug>' no encontrado`.

² **Aportados por la plantilla**: si mandás `plantilla_slug` (o `plantilla_id`),
los campos `cuerpo`, `asunto`, `formato`, `adjunto`, `remite` y `remitente` se
sobrescriben con los de la plantilla — el body ya no necesita mandarlos, y si
los manda, se ignoran. Excepción: los campos que la plantilla tiene vacíos no
sobrescriben, así que `remite` del body sirve como fallback si la plantilla no
lo definió.

### Formatos

Cada `formato` sale por un endpoint distinto de Evolution API y le da un
significado distinto a `adjunto` (ver el switch de `evolutionApiEnviar()` en
[cloud/api/lib/mensajes_enviar.php](../../../cloud/api/lib/mensajes_enviar.php)):

| `formato`    | Endpoint de Evolution | `adjunto`              | `cuerpo`                     |
|--------------|-----------------------|------------------------|------------------------------|
| `texto`      | `sendText`            | — (no se usa)          | el texto del mensaje         |
| `imagen`     | `sendMedia`           | URL de la imagen       | caption (pie de foto)        |
| `video`      | `sendMedia`           | URL del video          | caption                      |
| `audio`      | `sendWhatsAppAudio`   | URL del audio          | se ignora                    |
| `ubicacion`  | `sendLocation`        | `"latitud,longitud"`   | nombre del lugar             |
| `contacto`   | `sendContact`         | JSON de la vCard       | fallback de `fullName`       |

Notas:

- **`audio` sale siempre como nota de voz** (PTT), no como archivo adjunto:
  `sendWhatsAppAudio` es el endpoint de voice note. No hay forma de mandar un
  mp3 como archivo descargable.
- **`imagen` y `video` mandan un `fileName` fijo** (`imagen.jpg` / `video.mp4`)
  sin mirar la extensión real de la URL.
- `cuerpo` es obligatorio en **todos** los formatos, incluso donde el sender lo
  descarta (`audio`) o no lo manda (`contacto`).
- No están soportados: documentos (PDF, Excel), stickers, botones/listas
  interactivas, encuestas ni reacciones.

### Formato `contacto` (tarjeta / vCard)

Manda una tarjeta de contacto que el destinatario agenda con un toque, sin
copiar el número a mano. Los datos van en `adjunto` **como JSON** — a
diferencia del resto de los formatos, que llevan una sola URL:

```json
{
  "fullName":     "Americo Alvarez",
  "wuid":         "5492645101498",
  "phoneNumber":  "+54 9 264 510-1498",
  "organization": "Lider Distribuidora",
  "email":        "contacto@ejemplo.com",
  "url":          "liderdistribuidora.dex.net.ar"
}
```

| Campo          | Obligatorio | Notas                                                                 |
|----------------|-------------|-----------------------------------------------------------------------|
| `fullName`     | Sí          | Nombre que se ve en la tarjeta. Si falta, se usa `cuerpo`.            |
| `wuid`         | Sí          | Número en E.164 **sin `+`**. Es con lo que WhatsApp vincula el contacto. |
| `phoneNumber`  | No          | Cómo se muestra el número. Default: `+` + `wuid`.                     |
| `organization` | No          | Empresa. Se omite de la vCard si viene vacío.                         |
| `email`        | No          | Se omite si viene vacío.                                              |
| `url`          | No          | Sitio web. Se omite si viene vacío.                                   |

`cuerpo` sigue siendo **obligatorio** (lo son los 5 campos de siempre, en todos
los formatos), pero **no viaja a WhatsApp**: la tarjeta no admite texto
acompañante. Poné ahí el nombre del contacto — el sender lo usa como fallback
de `fullName` cuando el JSON no lo trae. Si querés acompañar la tarjeta con un
mensaje, encolá dos mensajes.

> **Ojo con el largo**: `adjunto` es `VARCHAR(500)` y el sanitizador **trunca**
> (no rechaza). Un JSON de tarjeta más largo que eso se corta a la mitad,
> `json_decode` falla y sale una tarjeta vacía sin error visible. Una tarjeta
> típica ronda los 200 caracteres; si cargás `organization` + `url` largos,
> medí antes.

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "dex",
    "canal_slug":    "dex-liderdistribuidora",
    "remite":        "5492645101498",
    "destino":       "5491199887766",
    "formato":       "contacto",
    "cuerpo":        "Americo Alvarez",
    "adjunto":       "{\"fullName\":\"Americo Alvarez\",\"wuid\":\"5492645101498\",\"phoneNumber\":\"+54 9 264 510-1498\",\"organization\":\"Lider Distribuidora\",\"url\":\"liderdistribuidora.dex.net.ar\"}"
  }'
```

### Aplicación de plantilla

Cuando el body incluye `plantilla_slug` (o `plantilla_id`), el microservicio
carga la fila de `datarocket_plantillas` y hace merge sobre el payload antes
de insertar en `evolution_mensajes`:

1. **Sobrescritura**: `cuerpo`, `asunto`, `formato`, `adjunto`, `remite` y
   `remitente` de la plantilla pisan cualquier valor equivalente que haya en
   el body. Si el campo está vacío en la plantilla, el body sobrevive como
   fallback.
2. **Sustitución de variables**: si el body trae un objeto `variables`, cada
   par `clave: valor` se aplica sobre `cuerpo` y `asunto` reemplazando el
   literal `{clave}` (case-sensitive, sin espacios). Los placeholders sin
   valor correspondiente quedan intactos en el texto final — no es error.

Ejemplo: la plantilla `1DV1ZH` tiene `cuerpo = "Hola {comunidad.nombre}, …"`
y `formato = "imagen"`. Al mandar `variables: {"comunidad.nombre": "Los Alerces"}`,
el mensaje encolado queda con `cuerpo = "Hola Los Alerces, …"` y
`formato = "imagen"` (el body ni siquiera necesita mencionar el formato).

Reglas de sanitización:

- Strings vacíos o solo whitespace → `NULL`.
- Ints vacíos → `NULL`.
- Datetimes aceptan `2026-07-25T20:15`, `2026-07-25 20:15` y `2026-07-25 20:15:00`
  (se normalizan a `Y-m-d H:i:s`). Formato inválido → `NULL` (y aplica el default).

### Respuesta — 201 Created

```json
{
  "ok": true,
  "data": {
    "id":         123456,
    "estado":     "pendiente",
    "fecha":      "2026-07-25 17:22:03",
    "encolado":   "2026-07-25 17:22:03",
    "programado": "2026-07-25 17:22:03"
  }
}
```

Los campos `fecha`/`encolado`/`programado` se re-leen desde la BD para que la
respuesta refleje exactamente lo que quedó persistido (incluidos los defaults
aplicados por el sanitizador).

### Efecto lateral: wake-on-demand del motor

Todo alta con `estado='pendiente'` levanta la bandera runtime
`parametros.evolution.mensajes.enviar` a `'2'` (ENVIANDO). Es la señal que el
cron worker `cloud/jobs/evolution_mensajes_enviar.php` usa para saber que hay
trabajo — sin ella el worker duerme aunque haya pendientes.

**Excepción**: si un operador dejó el motor en pausa manual (`valor='0'`,
DETENIDO) desde el UI, el alta **no** lo despierta — el mensaje queda en cola
pero no se procesa hasta que alguien haga "Iniciar motor" desde el ABM.

Semántica del flag tri-estado (definida en la migración
`20260725_1700_parametros_evolution_mensajes_enviar_3estados.sql`):

| Valor | Estado     | Significado                                                       |
|-------|------------|-------------------------------------------------------------------|
| `'0'` | DETENIDO   | Pausa manual desde el UI. El worker no procesa aunque haya cola.  |
| `'1'` | ESPERANDO  | Cola vacía, worker ocioso. El próximo encole lo lleva a `'2'`.    |
| `'2'` | ENVIANDO   | Hay pendientes; el worker los procesa en los próximos ticks.      |

### Errores

| Código | Body `error`                                     | Cuándo                                              |
|--------|--------------------------------------------------|-----------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                       | El body no es JSON válido.                          |
| 400    | `Faltan campos obligatorios: Proyecto, Canal, …` | Falta uno o más de los 5 requeridos.                |
| 400    | `<X> con slug '<slug>' no encontrado`            | Slug pasado en `proyecto_slug`/`canal_slug`/`plantilla_slug` no existe. |
| 500    | `<mensaje de la excepción>`                      | Falla inesperada (PDO, etc.).                       |

---

## GET — consultar estado

```
GET /v4/evolution/mensajes?id=123456
```

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "id":           123456,
    "canal_id":     42,
    "destino":      "5491133445566",
    "estado":       "enviado",
    "estado_label": "Enviado",
    "error":        null,
    "encolado":     "2026-07-25 17:22:03",
    "programado":   "2026-07-25 17:22:03",
    "enviado":      "2026-07-25 17:22:07",
    "demora":       4
  }
}
```

Valores posibles de `estado` (catálogo `estados.campo = 'evolution_mensaje_estado'`):

| `estado`    | `estado_label` | Significado                                             |
|-------------|----------------|---------------------------------------------------------|
| `pendiente` | Pendiente      | En cola, esperando que el worker lo tome.               |
| `enviando`  | Enviando       | Lock optimista del worker — mensaje en despacho.        |
| `enviado`   | Enviado        | Terminal ok. `enviado` y `demora` (segundos) poblados.  |
| `anulado`   | Anulado        | Terminal — cancelado desde el ABM antes de despacho.    |
| `error`     | Error          | Terminal — fallo. Ver `error` para detalle.             |

### Errores

| Código | Body `error`             | Cuándo                          |
|--------|--------------------------|---------------------------------|
| 400    | `Falta id (int > 0)`     | Query string sin `id` válido.   |
| 404    | `Mensaje no encontrado`  | `id` no existe en la tabla.     |

---

## Ejemplos

### curl — encolar (por slug)

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "vigicom",
    "canal_slug":    "vigicom-bot",
    "remite":        "5491133445566",
    "destino":       "5491199887766",
    "cuerpo":        "Hola, tu turno es a las 15:30.",
    "prioridad":     4
  }'
```

### curl — encolar desde plantilla (con variables)

Toma `cuerpo`, `asunto`, `formato` y `adjunto` de la plantilla y sustituye
`{comunidad.nombre}` por el valor pasado en `variables`. El body no necesita
mandar `cuerpo` — lo aporta la plantilla.

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug":  "vigicom",
    "canal_slug":     "vigicom-bot",
    "plantilla_slug": "1DV1ZH",
    "destino":        "5491199887766",
    "variables": {
      "comunidad.nombre": "Los Alerces"
    }
  }'
```

### curl — encolar (por id, equivalente)

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_id": 12,
    "canal_id":    42,
    "remite":      "5491133445566",
    "destino":     "5491199887766",
    "cuerpo":      "Hola, tu turno es a las 15:30.",
    "prioridad":   4
  }'
```

### curl — encolar diferido (envío programado)

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "vigicom",
    "canal_slug":    "vigicom-bot",
    "remite":        "5491133445566",
    "destino":       "5491199887766",
    "cuerpo":        "Recordatorio: cita mañana 10:00.",
    "programado":    "2026-07-26 09:00:00"
  }'
```

### curl — consultar estado

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/evolution/mensajes?id=123456"
```

---

## Fuera del alcance

Este microservicio es intencionalmente mínimo: **solo alta + consulta**. Los
siguientes verbos existen en el ABM cloud pero **no** están expuestos vía v4
(hoy los consume únicamente la UI interna):

- Listado con filtros → `GET  cloud/api/evolutionmensajes.php`
- Baja definitiva     → `DELETE cloud/api/evolutionmensajes.php?id=N`
- Anular pendiente    → `POST cloud/api/evolutionmensajes_anular.php?id=N`
- Control del motor   → `POST cloud/api/evolutionmensajes_motor.php`

Si algún consumidor externo llegara a necesitar alguno de esos, se agrega acá
como endpoint separado (siempre delegando la lógica a
`cloud/api/lib/evolution_mensajes.php` para no divergir de las reglas del ABM).

---

## Referencias

- Tabla destino: `evolution_mensajes` — schema en [db/schema.sql](../../../db/schema.sql).
- Función compartida de alta: `encolarEvolutionMensaje()` en [cloud/api/lib/evolution_mensajes.php](../../../cloud/api/lib/evolution_mensajes.php).
- Sender worker: [cloud/jobs/evolution_mensajes_enviar.php](../../../cloud/jobs/evolution_mensajes_enviar.php).
- ABM interno equivalente: [cloud/api/evolutionmensajes.php](../../../cloud/api/evolutionmensajes.php).
- Bandera runtime del motor: `parametros.variable = 'evolution.mensajes.enviar'`
  (semántica tri-estado sembrada por
  `cloud/sql/migrations/20260725_1700_parametros_evolution_mensajes_enviar_3estados.sql`).
