# /v4/claro/sims

> URL pública de esta documentación: <https://api.databox.net.ar/v4/claro/sims.md>

Microservicio de ingesta de SIMs Claro. Hace **UPSERT por ICC** contra la
tabla `claro_sims` (schema en [db/schema.sql](../../../db/schema.sql)),
aceptando JSON con **todas** las columnas del catálogo.

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo
vía HTTP). El ABM interno del panel usa su propio endpoint
[cloud/api/claro_sims.php](../../../cloud/api/claro_sims.php); el sync legacy
alimentado por `openclaw` sigue viviendo en
[cloud/api/claro_sims_sync.php](../../../cloud/api/claro_sims_sync.php) (solo
recibe CSV y toca un subset de columnas). Este microservicio los reemplaza y
**es más potente**: acepta JSON, todas las columnas y batch idempotente.

**Filosofía**: nunca se hace `INSERT` a ciegas — siempre `UPSERT` por
`icc`. Si la fila ya existe se actualiza; si no, se crea. Volver a llamar
con el mismo payload es una no-op perfecta.

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/claro/sims`

| Método | Path             | Uso                                          |
|--------|------------------|----------------------------------------------|
| POST   | `/v4/claro/sims` | UPSERT una o varias SIMs por `icc`.          |

En el futuro se agregarán más verbos sobre el mismo endpoint (GET por
`id`/`icc`, DELETE, PATCH parcial). Cualquier otro método hoy devuelve
`405 Metodo no soportado`.

La ruta va **sin extensión**: la resuelve el `.htaccess` de `api/`, que cubre
todo el árbol con un rewrite interno al `.php`.

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

Cada llamada exitosa incrementa `aplicaciones.usos` (best-effort — un fallo
en el contador nunca tumba el request).

---

## POST — UPSERT de SIMs

Content-Type: `application/json; charset=utf-8`.

### Body (tres formas equivalentes)

Podés mandar **una sola SIM**, un **array plano**, o un **wrapper `{sims:[]}`**:

```json
{ "icc": "8954312212097818037", "estado": "Activada" }
```

```json
{ "sims": [
    { "icc": "8954312212097818037", "estado": "Activada" },
    { "icc": "8954312212097818038", "estado": "Desactivada" }
]}
```

```json
[
    { "icc": "8954312212097818037", "estado": "Activada" },
    { "icc": "8954312212097818038", "estado": "Desactivada" }
]
```

### Campos aceptados

Cada SIM es un objeto JSON con los siguientes campos. `icc` es el único
obligatorio (es la clave de UPSERT). Los demás son opcionales; los que no
vengan **no se tocan** si la fila ya existe, o quedan en `NULL` si es
un INSERT.

| Campo             | Tipo                 | Obligatorio | Notas                                                                                          |
|-------------------|----------------------|-------------|------------------------------------------------------------------------------------------------|
| `icc`             | string(25)           | **Sí**      | Clave del UPSERT (UNIQUE `uk_claro_sims_icc`). Sin `icc` → la fila se salta y suma `sin_icc`.  |
| `nombre`          | string(255)          | No          | Nombre humano editable en el ABM.                                                              |
| `alias`           | string(100)          | No          | Alias / etiqueta corta.                                                                        |
| `linea`           | string(30)           | No          | Número corto (típicamente el `msisdn` sin prefijo `549`).                                      |
| `estado`          | string(40)           | No          | Ej. `"Activada"`, `"Desactivada"`, `"Suspendida"`, `"Retirada"`.                               |
| `estado_gprs`     | string(40)           | No          | Ej. `"conectado"`, `"desconectado"`.                                                           |
| `estado_lte`      | string(40)           | No          | Ej. `"habilitado"`, `"deshabilitado"`.                                                         |
| `limite_datos`    | string(40)           | No          | String tal cual reporta el portal (ej. `"100 MB"`).                                            |
| `consumo_datos`   | string(40)           | No          | Idem (ej. `"37 MB"`).                                                                          |
| `imei`            | string(30)           | No          | IMEI del equipo asociado.                                                                      |
| `msisdn`          | string(30)           | No          | Número completo con prefijo país (ej. `"5492646176179"`).                                      |
| `en_uso`          | `"si"`\|`"no"`\|null | No          | Cualquier otro valor se normaliza a `null` (no rompe el batch).                                |
| `actualizado`     | datetime             | No          | `YYYY-MM-DD HH:MM[:SS]` o ISO8601. **Default si el campo no viene: `NOW()`**.                  |
| `ultimo_trafico`  | datetime             | No          | Timestamp del último tráfico detectado (formato como `actualizado`).                           |
| `tags`            | string(500)          | No          | Etiquetas libres para segmentación / búsqueda.                                                 |

**Nota sobre `actualizado`**: si **no** incluís la clave en el payload, el
endpoint lo setea a `NOW()` (comportamiento default, útil para "acabo de
scrapear esta fila"). Si querés preservar el valor existente, mandá
`"actualizado": null` explícitamente — eso deja intacta la columna en el
UPDATE.

**Notas sobre sanitización**:

- Todos los strings se recortan (`trim`) y se truncan a la longitud máxima
  de la columna (sin error).
- Un string vacío o `null` se guarda como `NULL`.
- Datetimes se parsean con `strtotime()` y se normalizan a
  `YYYY-MM-DD HH:MM:SS`; strings inparseables → `NULL`.

### Respuesta — 201 Created (al menos un insert)

```json
{
  "ok": true,
  "data": {
    "stats": {
      "recibidas":    2,
      "insertados":   1,
      "actualizados": 1,
      "sin_icc":      0,
      "aplicacion":   { "id": 7, "nombre": "openclaw-eva" },
      "ejecutado_en": "2026-07-28 15:42:11"
    },
    "items": [
      { "index": 0, "accion": "insertado",   "id": 812, "icc": "8954312212097818037" },
      { "index": 1, "accion": "actualizado", "id": 137, "icc": "8954312212097818038" }
    ]
  }
}
```

### Respuesta — 200 OK (sólo actualizaciones)

Misma forma que arriba pero `stats.insertados = 0`. HTTP `200` en vez de
`201` para que clientes que sólo miran el status code puedan distinguir
"todo era ya conocido" de "hubo altas nuevas".

### Manejo de errores por fila

El microservicio nunca tumba un batch entero por una fila mala:

- Fila sin `icc` → suma `sin_icc`, se agrega a `items` con
  `{"accion":"skip","error":"ICC vacio"}`.
- Fila que no es objeto (ej. string suelto dentro del array) →
  `{"accion":"skip","error":"Fila no es objeto"}`.
- `en_uso` con valor inválido (ej. `"tal vez"`) → se normaliza a `null`,
  la fila se procesa igual.

Las excepciones inesperadas de PDO (que no sean colisión de UNIQUE, que se
maneja como race y se resuelve como UPDATE) sí propagan a `500`.

### Errores globales

| Código | Body `error`                                             | Cuándo                                                    |
|--------|----------------------------------------------------------|-----------------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                               | El body no es JSON válido.                                |
| 400    | `Body vacio: se esperaba un objeto o un array de SIMs`   | El JSON parseó pero está vacío.                           |
| 401    | `Bearer token ausente`                                   | Sin header `Authorization`.                               |
| 401    | `API key desconocida`                                    | La apikey no matchea ninguna fila de `aplicaciones`.      |
| 401    | `Aplicacion deshabilitada`                               | `aplicaciones.habilitada != '1'`.                         |
| 405    | `Metodo no soportado`                                    | Método distinto de POST.                                  |
| 500    | `<mensaje de la excepción>`                              | Falla inesperada (PDO, etc.).                             |

---

## Ejemplos

### curl — UPSERT de una SIM

```bash
curl -X POST https://api.databox.net.ar/v4/claro/sims \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "icc":           "8954312212097818037",
    "nombre":        "Gateway sucursal Belgrano",
    "linea":         "2646176179",
    "msisdn":        "5492646176179",
    "estado":        "Activada",
    "estado_gprs":   "conectado",
    "estado_lte":    "habilitado",
    "limite_datos":  "100 MB",
    "consumo_datos": "37 MB",
    "imei":          "358794102345678",
    "en_uso":        "si",
    "tags":          "planta-baja,router"
  }'
```

### curl — UPSERT batch (varias SIMs)

```bash
curl -X POST https://api.databox.net.ar/v4/claro/sims \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "sims": [
      { "icc": "8954312212097818037", "estado": "Activada",    "consumo_datos": "37 MB" },
      { "icc": "8954312212097818038", "estado": "Desactivada", "consumo_datos": "0 MB"  }
    ]
  }'
```

### curl — actualización parcial (sólo consumo)

Los campos que no se manden **no se pisan**:

```bash
curl -X POST https://api.databox.net.ar/v4/claro/sims \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '[
    { "icc": "8954312212097818037", "consumo_datos": "48 MB", "ultimo_trafico": "2026-07-28 14:20:00" }
  ]'
```

### curl — preservar `actualizado` existente

Por default el endpoint pisa `actualizado` con `NOW()`. Para preservar el
valor existente:

```bash
curl -X POST https://api.databox.net.ar/v4/claro/sims \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{ "icc": "8954312212097818037", "estado": "Suspendida", "actualizado": null }'
```

---

## Diferencias con `cloud/api/claro_sims_sync.php` (legacy openclaw)

| Aspecto              | `claro_sims_sync.php` (legacy)                                 | `/v4/claro/sims` (este endpoint)                          |
|----------------------|----------------------------------------------------------------|-----------------------------------------------------------|
| Formato del body     | CSV (raw body o multipart)                                     | JSON (objeto, array plano, o `{sims:[]}`)                 |
| Columnas soportadas  | `linea`, `icc`, `estado`, `consumo_datos`, `msisdn`            | **Todas** las columnas de `claro_sims`                    |
| Semántica            | UPSERT por ICC                                                 | UPSERT por ICC                                            |
| Auth                 | Bearer contra `aplicaciones`                                   | Bearer contra `aplicaciones`                              |
| Batch                | Sí (CSV con múltiples filas)                                   | Sí (array o wrapper)                                      |
| Idempotencia         | Sí                                                             | Sí                                                        |
| Extensible           | No — sólo `POST` con CSV                                       | Sí — dispatcher por método (GET/DELETE/PATCH en el futuro)|

El endpoint legacy sigue funcionando hasta que se retire `openclaw`.

---

## Fuera del alcance (por ahora)

Están planeados pero no implementados todavía:

- `GET /v4/claro/sims?id=N` — consulta por id.
- `GET /v4/claro/sims?icc=X` — consulta por ICC.
- `GET /v4/claro/sims?tags=…&estado=…` — listado con filtros.
- `DELETE /v4/claro/sims?icc=X` — baja definitiva.
- `PATCH /v4/claro/sims?icc=X` — modificación parcial estricta (misma lógica
  de `POST` pero fallando si la fila no existe).

Cuando se agreguen, se enganchan en el dispatcher del método sin tocar el
bloque de auth.

---

## Referencias

- Tabla destino: `claro_sims` — schema en [db/schema.sql](../../../db/schema.sql).
- ABM interno del panel: [cloud/api/claro_sims.php](../../../cloud/api/claro_sims.php).
- Sync legacy openclaw (CSV): [cloud/api/claro_sims_sync.php](../../../cloud/api/claro_sims_sync.php).
- Coordinación del "pedido de sync" con openclaw: [cloud/api/claro_sims_sync_pedido.php](../../../cloud/api/claro_sims_sync_pedido.php).
- Microservicios v4 vecinos (misma estructura de auth):
  [/v4/telegram/mensajes.md](../telegram/mensajes.md),
  [/v4/aws/mensajes.md](../aws/mensajes.md),
  [/v4/evolution/mensajes.md](../evolution/mensajes.md).
