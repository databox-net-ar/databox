# `/v4/datarocket/prospectos`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/prospectos.md>

Microservicio del **CRM Datarocket** sobre la tabla `datarocket_prospectos`
(ver [db/schema.sql](../../../db/schema.sql)). Un unico archivo `.php`
([prospectos.php](prospectos.php)) que sirve todos los verbos del recurso — sin
framework ni router aparte.

Las operaciones que motivan el microservicio:

| Quiero…                                    | Uso                                                   |
| ------------------------------------------ | ----------------------------------------------------- |
| Dar de alta un prospecto desde afuera      | `POST /v4/datarocket/prospectos`                      |
| Listar los prospectos                      | `GET /v4/datarocket/prospectos`                       |
| Saber si un prospecto ya existe            | `GET /v4/datarocket/prospectos?verificar=1`           |
| Corregirle un campo suelto                 | `PATCH /v4/datarocket/prospectos?id=N`                |

**Un prospecto no se puede dar de alta si su `correo` o su `celular` ya estan
registrados.** El `POST` corta con `409`; `?verificar=1` permite preguntarlo
antes. Ver [Unicidad de correo y celular](#unicidad-de-correo-y-celular).

**Para seleccionar el pais, la provincia y la localidad se usa
[/v4/databox/ubicaciones](../databox/ubicaciones.md).** Este endpoint recibe
`pais_id` / `provincia_id` / `localidad_id` (ids del catalogo geografico, no
texto) y rechaza con `400` los que no existan; `ubicaciones` es el que traduce
un nombre a esos ids, con la misma apikey. Ver
[Seleccionar pais, provincia y localidad](#seleccionar-pais-provincia-y-localidad).

> **Renombrado el 2026-08-17.** Este recurso se llamaba `/v4/datarocket/contactos`
> hasta la migracion `20260817_2700`. Las claves que antes eran `contacto_*`
> ahora son `prospecto_*`. Si tu integracion lee esas claves, hay que
> actualizarla.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). El `.htaccess` de `api/v4/` mapea URLs sin extension al
archivo `.php` correspondiente, asi que ambas formas son equivalentes:

```
GET https://api.databox.net.ar/v4/datarocket/prospectos
GET https://api.databox.net.ar/v4/datarocket/prospectos.php
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo
via HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket
> Prospectos) usa su propio endpoint
[cloud/api/datarocketprospectos.php](../../../cloud/api/datarocketprospectos.php).
Ambos caminos escriben en la misma tabla con las mismas reglas de sanitizacion;
la diferencia es la capa de auth (permisos de sesion vs. Bearer estatico) y que
el listado v4 no publica el bloque `stats` que usa el panel.

---

## Autenticacion

Bearer estatico contra `aplicaciones.apikey` (misma tabla que el resto del
stack). El header debe llegar como:

```
Authorization: Bearer <apikey>
```

Cualquier apikey habilitada pasa — no hay scope por endpoint. Cada llamada
exitosa incrementa `aplicaciones.usos` (best-effort).

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

Base URL: `https://api.databox.net.ar/v4/datarocket/prospectos`

| Metodo | Path                                   | Uso                                       |
| ------ | -------------------------------------- | ----------------------------------------- |
| GET    | `/v4/datarocket/prospectos`            | Listado con filtros (query string).       |
| GET    | `/v4/datarocket/prospectos?id=N`       | Consulta individual del prospecto N.      |
| GET    | `/v4/datarocket/prospectos?verificar=1`| Chequeo de existencia previa (no escribe).|
| POST   | `/v4/datarocket/prospectos`            | Alta (JSON body).                         |
| PUT    | `/v4/datarocket/prospectos?id=N`       | Reemplazo **total** del prospecto N.      |
| PATCH  | `/v4/datarocket/prospectos?id=N`       | Modificacion **parcial** del prospecto N. |
| DELETE | `/v4/datarocket/prospectos?id=N`       | Baja definitiva del prospecto N.          |

Cualquier otro metodo devuelve `405 Metodo no soportado`.

`?verificar=1` tiene prioridad sobre `?id=N`: si vienen los dos, se hace la
verificacion.

### `PUT` o `PATCH`

Los dos modifican, pero **no son intercambiables**:

| | `PUT` | `PATCH` |
| ------------------------------ | ------------------------------- | --------------------------------- |
| Campos ausentes del body       | Se guardan como `NULL`          | Se dejan como estaban             |
| Hay que mandar el prospecto entero | Si                          | No                                |
| `tipo` obligatorio en el body  | Siempre                         | Solo si lo queres cambiar         |
| Unicidad de `correo` / `celular` | Sobre el estado final         | Solo sobre lo que el body escribe |

Regla practica: **`PUT` para un formulario que postea todo el prospecto**
(es lo que hace el panel), **`PATCH` para corregir campos sueltos** — un
importador que solo trae el celular, un webhook que actualiza el cargo. Con
`PUT`, ese caso obliga a un `GET ?id=N` previo y a reenviar el prospecto
completo, con la carrera obvia si alguien lo edito en el medio.

---

## Unicidad de correo y celular

Este endpoint es la puerta de entrada de los formularios y los importadores
externos, y ahi es donde se generan los prospectos duplicados. Por eso:

- **`POST`** rechaza con `409` si el `correo` **o** el `celular` del payload ya
  estan cargados en otra fila.
- **`PUT`** aplica la misma regla, excluyendose a si mismo (un prospecto no
  choca contra su propio correo).
- **`PATCH`** la aplica solo sobre los campos que el body escribe: si el parche
  no trae `correo`, el correo heredado no se audita. Es a proposito — hay 2.876
  correos duplicados historicos, y hacer fallar por eso un `PATCH` de
  `domicilio` seria pedirle al cliente que arregle datos que no toco.
- **`GET ?verificar=1`** expone el mismo chequeo como consulta previa.

### La comparacion es sobre el valor normalizado

Nunca sobre el crudo. `celular` se lleva a 10 digitos argentinos y `correo` a
minuscula antes de buscar, con las mismas reglas que se usan para guardar (ver
[Reglas de sanitizacion](#reglas-de-sanitizacion-post--put--patch)). En la practica:

| Mandas                    | Se busca       | Choca contra lo guardado |
| ------------------------- | -------------- | ------------------------ |
| `"+54 9 11 5555-0981"`    | `1155550981`   | ✅                        |
| `"11 5555-0981"`          | `1155550981`   | ✅                        |
| `"015-5555-0981"`         | `1155550981`   | ✅                        |
| `"Juan.Perez@ACME.com"`   | `juan.perez@acme.com` | ✅                 |

El campo `consulta` de la respuesta de `?verificar=1` devuelve justamente esos
valores normalizados, para que se entienda por que matcheo.

### Los campos vacios no colisionan

La tabla tiene ~9.600 filas sin correo y ~20.500 sin celular, todas legitimas.
Solo se busca duplicado cuando el valor normalizado tiene contenido. Un alta
sin correo ni celular pasa siempre.

### Se compara `correo` contra `correo` y `celular` contra `celular`

No hay cruce entre columnas: un numero que este cargado en `whatsapp` pero no
en `celular` no bloquea el alta. En la practica cubre casi todo, porque la
migracion `20260817_2200` sincronizo `whatsapp` desde `celular`.

### Por que la regla vive en PHP y no en un UNIQUE

Los datos historicos todavia tienen **2.876 correos y 2.031 celulares
repetidos** (dev al 2026-08-18), y `correo` tiene `DEFAULT ''` con miles de
filas vacias — la cadena vacia si colisiona bajo UNIQUE, a diferencia de NULL.
El indice unico no entra hasta depurar eso, y cual de las dos filas duplicadas
sobrevive es una decision de producto, no de schema.

**Consecuencia a tener presente:** dos `POST` identicos y *simultaneos* pueden
colarse los dos, porque entre el `SELECT` y el `INSERT` no hay nada a nivel
motor que los frene. Para el uso real (altas de formulario, importadores
secuenciales) alcanza.

La migracion `20260818_1300` agrega indices **comunes** sobre `correo` y
`celular` para que la busqueda no sea un full scan de la tabla.

---

## Seleccionar pais, provincia y localidad

La ubicacion de un prospecto **no se manda como texto**. `pais_id`,
`provincia_id` y `localidad_id` son FK a los catalogos `paises`, `provincias` y
`localidades`, y el endpoint valida cada uno: un id que no exista corta con
`400` (`El país indicado no existe.` / `La provincia…` / `La localidad…`).

**El metodo para obtener esos ids es
[/v4/databox/ubicaciones](../databox/ubicaciones.md)**, que expone los tres
catalogos con la misma apikey. No hay otro camino desde afuera: el ABM del panel
tiene sus propios lookups pero autentica por sesion, no por Bearer.

### A) Ya tenes el nombre (importador, CSV)

Una sola llamada resuelve la cadena entera, porque cada item de `ubicaciones`
viaja con sus ancestros:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/databox/ubicaciones?tipo=localidades&q=quilmes"
```

```json
{"ok":true,"data":{"total":1,"items":[
  {"tipo":"localidad","id":6658,"nombre":"Quilmes","categoria":"Partido",
   "provincia_id":6,"provincia_nombre":"Buenos Aires","pais_id":1,"pais_nombre":"Argentina"}
]}}
```

Con eso el alta ya tiene los tres ids:

```json
{ "localidad_id": 6658, "provincia_id": 6, "pais_id": 1 }
```

> **El unico mapeo a tener en cuenta:** el `id` del item es el `<tipo>_id` del
> prospecto. Una localidad con `"id": 6658` se postea como
> `"localidad_id": 6658`; sus `provincia_id` y `pais_id` ya vienen con el nombre
> correcto y se copian tal cual.

La busqueda es insensible a acentos y a la eñe (`peru` encuentra `Perú`,
`canuelas` encuentra `Cañuelas`), asi que **no hace falta normalizar el texto
del CSV** antes de consultar.

### B) Estas armando un formulario (cascada de combos)

```bash
GET /v4/databox/ubicaciones                              # paises
GET /v4/databox/ubicaciones?tipo=provincias&pais_id=1    # provincias del pais elegido
GET /v4/databox/ubicaciones?tipo=localidades&provincia_id=6
```

### Los tres campos son opcionales

Si no los mandas, el prospecto queda sin ubicacion — el alta **no** falla. Solo
se validan cuando vienen con un valor.

### Alias legacy

`pais`, `provincia` y `localidad` (sin `_id`) se siguen aceptando en el body y
se siguen devolviendo en las respuestas, con **exactamente el mismo valor**: el
id del catalogo. Nunca fueron nombres. Estan por compatibilidad con las
integraciones previas a la migracion `20260815_1000`, que convirtio esas
columnas de VARCHAR a FK.

---

## Modelo de datos

Columnas de `datarocket_prospectos` expuestas por la API (mismo shape en
listado y consulta individual):

| Columna              | Tipo         | Notas                                                                                                                                                    |
| -------------------- | ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                 | int          | PK, auto-increment.                                                                                                                                      |
| `uuid`               | varchar(255) | Identificador externo. Si no se manda en el alta se autogenera como **UUID v4** RFC 4122 (36 chars con guiones).                                          |
| `tipo`               | varchar(20)  | **Obligatorio en POST y PUT.** `persona` o `empresa`. Cualquier otro valor -> 400. En `PATCH` solo se valida si el body lo trae.                          |
| `nombre`             | varchar(255) | **Derivado, no se acepta del cliente.** Se calcula como `persona_nombre` si `tipo='persona'` y como `empresa_nombre` si `tipo='empresa'`. Lo que se mande en este campo se ignora. |
| `empresa_nombre`     | varchar(255) | **Obligatorio si `tipo='empresa'`** (alimenta a `nombre`). En un prospecto persona es opcional y significa donde trabaja.                                  |
| `empresa_rubro`      | varchar(255) |                                                                                                                                                          |
| `empresa_actividad`  | varchar(255) |                                                                                                                                                          |
| `empresa_cargo`      | varchar(255) |                                                                                                                                                          |
| `persona_nombre`     | varchar(255) | **Obligatorio si `tipo='persona'`** (alimenta a `nombre`). En un prospecto empresa es opcional y significa quien atiende.                                  |
| `persona_genero`     | varchar(1)   | Passthrough (tipicamente `M` / `F` / vacio).                                                                                                              |
| `persona_nacimiento` | varchar(255) | Fecha de nacimiento en texto libre (no se valida formato).                                                                                                |
| `persona_dni`        | varchar(255) |                                                                                                                                                          |
| `domicilio`          | varchar(255) |                                                                                                                                                          |
| `ciudad`             | varchar(255) |                                                                                                                                                          |
| `ubicacion`          | varchar(255) | Coordenadas / geopoint textual.                                                                                                                          |
| `localidad_id`       | int          | FK a `localidades`. Un id inexistente -> 400. Se sigue aceptando y devolviendo como `localidad` (mismo valor) por compatibilidad.                          |
| `provincia_id`       | int          | FK a `provincias`. Idem, alias `provincia`.                                                                                                               |
| `pais_id`            | int          | FK a `paises`. Idem, alias `pais`.                                                                                                                        |
| `telefono`           | varchar(255) | Normalizado a 10 digitos argentinos.                                                                                                                      |
| `celular`            | varchar(255) | Normalizado a 10 digitos argentinos. **Participa del chequeo de unicidad.**                                                                               |
| `whatsapp`           | varchar(255) | Normalizado a 10 digitos argentinos.                                                                                                                      |
| `correo`             | varchar(255) | Normalizado a minuscula, sin espacios y validado. **Participa del chequeo de unicidad.**                                                                   |
| `web`                | varchar(255) | Host + path **sin esquema** y **todo en minuscula** (`bna.com.ar/sucursales`). Hay que anteponer `http://` al armar el link.                               |
| `facebook`           | varchar(255) |                                                                                                                                                          |
| `instagram`          | varchar(255) |                                                                                                                                                          |
| `tiktok`             | varchar(255) |                                                                                                                                                          |
| `comentarios`        | varchar(500) |                                                                                                                                                          |
| `registrado`         | datetime     | Fecha de alta. Si no se manda, default = `NOW()` en `America/Argentina/Buenos_Aires`.                                                                      |

> **¿De donde saco esos tres ids?** De
> [/v4/databox/ubicaciones](../databox/ubicaciones.md) — ver
> [Seleccionar pais, provincia y localidad](#seleccionar-pais-provincia-y-localidad).

Ademas, el listado y la consulta individual anexan las relaciones N:M:

| Clave              | Tipo       | Notas                                                                          |
| ------------------ | ---------- | ------------------------------------------------------------------------------ |
| `lista_ids`        | int[]      | Suscripciones en `datarocket_prospectos_listas`.                               |
| `lista_nombres`    | string[]   | Mismos indices que `lista_ids`, para pintar sin un fetch extra del catalogo.   |
| `etiqueta_ids`     | int[]      | Etiquetas en `datarocket_prospectos_etiquetas`.                                |
| `etiqueta_nombres` | string[]   | Mismos indices que `etiqueta_ids`.                                             |

En `POST` se aceptan `lista_ids` y `etiqueta_ids` como int[]. En `PUT` y en
`PATCH` son opcionales: si no vienen no se toca la puente; si vienen (aun `[]`)
se reemplazan por completo.

> **Baja de campos.** `verificacion`, `estado`, `error`, `completado`,
> `suscripciones` (migracion `20260817_1500`), `tags` y `listas` ya no existen
> en `datarocket_prospectos`. No se devuelven, no filtran, no ordenan y se
> ignoran si vienen en el body. El estado de envio vive en
> `datarocket_interacciones` y la suscripcion a listas en la puente
> `datarocket_prospectos_listas`.

### Reglas de sanitizacion (POST / PUT / PATCH)

- Strings vacios o solo whitespace -> `NULL`. Ints vacios -> `NULL`.
- Los `varchar(N)` se truncan a `N` en silencio si vienen mas largos.
- Datetimes aceptan `YYYY-MM-DDTHH:MM`, `YYYY-MM-DD HH:MM` y
  `YYYY-MM-DD HH:MM:SS`. Formato invalido -> `NULL` (y se aplica el default si
  corresponde).
- **`telefono`, `celular`, `whatsapp`**: se descarta **todo lo que no sea
  digito** — espacios, guiones, parentesis, puntos, `+` — y despues se llevan a
  los **10 digitos** de un numero nacional argentino: se sacan el `00`, el `0`
  de larga distancia, el `54` de pais, el `9` de movil y el `15` intercalado.
  `"+54 9 (11) 3344.5566"` -> `"1133445566"`. Si el campo trae dos numeros se
  queda con el primero valido. Lo que no llega a un nacional valido **se guarda
  igual, en digitos crudos** (hay prospectos del exterior en la tabla).
- **`correo`**: minuscula, y **lo que viene corregible se corrige en vez de
  rechazarse**. Si trae una lista se rescata la primera direccion valida
  (`"juan@x.com maria@x.com"` -> `"juan@x.com"`, sin pegarlas). Si trae algo de
  lo que **no** se puede extraer ninguna direccion -> **400**, no se descarta en
  silencio.

  | entrada | se guarda | |
  |---|---|---|
  | `" Juan @Gmail.com "` | `juan@gmail.com` | espacios tipeados |
  | `germán@m3kargentina.com.ar` | `german@m3kargentina.com.ar` | acentos |
  | `informes(a)windnet.com.ar` | `informes@windnet.com.ar` | `@` con palabras |
  | `<Juan@X.com>` / `mailto:Juan@X.com` | `juan@x.com` | envoltorios |
  | `anaacosta@gmail;.com` | `anaacosta@gmail.com` | puntuacion espuria |
  | `admin@crediguia` | **400** | falta el TLD |
  | `adanncortez1974gmail.com` | **400** | falta el `@` |
  | `Andres.yudica@hotmailcom` | **400** | typo del proveedor |

  La correccion es **determinista**: solo se arregla lo que no obliga a adivinar
  cual era la direccion. Reponer un TLD ausente o corregir `hotmailcom` seria
  inferencia, y va al `400` a proposito.
- **`web`**: host + path **sin esquema** (se saca `http://`, `https://` y
  cualquier otro, incluido el protocol-relative `//`) y **todo en minuscula**,
  path y query incluidos. `"HTTPS://WWW.Acme.com/Sucursales"` ->
  `"www.acme.com/sucursales"`. Lo que no es una URL -> `NULL`, **salvo** que
  sea un correo y `correo` venga vacio (ahi se rescata a `correo`).

  > **Ojo con el path.** Es case sensitive del lado del servidor, asi que
  > bajarlo puede dejar guardado un link que no resuelve: los vanity de
  > Facebook (`facebook.com/MENDOSUR`), los ids de acortador
  > (`w.app/InternewNetworks`, `bit.ly/3SSePnt`) y los tokens de query de
  > Instagram (`?igshid=ZDc4ODBmNjlmNQ==`). Es una decision explicita del
  > 2026-08-18: se prioriza el campo uniforme. Aplica solo a lo que entra —
  > las filas ya cargadas conservan su capitalizacion hasta que se las edite.

Reglas completas en
[cloud/api/lib/prospectos_normalizar.php](../../../cloud/api/lib/prospectos_normalizar.php),
compartidas con el ABM cloud.

---

## `GET /v4/datarocket/prospectos?verificar=1` — Verificacion de existencia

Responde si un alta con esos datos seria rechazada, **sin escribir nada**.
Corre exactamente la misma normalizacion y la misma busqueda que el `POST`, con
lo cual las dos respuestas no pueden divergir: `existe: false` implica que el
alta pasa el chequeo de unicidad (los otros obligatorios — `tipo` e identidad —
se validan aparte, en el alta).

### Query params

| Parametro   | Tipo   | Notas                                                                     |
| ----------- | ------ | ------------------------------------------------------------------------- |
| `verificar` | flag   | `1` / `true` / cualquier valor no vacio. `0`, `false`, `no` lo desactivan. |
| `correo`    | string | Se normaliza antes de buscar.                                             |
| `celular`   | string | Se normaliza antes de buscar.                                             |

Al menos uno de `correo` / `celular` tiene que venir con contenido.

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "existe": true,
    "consulta": { "correo": "juan.perez@acme.com", "celular": "1155550981" },
    "coincidencias": [
      {
        "campo": "correo",
        "valor": "juan.perez@acme.com",
        "prospecto": {
          "id": 149283,
          "uuid": "0c35179c-9a90-11f1-acf1-e61051cd3d98",
          "nombre": "Juan Perez",
          "correo": "juan.perez@acme.com",
          "celular": "1155550981",
          "registrado": "2026-08-13 16:21:00"
        }
      }
    ]
  }
}
```

- `existe`: `true` si hay al menos una coincidencia.
- `consulta`: los valores **ya normalizados** con los que se busco.
- `coincidencias`: una entrada **por campo en conflicto**. Una misma fila
  aparece dos veces si repite el correo *y* el celular. Cortado a 20 entradas —
  es informativo, un correo repetido 300 veces no tiene por que inflar la
  respuesta.

Cuando no hay conflicto:

```json
{ "ok": true, "data": { "existe": false, "consulta": {...}, "coincidencias": [] } }
```

### Errores

| Codigo | Body `error`                                                | Cuando                                          |
| ------ | ----------------------------------------------------------- | ----------------------------------------------- |
| 400    | `Hay que indicar al menos \`correo\` o \`celular\` para verificar.` | No vino ninguno de los dos.             |
| 400    | `El correo no es válido.`                                   | Vino un `correo` del que no se extrae direccion. |

### Ejemplo `curl`

```bash
curl -G "https://api.databox.net.ar/v4/datarocket/prospectos" \
  -H "Authorization: Bearer $APIKEY" \
  --data-urlencode "verificar=1" \
  --data-urlencode "correo=juan.perez@acme.com" \
  --data-urlencode "celular=+54 9 11 5555-0981"
```

---

## `GET /v4/datarocket/prospectos` — Listado

### Query params

Todos opcionales; combinables con `AND`.

| Parametro        | Tipo   | Notas                                                                                                                    |
| ---------------- | ------ | ------------------------------------------------------------------------------------------------------------------------ |
| `codigo`         | int    | Filtra por `id` exacto (devuelve envelope de listado).                                                                   |
| `persona_genero` | string | Match exacto.                                                                                                            |
| `pais_id`        | int    | Match exacto. Alias legacy: `pais`.                                                                                      |
| `provincia_id`   | int    | Match exacto. Alias legacy: `provincia`.                                                                                 |
| `correo`         | string | `LIKE '%<v>%'`. **Es busqueda parcial, no el chequeo de unicidad** — para eso usar `?verificar=1`.                        |
| `celular`        | string | `LIKE '%<v>%'`. Idem: no normaliza el valor.                                                                             |
| `desde`          | date   | `YYYY-MM-DD`. Filtra `registrado >= '<desde> 00:00:00'`.                                                                 |
| `hasta`          | date   | `YYYY-MM-DD`. Filtra `registrado <= '<hasta> 23:59:59'`.                                                                 |
| `q`              | string | Busqueda difusa sobre `nombre`, `empresa_nombre`, `correo`, `telefono`, `celular`, `whatsapp`, `persona_dni`, `uuid`.    |
| `order_by`       | string | Default `id`. Whitelist: `id`, `nombre`, `empresa_nombre`, `correo`, `registrado`, `pais_id`, `provincia_id`. Fuera de la lista cae a `id`. |
| `dir`            | string | `asc` / `desc`. Default `desc`.                                                                                          |
| `limite`         | int    | Default `100`. Clampeado a `[1, 1000]`.                                                                                  |

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 2,
    "items": [
      {
        "id": 149283,
        "uuid": "0c35179c-9a90-11f1-acf1-e61051cd3d98",
        "tipo": "persona",
        "nombre": "Juan Perez",
        "persona_nombre": "Juan Perez",
        "empresa_nombre": "Acme SA",
        "correo": "juan.perez@acme.com",
        "celular": "1155550981",
        "whatsapp": "1155550981",
        "registrado": "2026-08-13 16:21:00",
        "lista_ids": [3, 7],
        "lista_nombres": ["Clientes", "Newsletter 2026"],
        "etiqueta_ids": [],
        "etiqueta_nombres": []
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
  "https://api.databox.net.ar/v4/datarocket/prospectos?q=juan&limite=50&order_by=registrado&dir=desc"
```

---

## `GET /v4/datarocket/prospectos?id=N` — Consulta individual

Mismo shape que un item del listado, con `lista_nombres` / `etiqueta_nombres`
resueltos.

| Codigo | Body `error`              | Cuando                                |
| ------ | ------------------------- | ------------------------------------- |
| 404    | `Prospecto no encontrado` | El `id` pasado no existe en la tabla. |

---

## `POST /v4/datarocket/prospectos` — Alta

Content-Type: `application/json; charset=utf-8`.

### Body

Cualquier subconjunto de las columnas del modelo. **Obligatorios:**

- `tipo`: `persona` o `empresa`.
- `persona_nombre` si `tipo='persona'`, `empresa_nombre` si `tipo='empresa'`.

Campos con tratamiento especial:

- **`nombre`**: se ignora lo que mandes — se deriva del campo de identidad.
- **`uuid`**: si viene se persiste; si no, se genera un UUID v4.
- **`registrado`**: si viene y es valido se persiste; si no, `NOW()` en
  `America/Argentina/Buenos_Aires`.
- **`pais_id` / `provincia_id` / `localidad_id`**: son **ids del catalogo, no
  nombres**. Se obtienen de
  [/v4/databox/ubicaciones](../databox/ubicaciones.md) — ver
  [Seleccionar pais, provincia y localidad](#seleccionar-pais-provincia-y-localidad).
  Un id inexistente corta con `400`. Son opcionales.

> Si venis de un importador con el formato compuesto `EMPRESA - Persona` en un
> solo campo: **partilo antes de postear**, mandando `empresa_nombre` y
> `persona_nombre` por separado. Si mandas el compuesto entero en uno de los
> dos, se guarda entero.

### Respuesta (201)

```json
{
  "ok": true,
  "data": {
    "id": 149299,
    "uuid": "09fbd1c6-9bf1-4b86-bb65-2b6361e1ecee",
    "registrado": "2026-08-18 00:54:07"
  }
}
```

Se devuelven `id`, `uuid` y `registrado` porque son los tres campos que el
caller no siempre conoce a priori. Para releer el prospecto completo hacer
`GET /v4/datarocket/prospectos?id=<id>`.

### Errores

| Codigo | Body `error`                                                               | Cuando                                        |
| ------ | -------------------------------------------------------------------------- | --------------------------------------------- |
| 400    | `Cuerpo no es JSON valido`                                                 | El body no es JSON valido.                    |
| 400    | `El tipo es obligatorio (persona o empresa).`                              | Falta `tipo` o no es uno de los dos valores.  |
| 400    | `El nombre de la persona es obligatorio para un prospecto de tipo persona.`| `tipo='persona'` sin `persona_nombre`.        |
| 400    | `El nombre de la empresa es obligatorio para un prospecto de tipo empresa.`| `tipo='empresa'` sin `empresa_nombre`.        |
| 400    | `El correo no es válido.`                                                  | `correo` con contenido no parseable.          |
| 400    | `El país indicado no existe.` / `La provincia…` / `La localidad…`           | FK a un catalogo con id inexistente.          |
| **409**| `Ya existe un prospecto con ese correo.`                                   | El `correo` normalizado ya esta cargado.      |
| **409**| `Ya existe un prospecto con ese celular.`                                  | El `celular` normalizado ya esta cargado.     |
| **409**| `Ya existe un prospecto con ese correo y ese celular.`                     | Chocan los dos.                               |
| 500    | `<mensaje de la excepcion>`                                                | Falla inesperada (PDO, etc.).                 |

El cuerpo del `409` trae ademas `coincidencias`, con el mismo shape que
`?verificar=1`, para poder ofrecer *"ya lo tenes cargado, ¿queres verlo?"* en
vez de un mensaje ciego:

```json
{
  "ok": false,
  "error": "Ya existe un prospecto con ese correo.",
  "coincidencias": [
    {
      "campo": "correo",
      "valor": "juan.perez@acme.com",
      "prospecto": { "id": 149283, "uuid": "…", "nombre": "Juan Perez", "correo": "…", "celular": "…", "registrado": "…" }
    }
  ]
}
```

### Ejemplo `curl`

```bash
curl -X POST https://api.databox.net.ar/v4/datarocket/prospectos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "tipo":            "persona",
    "persona_nombre":  "Juan Perez",
    "empresa_nombre":  "Acme SA",
    "empresa_cargo":   "Gerente",
    "correo":          "juan.perez@acme.com",
    "celular":         "+54 9 11 5555-0981",
    "whatsapp":        "+54 9 11 5555-0981",
    "pais_id":         1,
    "ciudad":          "CABA",
    "lista_ids":       [3, 7]
  }'
```

### Flujo recomendado para un formulario externo

```
1. GET  ?verificar=1&correo=…&celular=…   -> mostrar "ya estas registrado" si existe:true
2. POST                                    -> igual hay que manejar el 409 (otro pudo
                                              cargarlo entre el paso 1 y el 2)
```

---

## `PUT /v4/datarocket/prospectos?id=N` — Modificacion

Content-Type: `application/json; charset=utf-8`.

**Reemplazo total** de las columnas mutables (todas menos `id` y `uuid`). Los
campos que **no** vengan en el body se persisten como `NULL` — no es un patch
parcial. Para dejar un campo como estaba, incluilo con su valor actual
(obtenido de un `GET ?id=N` previo), o usa
[`PATCH`](#patch-v4datarocketprospectosidn--modificacion-parcial).

Excepcion: `lista_ids` y `etiqueta_ids` **si** son opcionales — si no vienen no
se toca la puente.

Aplica el mismo chequeo de unicidad que el alta, excluyendose a si mismo. Sin
esto el `409` del `POST` seria trivial de esquivar: alta con correo libre + PUT
pisandolo con el que ya existia.

### Respuesta (200)

```json
{ "ok": true, "data": { "id": 149299 } }
```

### Errores

Los mismos del `POST` (incluidos los `409`), mas:

| Codigo | Body `error`              | Cuando                        |
| ------ | ------------------------- | ----------------------------- |
| 400    | `Falta id (int > 0)`      | Query string sin `id` valido. |
| 404    | `Prospecto no encontrado` | El `id` no existe.            |

---

## `PATCH /v4/datarocket/prospectos?id=N` — Modificacion parcial

Content-Type: `application/json; charset=utf-8`.

**Se escriben unicamente las columnas presentes en el body.** Todo lo demas
queda como estaba. Es la diferencia con el `PUT`, que es reemplazo total —
comparativa en [`PUT` o `PATCH`](#put-o-patch).

```bash
# Corregirle el celular a un prospecto, sin tocarle nada mas.
curl -X PATCH "https://api.databox.net.ar/v4/datarocket/prospectos?id=149299" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"celular": "+54 9 11 5555-0981"}'
```

```json
{ "ok": true, "data": { "id": 149299, "campos": ["celular"] } }
```

`campos` lista lo que **realmente se escribio**: incluye lo que agrego el
endpoint por su cuenta (el `nombre` derivado, el `correo` rescatado de `web`) y
**no** incluye las claves del body que se ignoraron. Es la forma de confirmar
que el parche hizo lo que se esperaba.

### Que se puede mandar

Cualquier subconjunto de las columnas del [modelo de datos](#modelo-de-datos),
mas `lista_ids` / `etiqueta_ids`. Se aplican las mismas
[reglas de sanitizacion](#reglas-de-sanitizacion-post--put--patch) que en el
`POST`: los valores se normalizan igual antes de guardarse.

- **`nombre`** se ignora (es derivado), igual que en `POST` / `PUT`.
- **`uuid`** e **`id`** no son modificables.
- Los alias legacy **`pais` / `provincia` / `localidad`** se aceptan y escriben
  la columna `*_id` correspondiente.
- Las claves desconocidas se ignoran en silencio, igual que en `POST` / `PUT`.
- **Un string vacio no es "no lo toques": es `NULL`.** `{"comentarios": ""}`
  borra los comentarios. Para no tocar un campo, no lo mandes.

Un body que no traiga **ninguna** clave conocida corta con `400` en vez de
devolver un `200` que no hizo nada. Eso incluye `{}` y `{"nombre": "…"}`.

### Las validaciones corren sobre el estado resultante

No sobre el body suelto. El endpoint arma `fila actual + parche` y valida eso:

- `PATCH {"tipo": "empresa"}` chequea el `empresa_nombre` que **ya estaba
  cargado** — si esta vacio, corta con `400`.
- `PATCH {"persona_nombre": ""}` sobre una persona corta con `400`, aunque el
  body no traiga `tipo`.
- **`nombre` se re-deriva** cuando el parche toca `tipo` o el campo de
  identidad, y aparece en `campos`. Asi no queda el nombre viejo colgado, que
  es exactamente como se ensucio la tabla antes de la backfill `20260817_2100`.

### …pero no se auditan las invariantes que el parche no rompe

Es la contracara, y es deliberada: hay filas historicas con `tipo` en `NULL` y
con correo/celular duplicado, y son justamente las que mas necesitan que se las
pueda corregir de a un campo.

| Situacion | `PUT` | `PATCH` |
| ------------------------------------------------ | ------- | ---------------------- |
| Fila con `tipo` NULL, se le corrige el celular    | `400` (exige `tipo`) | `200` — `tipo` queda NULL |
| Fila con correo duplicado historico, se le cambia `domicilio` | `409` | `200` — no se mira el correo heredado |
| El parche **si** trae `tipo` con un valor invalido | `400` | `400` |
| El parche **si** trae un `correo` ya cargado en otra fila | `409` | `409` |

`pais_id` / `provincia_id` / `localidad_id` siguen la misma logica: se validan
contra el catalogo solo cuando el parche los escribe.

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "id": 149299,
    "campos": ["tipo", "persona_nombre", "nombre"]
  }
}
```

### Errores

Los mismos del `POST` (incluidos los `409`, con su bloque `coincidencias`),
mas:

| Codigo | Body `error`                                          | Cuando                                        |
| ------ | ----------------------------------------------------- | --------------------------------------------- |
| 400    | `Falta id (int > 0)`                                  | Query string sin `id` valido.                 |
| 400    | `El cuerpo del PATCH no trae ningun campo modificable.` | Body vacio, o solo con claves ignoradas.     |
| 404    | `Prospecto no encontrado`                             | El `id` no existe.                            |

### Ejemplos

```bash
# Reasignar listas y etiquetas sin tocar ninguna columna del prospecto.
curl -X PATCH "…/prospectos?id=149299" -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"lista_ids": [3, 7], "etiqueta_ids": []}'
# -> {"ok":true,"data":{"id":149299,"campos":["lista_ids","etiqueta_ids"]}}

# Cambiar el tipo: `nombre` se re-deriva solo desde `empresa_nombre`.
curl -X PATCH "…/prospectos?id=149299" -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"tipo": "empresa"}'
# -> {"ok":true,"data":{"id":149299,"campos":["tipo","nombre"]}}

# Un correo cargado por error en `web` se rescata a `correo` — igual que en el
# alta, y solo si el prospecto no tenia correo y el parche no lo trae.
curl -X PATCH "…/prospectos?id=149299" -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"web": "contacto@acme.com"}'
# -> {"ok":true,"data":{"id":149299,"campos":["web","correo"]}}
```

---

## `DELETE /v4/datarocket/prospectos?id=N` — Baja

Elimina la fila **definitivamente** — no hay soft-delete. Las filas de
`datarocket_prospectos_listas` y `datarocket_prospectos_etiquetas` se borran
solas por el `ON DELETE CASCADE` de sus FKs.

Las interacciones (`datarocket_interacciones.prospecto_id = N`) **quedan
huerfanas**: esa columna todavia no lleva FK a `datarocket_prospectos`. Si el
caller necesita limpiar historial, tiene que hacerlo aparte.

### Respuesta (200)

```json
{ "ok": true, "data": { "id": 149299 } }
```

### Errores

| Codigo | Body `error`              | Cuando                        |
| ------ | ------------------------- | ----------------------------- |
| 400    | `Falta id (int > 0)`      | Query string sin `id` valido. |
| 404    | `Prospecto no encontrado` | El `id` no existe.            |

---

## Referencias

- Tabla destino: `datarocket_prospectos` — schema en [db/schema.sql](../../../db/schema.sql).
- ABM interno equivalente (panel cloud): [cloud/api/datarocketprospectos.php](../../../cloud/api/datarocketprospectos.php).
- Normalizacion compartida: [cloud/api/lib/prospectos_normalizar.php](../../../cloud/api/lib/prospectos_normalizar.php).
- Catalogo geografico para resolver `pais_id` / `provincia_id` / `localidad_id`: [/v4/databox/ubicaciones](../databox/ubicaciones.md).
- Indices de la busqueda de duplicados: migracion `20260818_1300_datarocket_prospectos_indices_correo_celular.sql`.
- Helper de auth por Bearer: [cloud/api/lib/apikey_auth.php](../../../cloud/api/lib/apikey_auth.php) (el v4 rueda la logica inline para no arrastrar dependencias, pero el shape es identico).
- Microservicios hermanos del mismo `v4/`: [/v4/evolution/mensajes](../evolution/mensajes.md), [/v4/datacount/comprobantes](../datacount/comprobantes.md).
