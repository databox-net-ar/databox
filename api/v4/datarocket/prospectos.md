# `/v4/datarocket/contactos`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/contactos.md>

Microservicio CRUD del **CRM Datarocket** sobre la tabla `datarocket_contactos`
(ver [db/schema.sql](../../../db/schema.sql)). Un unico archivo `.php`
([contactos.php](contactos.php)) que sirve los cinco verbos HTTP del recurso —
sin framework ni router aparte.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). El `.htaccess` de `api/v4/` mapea URLs sin extension al
archivo `.php` correspondiente, asi que ambas formas son equivalentes:

```
GET https://api.databox.net.ar/v4/datarocket/contactos
GET https://api.databox.net.ar/v4/datarocket/contactos.php
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo
via HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket
> Contactos) usa su propio endpoint [cloud/api/datarocketcontactos.php](../../../cloud/api/datarocketcontactos.php).
Ambos caminos escriben en la misma tabla (`datarocket_contactos`) con las
mismas reglas de sanitizacion, obligatorios y defaults; la diferencia es la
capa de auth (permisos de sesion vs. Bearer estatico) y que el listado v4
no publica el bloque `stats` que usa el panel.

---

## Autenticacion

Bearer estatico contra `aplicaciones.apikey` (misma tabla que el resto del
stack). El header debe llegar como:

```
Authorization: Bearer <apikey>
```

Cualquier apikey habilitada pasa — no hay scope por endpoint. Cada llamada
exitosa incrementa `aplicaciones.usos` (best-effort).

Errores devueltos por el auth:

| Codigo | Cuerpo                                               |
| ------ | ---------------------------------------------------- |
| 401    | `{"ok": false, "error": "Bearer token ausente"}`     |
| 401    | `{"ok": false, "error": "API key desconocida"}`      |
| 401    | `{"ok": false, "error": "Aplicacion deshabilitada"}` |

Apache no siempre propaga `Authorization` — el handler chequea
`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` y como ultimo recurso
`getallheaders()`.

---

## Contrato de respuesta

Todas las respuestas siguen el shape unificado del stack:

```json
{ "ok": true,  "data": <payload> }
{ "ok": false, "error": "<mensaje>" }
```

Body-in y body-out son JSON `utf-8` (`Content-Type: application/json`).

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/datarocket/contactos`

| Metodo | Path                            | Uso                                 |
| ------ | ------------------------------- | ----------------------------------- |
| GET    | `/v4/datarocket/contactos`      | Listado con filtros (query string). |
| GET    | `/v4/datarocket/contactos?id=N` | Consulta individual del contacto N. |
| POST   | `/v4/datarocket/contactos`      | Alta (JSON body).                   |
| PUT    | `/v4/datarocket/contactos?id=N` | Modificacion del contacto N (JSON). |
| DELETE | `/v4/datarocket/contactos?id=N` | Baja definitiva del contacto N.     |

Cualquier otro metodo devuelve `405 Metodo no soportado`.

---

## Modelo de datos

Columnas de `datarocket_contactos` expuestas por la API (mismo shape en listado
y consulta individual):

| Columna              | Tipo         | Notas                                                                                                                                                       |
| -------------------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                 | int          | PK, auto-increment.                                                                                                                                         |
| `uuid`               | varchar(255) | Identificador externo. Si no se manda en el alta, se autogenera con `bin2hex(random_bytes(16))` (32 chars hex).                                             |
| `nombre`             | varchar(255) | **Derivado, no se acepta del cliente.** Se calcula como `persona_nombre` si `tipo='persona'` y como `empresa_nombre` si `tipo='empresa'`. Lo que se mande en este campo se ignora. |
| `empresa_nombre`     | varchar(255) | **Obligatorio si `tipo='empresa'`** (alimenta a `nombre`). En un contacto persona es opcional y significa dónde trabaja.                                     |
| `empresa_rubro`      | varchar(255) |                                                                                                                                                             |
| `empresa_actividad`  | varchar(255) |                                                                                                                                                             |
| `empresa_cargo`      | varchar(255) |                                                                                                                                                             |
| `persona_nombre`     | varchar(255) | **Obligatorio si `tipo='persona'`** (alimenta a `nombre`). En un contacto empresa es opcional y significa quién atiende.                                     |
| `persona_genero`     | varchar(1)   | Passthrough (tipicamente `M` / `F` / vacio).                                                                                                                |
| `persona_nacimiento` | varchar(255) | Fecha de nacimiento en texto libre (no se valida formato).                                                                                                  |
| `persona_dni`        | varchar(255) |                                                                                                                                                             |
| `domicilio`          | varchar(255) |                                                                                                                                                             |
| `ciudad`             | varchar(255) |                                                                                                                                                             |
| `ubicacion`          | varchar(255) | Coordenadas / geopoint textual.                                                                                                                             |
| `localidad`          | varchar(255) |                                                                                                                                                             |
| `provincia`          | varchar(255) |                                                                                                                                                             |
| `pais`               | varchar(255) |                                                                                                                                                             |
| `telefono`           | varchar(255) | **Normalizado a solo digitos** en el alta / modificacion (ver reglas de sanitizacion).                                                                      |
| `celular`            | varchar(255) | **Normalizado a solo digitos** en el alta / modificacion.                                                                                                   |
| `whatsapp`           | varchar(255) | **Normalizado a solo digitos** en el alta / modificacion. Formato final: E.164 sin `+` (compatible con [/v4/evolution/mensajes](../evolution/mensajes.md)). |
| `correo`             | varchar(255) |                                                                                                                                                             |
| `web`                | varchar(255) |                                                                                                                                                             |
| `facebook`           | varchar(255) |                                                                                                                                                             |
| `instagram`          | varchar(255) |                                                                                                                                                             |
| `tiktok`             | varchar(255) |                                                                                                                                                             |
| `comentarios`        | varchar(500) |                                                                                                                                                             |
| `tags`               | varchar(500) | Tags libres para segmentacion / busqueda.                                                                                                                   |
| `listas`             | varchar(500) |                                                                                                                                                             |
| `registrado`         | datetime     | Fecha de alta. Si no se manda, default = `NOW()` en `America/Argentina/Buenos_Aires`.                                                                       |

> **Baja de campos (migracion 20260817_1500).** `verificacion`, `estado`,
> `error`, `completado` y `suscripciones` fueron eliminados de
> `datarocket_contactos`. Ya no se devuelven en las respuestas, no filtran, no
> ordenan y se ignoran si vienen en el body de un POST / PUT. El estado de
> envio vive ahora en `datarocket_interacciones` y la suscripcion a listas en
> la puente `datarocket_contactos_listas` (expuesta como `lista_ids` /
> `lista_nombres`).

### Reglas de sanitizacion (POST / PUT)

- Strings vacios o solo whitespace -> `NULL`.
- Ints vacios -> `NULL`.
- Datetimes aceptan `YYYY-MM-DDTHH:MM`, `YYYY-MM-DD HH:MM` y
  `YYYY-MM-DD HH:MM:SS` (se normalizan a `Y-m-d H:i:s`). Formato invalido ->
  `NULL` (y se aplica el default si corresponde).
- **`telefono`, `celular` y `whatsapp`**: se eliminan todos los caracteres que
  no sean digitos (`0-9`). Ejemplos:
  - `"+54 9 11 3344-5566"` -> `"5491133445566"`.
  - `"(011) 4333-4444"` -> `"01143334444"`.
  - Si despues del strip queda vacio -> `NULL`. Aplica la misma regla en el
    ABM cloud interno ([cloud/api/datarocketcontactos.php](../../../cloud/api/datarocketcontactos.php))
    para que ambos caminos escriban comparable.
- Los campos `varchar(N)` se truncan a `N` en silencio si vienen mas largos.
- El `uuid` no es reutilizable en el alta: si se manda, se persiste tal cual;
  si no viene, se genera uno nuevo. No hay UNIQUE en la BD, asi que la API
  no reporta colisiones.

---

## `GET /v4/datarocket/contactos` — Listado

### Query params

Todos son opcionales; combinables con `AND`.

| Parametro        | Tipo   | Notas                                                                                                                                                 |
| ---------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `codigo`         | int    | Filtra por `id` exacto (equivalente a la consulta individual pero devuelve envelope de listado).                                                      |
| `persona_genero` | string | Match exacto contra `persona_genero`.                                                                                                                 |
| `pais`           | string | Match exacto contra `pais`.                                                                                                                           |
| `provincia`      | string | Match exacto contra `provincia`.                                                                                                                      |
| `correo`         | string | `LIKE '%<v>%'` sobre `correo`.                                                                                                                        |
| `celular`        | string | `LIKE '%<v>%'` sobre `celular`.                                                                                                                       |
| `desde`          | date   | `YYYY-MM-DD`. Filtra `registrado >= '<desde> 00:00:00'`.                                                                                              |
| `hasta`          | date   | `YYYY-MM-DD`. Filtra `registrado <= '<hasta> 23:59:59'`.                                                                                              |
| `q`              | string | Busqueda difusa: `LIKE '%<q>%'` sobre `nombre`, `empresa_nombre`, `correo`, `telefono`, `celular`, `whatsapp`, `persona_dni`, `uuid`.                 |
| `order_by`       | string | Default `id`. Whitelist: `id`, `nombre`, `empresa_nombre`, `correo`, `registrado`, `pais`, `provincia`. Valor fuera de la lista cae a `id`. |
| `dir`            | string | `asc` \                                                                                                                                               | `desc`. Default `desc`. |
| `limite`         | int    | Default `100`. Clampeado a `[1, 1000]`.                                                                                                               |

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 42,
    "items": [
      {
        "id": 148286,
        "uuid": "a3f9c1b2d4e5f6a7b8c9d0e1f2a3b4c5",
        "nombre": "Juan Perez",
        "empresa_nombre": "Acme SA",
        "correo": "juan@acme.com",
        "celular": "5491133445566",
        "whatsapp": "5491133445566",
        "registrado": "2026-07-27 14:32:07"
      }
    ]
  }
}
```

`total` es la cantidad de filas devueltas (post-`LIMIT`), no el total absoluto
en la tabla. Cada item trae todas las columnas del modelo (arriba omitidas por
brevedad).

### Ejemplo `curl`

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/contactos?q=juan&pais=Argentina&limite=50&order_by=registrado&dir=desc"
```

---

## `GET /v4/datarocket/contactos?id=N` — Consulta individual

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "id": 148286,
    "uuid": "a3f9c1b2d4e5f6a7b8c9d0e1f2a3b4c5",
    "nombre": "Juan Perez",
    "empresa_nombre": "Acme SA",
    "empresa_rubro": "Retail",
    "empresa_actividad": null,
    "empresa_cargo": "Gerente",
    "persona_nombre": null,
    "persona_genero": "M",
    "persona_nacimiento": "1985-04-12",
    "persona_dni": "30123456",
    "domicilio": "Av. Corrientes 1234",
    "ciudad": "CABA",
    "ubicacion": null,
    "localidad": "Balvanera",
    "provincia": "Buenos Aires",
    "pais": "Argentina",
    "telefono": "01143334444",
    "celular": "5491133445566",
    "whatsapp": "5491133445566",
    "correo": "juan@acme.com",
    "web": "https://acme.com",
    "facebook": null,
    "instagram": null,
    "tiktok": null,
    "comentarios": "Contacto interesado en el plan enterprise",
    "tags": "vip,enterprise",
    "listas": "clientes,newsletter-2026",
    "registrado": "2026-07-27 14:32:07"
  }
}
```

### Errores

| Codigo | Body `error`             | Cuando                                |
| ------ | ------------------------ | ------------------------------------- |
| 404    | `Contacto no encontrado` | El `id` pasado no existe en la tabla. |

---

## `POST /v4/datarocket/contactos` — Alta

Content-Type: `application/json; charset=utf-8`.

### Body

Cualquier subconjunto de las columnas del modelo (ver tabla arriba). Todos los
campos son opcionales — es valido mandar un body vacio `{}` y obtener un
contacto con `uuid` autogenerado y `registrado = NOW()`.

Campos con tratamiento especial:

- **`uuid`**: si viene se persiste; si no viene, se genera con
  `bin2hex(random_bytes(16))` (32 caracteres hex).
- **`registrado`**: si viene y es una fecha valida, se persiste; si no viene o
  es invalida, se defaultea a `NOW()` en `America/Argentina/Buenos_Aires`.

### Respuesta (201)

```json
{
  "ok": true,
  "data": {
    "id": 148287,
    "uuid": "a3f9c1b2d4e5f6a7b8c9d0e1f2a3b4c5",
    "registrado": "2026-07-27 14:32:07"
  }
}
```

Se devuelven `id`, `uuid` y `registrado` porque son los tres campos que el
caller no siempre conoce a priori (el `id` lo asigna la BD, el `uuid` y el
`registrado` los puede haber defaulteado el sanitizador). Para releer el
contacto completo hacer `GET /v4/datarocket/contactos?id=<id>`.

### Errores

| Codigo | Body `error`                                                                  | Cuando                                        |
| ------ | ----------------------------------------------------------------------------- | --------------------------------------------- |
| 400    | `Cuerpo no es JSON valido`                                                    | El body no es JSON valido.                    |
| 400    | `El tipo es obligatorio (persona o empresa).`                                 | Falta `tipo` o no es uno de los dos valores.  |
| 400    | `El nombre de la persona es obligatorio para un contacto de tipo persona.`    | `tipo='persona'` sin `persona_nombre`.        |
| 400    | `El nombre de la empresa es obligatorio para un contacto de tipo empresa.`    | `tipo='empresa'` sin `empresa_nombre`.        |
| 500    | `<mensaje de la excepcion>`                                                   | Falla inesperada (PDO, etc.).                 |

### Ejemplo `curl`

```bash
curl -X POST https://api.databox.net.ar/v4/datarocket/contactos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "nombre":   "Juan Perez",
    "empresa_nombre":  "Acme SA",
    "correo":   "juan@acme.com",
    "celular":  "5491133445566",
    "whatsapp": "5491133445566",
    "pais":     "Argentina",
    "provincia":"Buenos Aires",
    "ciudad":   "CABA",
    "tags":     "vip,enterprise"
  }'
```

---

## `PUT /v4/datarocket/contactos?id=N` — Modificacion

Content-Type: `application/json; charset=utf-8`.

**Reemplazo total** de las columnas mutables (todas menos `id` y `uuid`). Los
campos que **no** vengan en el body se persisten como `NULL` — no es un
patch parcial.

Si necesitas dejar un campo como estaba, incluilo en el body con su valor
actual (obtenido de un `GET ?id=N` previo).

### Body

Mismo shape del `POST`, sin `uuid` (el `uuid` se preserva tal cual esta en la
BD; no se puede cambiar via PUT).

### Respuesta (200)

```json
{
  "ok": true,
  "data": { "id": 148287 }
}
```

### Errores

| Codigo | Body `error`                                                                  | Cuando                                        |
| ------ | ----------------------------------------------------------------------------- | --------------------------------------------- |
| 400    | `Falta id (int > 0)`                                                          | Query string sin `id` valido.                 |
| 400    | `Cuerpo no es JSON valido`                                                    | El body no es JSON valido.                    |
| 400    | `El tipo es obligatorio (persona o empresa).`                                 | Falta `tipo` o no es uno de los dos valores.  |
| 400    | `El nombre de la persona es obligatorio para un contacto de tipo persona.`    | `tipo='persona'` sin `persona_nombre`.        |
| 400    | `El nombre de la empresa es obligatorio para un contacto de tipo empresa.`    | `tipo='empresa'` sin `empresa_nombre`.        |
| 404    | `Contacto no encontrado`                                                      | El `id` no existe.                            |
| 500    | `<mensaje de la excepcion>`                                                   | Falla inesperada (PDO, etc.).                 |

### Ejemplo `curl`

```bash
curl -X PUT "https://api.databox.net.ar/v4/datarocket/contactos?id=148287" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "nombre":   "Juan Perez",
    "empresa_nombre":  "Acme SA",
    "correo":   "juan.perez@acme.com",
    "celular":  "5491133445566",
    "whatsapp": "5491133445566",
    "pais":     "Argentina",
    "provincia":"Buenos Aires",
    "ciudad":   "CABA"
  }'
```

---

## `DELETE /v4/datarocket/contactos?id=N` — Baja

Elimina la fila **definitivamente** — no hay soft-delete. Las actividades
asociadas (`datarocket_actividades.contacto_id = N`) quedan huerfanas: la
tabla `datarocket_actividades` no tiene FK con `ON DELETE CASCADE` sobre
`datarocket_contactos`, asi que sus filas siguen existiendo apuntando a un
`contacto_id` que ya no existe. Si el caller necesita limpiar historial,
tiene que hacerlo aparte.

### Respuesta (200)

```json
{
  "ok": true,
  "data": { "id": 148287 }
}
```

### Errores

| Codigo | Body `error`             | Cuando                        |
| ------ | ------------------------ | ----------------------------- |
| 400    | `Falta id (int > 0)`     | Query string sin `id` valido. |
| 404    | `Contacto no encontrado` | El `id` no existe.            |

### Ejemplo `curl`

```bash
curl -X DELETE "https://api.databox.net.ar/v4/datarocket/contactos?id=148287" \
  -H "Authorization: Bearer $APIKEY"
```

---

## Referencias

- Tabla destino: `datarocket_contactos` — schema en [db/schema.sql](../../../db/schema.sql).
- ABM interno equivalente (usado por el panel cloud): [cloud/api/datarocketcontactos.php](../../../cloud/api/datarocketcontactos.php).
- Historial de actividades sobre cada contacto: `datarocket_actividades` (schema en [db/schema.sql](../../../db/schema.sql), ABM en [cloud/api/datarocketactividades.php](../../../cloud/api/datarocketactividades.php)).
- Helper de auth por Bearer: [cloud/api/lib/apikey_auth.php](../../../cloud/api/lib/apikey_auth.php) (el v4 rueda la logica inline para no arrastrar dependencias, pero el shape es identico).
- Microservicios hermanos del mismo `v4/`: [/v4/aws/mensajes](../aws/mensajes.md), [/v4/evolution/mensajes](../evolution/mensajes.md).
