# /v4/evolution/mensajes

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

Cualquier otro método devuelve `405 Metodo no soportado`. La ruta sin
extensión se resuelve via `.htaccess` del padre `api/v4/` (rewrite a `.php`).

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

| Campo            | Tipo         | Obligatorio | Notas                                                                     |
|------------------|--------------|-------------|---------------------------------------------------------------------------|
| `proyecto_id`    | int          | Sí          | FK a `proyectos.id`. Alias legacy: `proyecto`.                            |
| `canal_id`       | int          | Sí          | FK a `evolution_canales.id`. Alias legacy: `canal`.                       |
| `remite`         | string(255)  | Sí          | Número emisor (ej. `5491133445566`).                                      |
| `destino`        | string(255)  | Sí          | Número destinatario en E.164 sin `+`.                                     |
| `cuerpo`         | string       | Sí          | Texto del mensaje. Para plantillas se auto-completa desde `plantilla_id`. |
| `plantilla_id`   | int          | No          | FK a `datarocket_plantillas.id`. Alias legacy: `plantilla`.               |
| `remitente`      | string(255)  | No          | Nombre humano del emisor (para display).                                  |
| `destinatario`   | string(255)  | No          | Nombre humano del destinatario.                                           |
| `prioridad`      | int (1–5)    | No          | Default `3` (Media). `5` = Muy Alta (sale primero), `1` = Muy Baja.       |
| `asunto`         | string(255)  | No          | WhatsApp no tiene subject; el sender lo antepone en negrita al cuerpo.    |
| `formato`        | string(20)   | No          | Default `'texto'`. Otros: `'imagen'`, `'video'`, `'audio'`, `'url'`.      |
| `adjunto`        | string(500)  | No          | URL/path del adjunto cuando `formato` != `'texto'`.                       |
| `tags`           | string(255)  | No          | Etiquetas libres para segmentación / búsqueda.                            |
| `fecha`          | datetime     | No          | `YYYY-MM-DD HH:MM[:SS]`. Default: `NOW()` en `America/Argentina/Buenos_Aires`. |
| `encolado`       | datetime     | No          | Default: mismo valor que `fecha`.                                         |
| `programado`     | datetime     | No          | Cuándo debe salir. Default: mismo valor que `fecha` (envío inmediato).    |

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

### curl — encolar

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
    "proyecto_id": 12,
    "canal_id":    42,
    "remite":      "5491133445566",
    "destino":     "5491199887766",
    "cuerpo":      "Recordatorio: cita mañana 10:00.",
    "programado":  "2026-07-26 09:00:00"
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
