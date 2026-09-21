# `/v4/evolution/canales`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/evolution/canales.md>

Consulta de los **canales** de WhatsApp — cada canal es una instancia conectada
en Evolution API, con su propio número, su propia sesión y su propia apikey.
Sirve dos casos de uso:

- **Listar** los canales disponibles (desde nuestra base).
- **Consultar** el estado en vivo de uno, preguntándole a Evolution.

Es el punto de partida de los demás microservicios de Evolution: todos piden un
`canal_slug`, y éste es el que dice cuáles existen.

**Solo lectura.** El alta y la edición de canales viven en el ABM del panel
cloud (Plataformas → Evolution → Canales): un canal se da de alta una vez, a
mano, cuando alguien escanea el QR. No es una operación de integración.

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/evolution/canales`

| Método | Path                                            | Uso                              |
|--------|-------------------------------------------------|----------------------------------|
| GET    | `/v4/evolution/canales`                         | Listado (desde la base).         |
| GET    | `/v4/evolution/canales?canal_slug=X`            | Detalle + estado en vivo.        |

Cualquier otro método devuelve `405`. La ruta va **sin extensión**: la resuelve
el `.htaccess` de `api/`, que cubre todo el árbol con un rewrite interno al
`.php`.

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

## Cómo se nombra un canal

Los tres microservicios de Evolution aceptan las mismas tres formas, y es
indistinto usar query string (GET) o campo del body (POST/PATCH):

| Campo        | Tipo   | Notas                                                                  |
|--------------|--------|------------------------------------------------------------------------|
| `canal_slug` | string | Slug de `evolution_canales.slug` (ej. `"vigicom-bot"`). Forma canónica — es el *instance name* en Evolution. |
| `canal_id`   | int    | Alternativa numérica. FK a `evolution_canales.id`.                     |
| `canal`      | string | Alias legacy. Si es todo dígitos se trata como id; si no, como slug.   |

Si mandás `canal_slug` y `canal_id` juntos, **gana el slug**. Mismo criterio que
`/v4/evolution/mensajes`.

Errores posibles al resolver el canal (los comparten los tres microservicios):

| Código | Body `error`                                          | Cuándo                                        |
|--------|-------------------------------------------------------|-----------------------------------------------|
| 400    | `Falta canal (canal_slug o canal_id)`                  | No vino ninguna de las tres formas.           |
| 400    | `Canal con slug 'X' no encontrado`                     | El slug no existe en `evolution_canales`.     |
| 400    | `Canal #N no encontrado`                               | El id no existe.                              |
| 400    | `Canal #N sin configuracion completa (falta: token)`   | La fila existe pero está a medio cargar.      |

---

## GET — listado

```
GET /v4/evolution/canales
GET /v4/evolution/canales?proyecto_slug=vigicom
GET /v4/evolution/canales?habilitado=1
```

Filtros (todos opcionales, combinables):

| Parámetro       | Notas                                                              |
|-----------------|---------------------------------------------------------------------|
| `proyecto_slug` | Slug de `proyectos.slug`. Slug inexistente → `400`.                 |
| `proyecto_id`   | Alternativa numérica. Se ignora si vino `proyecto_slug`.            |
| `habilitado`    | `1` (activos) o `0` (desactivados). Sin el filtro salen todos.      |

Ordenado por `nombre`, después `id`.

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "total": 2,
    "items": [
      {
        "id":          102,
        "slug":        "vigicom-bot",
        "nombre":      "Vigicom Bot",
        "proyecto":    102,
        "prefijo":     "549",
        "numero":      "1127934060",
        "celular":     "5491127934060",
        "online":      true,
        "latido":      "2026-09-07 13:42:14",
        "habilitado":  "1",
        "actualizado": "2026-09-07 13:42:14"
      }
    ]
  }
}
```

> **`online` acá es un caché, no el estado del instante.** El listado sale de la
> base sin hablar con Evolution: preguntarle el estado en vivo a N canales serían
> N requests HTTP secuenciales y el listado dejaría de contestar en tiempo
> razonable. Los campos `online` y `latido` los refresca el cron
> `evolution_canales_actualizar_estados.php`; `actualizado` dice de cuándo es la
> foto. Para el estado del momento, pedí el detalle de un canal.

`latido` es la última vez que se **supo vivo** al canal (no la última vez que se
lo miró): si está caído, conserva el valor anterior.

El `token` del canal (la apikey de la instancia en Evolution) **nunca** sale en
una respuesta de este microservicio.

---

## GET — detalle + estado en vivo

```
GET /v4/evolution/canales?canal_slug=databox-bot
```

Consulta `GET /instance/fetchInstances` contra Evolution con la apikey del
canal. Equivalente de `POST /v2/evolution/canalEstado` del legacy.

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "canal": {
      "id":          130,
      "slug":        "databox-bot",
      "nombre":      "Databox Bot",
      "proyecto":    100,
      "prefijo":     "549",
      "numero":      "1163219578",
      "celular":     "5491163219578",
      "online":      true,
      "latido":      "2026-09-01 09:45:08",
      "habilitado":  "1",
      "actualizado": "2026-09-01 09:45:08"
    },
    "online":           true,
    "connectionStatus": "open",
    "instancia": {
      "id":               "a4ef4477-ffc0-4309-af70-2f9746081672",
      "name":             "databox-bot",
      "connectionStatus": "open",
      "ownerJid":         "5491163219578@s.whatsapp.net",
      "profileName":      "Data",
      "profilePicUrl":    "https://pps.whatsapp.net/...",
      "integration":      "WHATSAPP-BAILEYS",
      "createdAt":        "2026-03-22T17:58:55.515Z",
      "updatedAt":        "2026-09-21T20:25:50.120Z",
      "_count":           { "Message": 51234, "Contact": 890, "Chat": 412 }
    }
  }
}
```

| Campo              | Qué es                                                                      |
|--------------------|------------------------------------------------------------------------------|
| `canal`            | Nuestra fila de `evolution_canales` (el caché, igual que en el listado).     |
| `online`           | `true` si `connectionStatus == "open"`. Es lo único que mira la mayoría.     |
| `connectionStatus` | `open` \| `connecting` \| `close`. Estado del instante según Evolution.      |
| `instancia`        | Lo que devuelve `fetchInstances` **tal cual**, sin renombrar campos.         |

`instancia` se devuelve crudo a propósito: si Evolution agrega un campo en una
versión nueva, aparece solo. La única excepción es `instancia.token`, que **se
quita**: es la apikey de la instancia y con ella cualquiera manda mensajes como
el canal, salteando la cola, el throttling y el registro en `evolution_mensajes`.

Un canal `close` con `disconnectionReasonCode: 401` está **deslogueado** y no se
arregla reintentando: necesita que alguien escanee el QR de nuevo.

### Errores

| Código | Body `error`                                        | Cuándo                                          |
|--------|-----------------------------------------------------|-------------------------------------------------|
| 400    | (ver la tabla de resolución de canal, arriba)       | Identificador ausente / inexistente.            |
| 502    | `Consultar estado del canal: cURL: ...`             | Evolution no respondió (caído, timeout, DNS).   |
| 502    | `Evolution devolvio 0 instancias para este canal`   | La apikey del canal ya no corresponde a ninguna instancia. |

---

## Ejemplos

### curl — listar los canales de un proyecto

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/evolution/canales?proyecto_slug=vigicom"
```

### curl — ver si un canal está conectado ahora

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/evolution/canales?canal_slug=databox-bot"
```

---

## Equivalencias con el legacy

| Legacy (`/v2/evolution`)         | Acá                                              |
|----------------------------------|--------------------------------------------------|
| `POST canalEstado` + `canal=X`   | `GET /v4/evolution/canales?canal_slug=X`         |
| —                                | `GET /v4/evolution/canales` (el listado es nuevo)|

Qué cambia respecto del v2, además de la URL:

- **Auth**: header `Authorization: Bearer <apikey>` en vez de `apikey` en el body.
- **Envelope**: `{"ok":true,"data":{...}}` en vez de `{codigo, mensaje, cuerpo}`
  con códigos propios (603/604/605).
- **Status HTTP reales**: el legacy contestaba `200` con el error de cURL adentro
  del cuerpo; acá un canal caído es `502` y un canal inexistente es `400`.
- **El token no sale** en la respuesta.

El `/v2` sigue funcionando: esto es aditivo y no lo toca.

---

## Referencias

- Tabla: `evolution_canales` — schema en [db/schema.sql](../../../db/schema.sql).
- Lógica compartida: [cloud/api/lib/evolution_grupos.php](../../../cloud/api/lib/evolution_grupos.php).
- Cliente HTTP de Evolution: [cloud/api/lib/evolution_api.php](../../../cloud/api/lib/evolution_api.php).
- Cron que refresca el caché de estado: [cloud/jobs/evolution_canales_actualizar_estados.php](../../../cloud/jobs/evolution_canales_actualizar_estados.php).
- ABM interno de canales: [cloud/api/evolutioncanales.php](../../../cloud/api/evolutioncanales.php).
- Grupos de un canal: [grupos.md](grupos.md) · Miembros: [grupoMiembros.md](grupoMiembros.md).
