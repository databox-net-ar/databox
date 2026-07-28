# /v4/telegram/mensajes

> URL pública de esta documentación: <https://api.databox.net.ar/v4/telegram/mensajes.md>

Microservicio de envío de mensajes de Telegram vía Bot API. Sirve dos casos de
uso:

- **Enviar** un mensaje **sincronamente** (llama a la Bot API en el mismo POST
  y devuelve el resultado real).
- **Consultar** el resultado de un mensaje ya enviado.

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo
vía HTTP). La UI de administración interna (panel cloud → Plataformas →
Telegram → Mensajes) usa su propio endpoint `cloud/api/telegrammensajes.php`.
Ambos caminos escriben en la misma tabla (`telegram_mensajes`) y pasan por la
misma función compartida `enviarTelegramMensaje()`
([cloud/api/lib/telegram_mensajes.php](../../../cloud/api/lib/telegram_mensajes.php)),
garantizando reglas idénticas de sanitización, obligatorios, defaults y envío.

**Diferencia clave con Evolution API**: acá no hay cola ni motor. El envío es
**sincrónico** — el endpoint espera la respuesta de la Bot API y devuelve el
estado final del mensaje (`enviado` o `error`).

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/telegram/mensajes`

| Método | Path                          | Uso                                        |
|--------|-------------------------------|--------------------------------------------|
| POST   | `/v4/telegram/mensajes`       | Enviar un mensaje (sincrónico).            |
| GET    | `/v4/telegram/mensajes?id=N`  | Consultar resultado del mensaje N.         |

Cualquier otro método devuelve `405 Metodo no soportado`.

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

---

## POST — enviar un mensaje

Content-Type: `application/json; charset=utf-8`.

### Body

| Campo             | Tipo         | Obligatorio | Notas                                                                                                                    |
|-------------------|--------------|-------------|--------------------------------------------------------------------------------------------------------------------------|
| `proyecto_slug`   | string       | Sí¹         | Slug de `proyectos.slug`. Se resuelve al id antes del INSERT.                                                            |
| `proyecto_id`     | int          | Sí¹         | Alternativa numérica a `proyecto_slug`. FK a `proyectos.id`. Alias legacy: `proyecto`.                                   |
| `canal_slug`      | string       | Sí¹         | Slug del bot en `telegram_bots.slug`. Determina qué token usar para autenticar contra la Bot API.                        |
| `canal_id`        | int          | Sí¹         | Alternativa numérica a `canal_slug`. FK a `telegram_bots.id`. Alias legacy: `canal`.                                     |
| `destino`         | string(255)  | Sí²         | `chat_id` de Telegram (numérico; puede ser negativo para grupos/canales, ej. `-1001234567890`).                          |
| `cuerpo`          | string       | Sí³         | Texto del mensaje (Markdown). Cuando viene plantilla, se ignora y se usa el `cuerpo` de la plantilla.                    |
| `plantilla_slug`  | string       | No          | Slug de `datarocket_plantillas.slug`. Dispara el merge de plantilla.                                                     |
| `plantilla_id`    | int          | No          | Alternativa numérica a `plantilla_slug`. Alias legacy: `plantilla`.                                                      |
| `variables`       | object       | No          | Diccionario `{clave: valor}` para sustituir placeholders `{clave}` en `cuerpo` / `asunto` de la plantilla.                |
| `remitente`       | string(255)  | No          | Nombre humano del emisor (para display en el ABM interno).                                                               |
| `remite`          | string(255)  | No          | No aplica al envío en Telegram — se conserva para trazabilidad.                                                          |
| `destinatario`    | string(255)  | No          | Nombre humano del destinatario.                                                                                          |
| `asunto`          | string(255)  | No          | Si viene, se antepone en negrita al `cuerpo` antes de enviar.                                                            |
| `formato`         | string(20)   | No          | Default `'texto'`. Informativo.                                                                                          |
| `adjunto`         | string(500)  | No          | Si es un URL http(s) el envío usa `sendPhoto` con esa URL como `photo` y el `cuerpo` como `caption`.                     |
| `prioridad`       | int (1–5)    | No          | Default `3`. Se preserva para consistencia con evolution/aws — no afecta el envío (es sincrónico).                       |
| `tags`            | string(255)  | No          | Etiquetas libres para segmentación / búsqueda.                                                                           |
| `fecha`           | datetime     | No          | `YYYY-MM-DD HH:MM[:SS]`. Default: `NOW()` en `America/Argentina/Buenos_Aires`.                                            |

¹ **Slug o id, no ambos**: si mandás los dos, el slug gana.

² Si el bot tiene `chat_id` default seteado, `destino` es opcional — se usa el
default del bot cuando el body no lo trae.

³ **Aportados por la plantilla**: si mandás `plantilla_slug` / `plantilla_id`,
los campos `cuerpo`, `asunto`, `formato`, `adjunto`, `remite` y `remitente` se
sobrescriben con los de la plantilla.

### Aplicación de plantilla

Idéntica al microservicio de Evolution: sobrescritura de los 6 campos de
contenido + sustitución de placeholders `{clave}` usando `variables`. Ver
[/v4/evolution/mensajes.md](../evolution/mensajes.md#aplicación-de-plantilla)
para el detalle.

### Envío

- Se resuelve el `canal_id` en `telegram_bots` y se lee su `token`.
- Si el bot no existe / no tiene token / está deshabilitado → `400`.
- Se hace `POST https://api.telegram.org/bot<TOKEN>/sendMessage` (o
  `sendPhoto` si `adjunto` es un URL) con `chat_id = destino`.
- Timeout total 15s, connect timeout 5s.
- Si la Bot API devuelve `ok:true` → el mensaje queda con `estado='enviado'`
  y `demora` en segundos.
- Si devuelve error o hay fallo de red → `estado='error'` y `error` con el
  detalle. El id igual se devuelve, con `estado='error'`.

### Respuesta — 201 Created (envío ok)

```json
{
  "ok": true,
  "data": {
    "id":           123456,
    "estado":       "enviado",
    "estado_label": "Enviado",
    "fecha":        "2026-07-27 20:15:03",
    "enviado":      "2026-07-27 20:15:04",
    "demora":       1,
    "error":        null
  }
}
```

### Respuesta — 502 Bad Gateway (Telegram rechazó)

```json
{
  "ok": true,
  "data": {
    "id":           123457,
    "estado":       "error",
    "estado_label": "Error",
    "fecha":        "2026-07-27 20:15:12",
    "enviado":      null,
    "demora":       2,
    "error":        "Telegram [400]: Bad Request: chat not found"
  }
}
```

Nota: `ok: true` porque **el request al microservicio fue exitoso** (recibió
body válido, encontró el bot, hizo la llamada). El `data.estado = 'error'`
indica que Telegram rechazó el mensaje. El HTTP status `502` distingue este
caso del `201` de éxito para clientes que sólo miran el status code.

### Errores

| Código | Body `error`                                     | Cuándo                                              |
|--------|--------------------------------------------------|-----------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                       | El body no es JSON válido.                          |
| 400    | `Faltan campos obligatorios: Proyecto, Bot, …`   | Falta uno o más de los 4 requeridos.                |
| 400    | `Bot #N no existe`                               | `canal_id` inexistente.                             |
| 400    | `Bot #N no tiene token configurado`              | El bot existe pero `telegram_bots.token` es NULL.   |
| 400    | `Bot #N esta deshabilitado`                      | `telegram_bots.habilitado = '0'`.                   |
| 400    | `<X> con slug '<slug>' no encontrado`            | Slug pasado en `*_slug` no existe.                  |
| 500    | `<mensaje de la excepción>`                      | Falla inesperada (PDO, etc.).                       |

---

## GET — consultar resultado

```
GET /v4/telegram/mensajes?id=123456
```

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "id":           123456,
    "canal_id":     3,
    "destino":      "-1001234567890",
    "estado":       "enviado",
    "estado_label": "Enviado",
    "error":        null,
    "encolado":     "2026-07-27 20:15:03",
    "enviado":      "2026-07-27 20:15:04",
    "demora":       1
  }
}
```

Valores posibles de `estado`:

| `estado`   | `estado_label` | Significado                                                        |
|------------|----------------|--------------------------------------------------------------------|
| `enviando` | Enviando       | Fila persistida pero el proceso se murió antes del UPDATE final.   |
| `enviado`  | Enviado        | Terminal ok. `enviado` y `demora` (segundos) poblados.             |
| `error`    | Error          | Terminal — fallo. Ver `error` para detalle.                        |

### Errores

| Código | Body `error`             | Cuándo                          |
|--------|--------------------------|---------------------------------|
| 400    | `Falta id (int > 0)`     | Query string sin `id` válido.   |
| 404    | `Mensaje no encontrado`  | `id` no existe en la tabla.     |

---

## Ejemplos

### curl — enviar (por slug)

```bash
curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "vigicom",
    "canal_slug":    "vigicom-support",
    "destino":       "-1001234567890",
    "cuerpo":        "*Alerta*\nSensor 42 fuera de rango."
  }'
```

### curl — enviar al chat_id default del bot

Si el bot tiene `chat_id` configurado, se puede omitir `destino`:

```bash
curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "vigicom",
    "canal_slug":    "vigicom-support",
    "cuerpo":        "Ping."
  }'
```

### curl — enviar imagen (adjunto es URL http/https)

```bash
curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "proyecto_slug": "vigicom",
    "canal_slug":    "vigicom-support",
    "destino":       "-1001234567890",
    "cuerpo":        "Grafico de la ultima hora.",
    "adjunto":       "https://ejemplo.com/grafico.png"
  }'
```

### curl — consultar resultado

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/telegram/mensajes?id=123456"
```

---

## Fuera del alcance

Este microservicio es intencionalmente mínimo: **solo alta + consulta**. Los
siguientes verbos existen en el ABM cloud pero **no** están expuestos vía v4:

- Listado con filtros → `GET  cloud/api/telegrammensajes.php`
- Baja definitiva     → `DELETE cloud/api/telegrammensajes.php?id=N`

---

## Referencias

- Tabla destino: `telegram_mensajes` — schema en [db/schema.sql](../../../db/schema.sql).
- Función compartida de envío: `enviarTelegramMensaje()` en [cloud/api/lib/telegram_mensajes.php](../../../cloud/api/lib/telegram_mensajes.php).
- ABM interno equivalente: [cloud/api/telegrammensajes.php](../../../cloud/api/telegrammensajes.php).
- Bots (catálogo): [cloud/api/telegrambots.php](../../../cloud/api/telegrambots.php).
