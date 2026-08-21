# `/v4/datarocket/embudos`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/embudos.md>

Microservicio del **CRM Datarocket** sobre la tabla `datarocket_embudos` — el
catalogo de pipelines de venta / captacion. Cada oportunidad vive en un embudo y
avanza por las etapas de ese embudo (`datarocket_etapas`). Un unico archivo
`.php` ([embudos.php](embudos.php)) que sirve todo el recurso — sin framework ni
router aparte.

La operacion que motiva el microservicio:

| Quiero…                                          | Uso                                                    |
| ------------------------------------------------ | ------------------------------------------------------ |
| **Tengo el slug y necesito el `id` del embudo**   | `GET /v4/datarocket/embudos?slug=causam-clientes`       |
| …y ademas el `id` de la etapa donde arranca       | `GET /v4/datarocket/embudos?slug=...&con_etapas=1`      |
| Ver que embudos hay                              | `GET /v4/datarocket/embudos`                            |
| Ver los de un proyecto                           | `GET /v4/datarocket/embudos?proyecto_id=109`            |
| Consultar uno que ya tengo identificado          | `GET /v4/datarocket/embudos?id=4`                       |

**Se busca por `slug` o por `id`, nada mas.** No hay busqueda por texto libre:
`?q=` se retiro y `?nombre=` no existe — los dos devuelven `400`. Ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

**Este endpoint es de solo lectura.** No hay `POST`, `PUT`, `PATCH` ni `DELETE`.
Ver [Por que es de solo lectura](#por-que-es-de-solo-lectura).

**El slug es unico por proyecto, no global.** Un `?slug=` que matchea en dos
proyectos devuelve `409` con los candidatos, no "el primero". Ver
[El slug es unico por proyecto](#el-slug-es-unico-por-proyecto).

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/datarocket/embudos
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo via
HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket >
Embudos) usa su propio endpoint
[cloud/api/datarocket_embudos.php](../../../cloud/api/datarocket_embudos.php),
que ademas expone el alta, la modificacion y la baja. Los dos leen la misma
tabla; la diferencia es la capa de auth (permisos de sesion vs. Bearer estatico)
y el alcance de las operaciones.

---

## Para que existe: resolver el slug a un id

Lo que un integrador tiene a mano es un identificador estable escrito en su
configuracion (`causam-clientes`), no el `id` autoincremental que le toco al
embudo en esta base. Y el `nombre` tampoco sirve como referencia: es texto libre,
editable desde el panel, y trae acentos, espacios y mayusculas.

El **`slug`** es exactamente esa referencia estable — kebab-case, maximo 40
caracteres, `UNIQUE` por proyecto (mismo criterio que `datacount_empresas.slug`).
Este endpoint lo traduce al `id` que despues viaja como `embudo_id` hacia los
demas endpoints del CRM, y de paso devuelve **la fila entera**, incluido
`proyecto_id` — el otro dato que el consumidor suele necesitar y no tiene por que
conocer de memoria.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes"
# -> {"ok":true,"data":{"id":4,"proyecto_id":109,...}}
#                        ^^^^^^^^ esto es lo que se manda como embudo_id
```

---

## Se busca por slug o por id

Las dos unicas claves de busqueda son **`?slug=`** y **`?id=N`** (`?codigo=N` en
el listado, que es el mismo id con el nombre que usa el ABM). No hay busqueda por
texto libre:

| Parametro  | Antes                                              | Ahora                |
| ---------- | -------------------------------------------------- | -------------------- |
| `?q=`      | Coincidencia parcial sobre `slug`, `nombre` y `descripcion` | **`400`** — retirado |
| `?nombre=` | Nunca existio                                       | **`400`**            |

El motivo es el mismo que justifica el endpoint: `nombre` y `descripcion` son
texto libre editable desde el panel y no identifican nada de forma estable. Una
integracion que resuelve su embudo por aproximacion sobre esas columnas queda
atada a como este redactado el catalogo hoy, y el dia que alguien lo retoca desde
el ABM la resolucion empieza a devolver otra fila —o ninguna— sin que nada falle
de forma visible.

**Perder `?q=` no le saca alcance a nadie**, porque `?slug=` acepta el texto del
nombre sin formatear: lo que antes se pedia como `?q=Causam Clientes` ahora se
pide como `?slug=Causam Clientes` y resuelve `causam-clientes` (ver
[El slug](#el-slug)). La diferencia es que devuelve **la** fila, no un conjunto
parecido.

Los dos parametros **no se ignoran en silencio**. Mandar `?q=` y recibir el
catalogo entero con `200` seria una respuesta silenciosamente incorrecta —el
cliente creeria que filtro— asi que se corta con `400` y el error dice como se
hace ahora:

```json
{"ok":false,"error":"El parametro `q` no esta soportado: `/v4/datarocket/embudos` se consulta por `?slug=...` o por `?id=N`, nada mas. `?slug=` acepta el texto del nombre sin formatear (`?slug=Causam Clientes` resuelve `causam-clientes`)."}
```

`proyecto_id`, `activo`, `order_by`, `dir` y `limite` siguen estando: son filtros
y presentacion del listado, no formas de buscar.

Si de verdad hace falta una aproximacion, el catalogo entra entero en una sola
llamada (7 embudos al 2026-08-18) y se filtra del lado del consumidor.

---

## Autenticacion

Bearer estatico contra `aplicaciones.apikey` (misma tabla que el resto del
stack). El header debe llegar como:

```
Authorization: Bearer <apikey>
```

Cualquier apikey habilitada pasa — no hay scope por endpoint. Cada llamada
exitosa incrementa `aplicaciones.usos` (best-effort). No se registran `sucesos`:
el endpoint no escribe nada en el catalogo.

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

Body-out es JSON `utf-8` (`Content-Type: application/json`).

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/datarocket/embudos`

| Metodo | Path                                              | Uso                                             |
| ------ | ------------------------------------------------- | ----------------------------------------------- |
| GET    | `/v4/datarocket/embudos`                          | Listado con filtros (query string).             |
| GET    | `/v4/datarocket/embudos?id=N`                     | Consulta individual del embudo N.               |
| GET    | `/v4/datarocket/embudos?slug=causam-clientes`     | Resolucion slug → registro completo. `404` si no esta. |

Cualquier otro metodo devuelve `405`:

```json
{"ok":false,"error":"Metodo no soportado. `/v4/datarocket/embudos` es de solo lectura: los embudos se crean, editan y borran desde el ABM del panel cloud."}
```

Precedencia de los parametros: `?id=N` gana sobre `?slug=`, y `?slug=` gana sobre
el listado. `?q=` y `?nombre=` cortan con `400` antes de todo eso — ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

---

## Modelo de datos

Tabla `datarocket_embudos` (migraciones `20260812_0200`, `_0600`, `_0700` y
`20260818_1400`).

| Campo                | Tipo           | En el JSON | Notas                                                       |
| -------------------- | -------------- | ---------- | ----------------------------------------------------------- |
| `id`                 | `int`          | `int`      | Autoincremental. Es lo que se manda como `embudo_id`.        |
| `proyecto_id`        | `int`          | `int`      | FK a `proyectos`. Obligatorio.                               |
| `slug`               | `varchar(40)`  | `string`   | **UNIQUE por proyecto.** Referencia estable (ver abajo).     |
| `nombre`             | `varchar(80)`  | `string`   | **UNIQUE por proyecto.** Texto libre, editable.              |
| `descripcion`        | `varchar(500)` | `string?`  | Nota interna opcional. Vacia se guarda como `null`.          |
| `activo`             | `tinyint(1)`   | `int`      | `1` / `0`. Un embudo inactivo **sigue apareciendo** (ver abajo). |
| `fecha_creacion`     | `datetime`     | `string`   | La pone la base (`CURRENT_TIMESTAMP`).                       |
| `fecha_modificacion` | `datetime`     | `string`   | La pone la base (`ON UPDATE CURRENT_TIMESTAMP`).             |

El JSON agrega ademas `proyecto_nombre` (`string?`), resuelto con un `LEFT JOIN`
a `proyectos`. Es `null` solo si el proyecto no resuelve — el embudo se devuelve
igual en vez de desaparecer del listado.

Una fila tal como sale del endpoint:

```json
{
  "id": 4,
  "proyecto_id": 109,
  "proyecto_nombre": "Causam",
  "slug": "causam-clientes",
  "nombre": "Causam Clientes",
  "descripcion": "Embudo de captacion / ventas de Causam.",
  "activo": 1,
  "fecha_creacion": "2026-08-11 23:51:42",
  "fecha_modificacion": "2026-08-18 11:44:42"
}
```

`?con_conteo=1` agrega `etapas_count` y `oportunidades_count` (int);
`?con_etapas=1` agrega `etapas` (array). Las tres claves **no aparecen** si no se
pidieron: una clave ausente y una clave en `null` significan cosas distintas y
mezclarlas obliga al consumidor a adivinar.

> **`activo=0` no filtra nada por default.** El listado devuelve activos e
> inactivos, y `?slug=` resuelve un embudo inactivo igual que uno activo — la
> desactivacion es una decision de la UI (que deja de ofrecerlo en los combos),
> no un borrado logico. Si el consumidor quiere solo los vivos, `?activo=1`.

### El slug

Kebab-case estricto — `^[a-z0-9]+(-[a-z0-9]+)*$`, maximo 40 caracteres. Cuando el
operador no lo carga a mano, el ABM del panel lo deriva del `nombre`.

**No hace falta mandarlo perfectamente formateado.** El endpoint aplica al
termino de busqueda la misma transformacion con la que se deriva al dar de alta
(`embSlugify()`, espejo de `dremSlugify()` del ABM y del backfill de la migracion
`20260818_1400`): acentos plegados, minusculas, todo lo que no sea `[a-z0-9]` a
guion, guiones de los bordes recortados, corte a 40. Los tres ejemplos devuelven
la misma fila:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=Causam%20Clientes"   # "Causam Clientes"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=%20CAUSAM_CLIENTES%20"
```

Que la busqueda use la misma transformacion que el alta es lo que garantiza que
el cliente pueda mandar el slug ya armado **o** el texto del nombre y los dos
caigan en la misma clave. El plegado cubre tambien el texto en forma **NFD**
(donde la `í` viaja como `i` + tilde suelta, lo que mandan varios teclados de
macOS / iOS): sin eso la tilde suelta se convertiria en un guion en el medio de
la palabra.

### El slug es unico por proyecto

El `UNIQUE` de la tabla es **(`proyecto_id`, `slug`)**, no `slug` a secas: dos
proyectos distintos pueden tener cada uno su `captacion-general`.

Por eso un `?slug=` suelto puede matchear mas de una fila. Devolver "la primera"
seria una respuesta silenciosamente incorrecta — el cliente se llevaria el embudo
de otro proyecto sin enterarse — asi que la ambiguedad se contesta con **`409` y
la lista completa de candidatos**:

```json
{
  "ok": false,
  "error": "El slug `causam-clientes` existe en mas de un proyecto. Agrega `&proyecto_id=N` para desambiguar.",
  "consulta": { "slug": "causam-clientes" },
  "embudos": [
    { "id": 8, "proyecto_id": 102, "proyecto_nombre": "Vigicom", "slug": "causam-clientes", ... },
    { "id": 4, "proyecto_id": 109, "proyecto_nombre": "Causam",  "slug": "causam-clientes", ... }
  ]
}
```

Se resuelve agregando `&proyecto_id=N`:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes&proyecto_id=109"
# -> 200 {"ok":true,"data":{"id":4,...}}
```

Al 2026-08-18 no hay ningun slug repetido en la base (7 embudos, 7 slugs
distintos), o sea que en la practica el `409` no aparece. El endpoint no se apoya
en eso porque nada lo garantiza: alcanza con que alguien cree desde el panel un
`nuevos-usuarios` en un segundo proyecto.

### `etapas` — el otro id que se suele necesitar

Quien resuelve el embudo por slug casi siempre necesita despues el `id` de una
**etapa** (la primera del pipeline, para dar de alta la oportunidad ahi).
`?con_etapas=1` las trae embebidas y ahorra la segunda llamada:

```json
{
  "id": 4,
  "slug": "causam-clientes",
  "etapas": [
    { "id": 10, "nombre": "Nuevo",      "orden": 1, "color": "#6b7280", "tipo": "activa",  "probabilidad": 10 },
    { "id": 13, "nombre": "Contactado", "orden": 2, "color": "#3b82f6", "tipo": "activa",  "probabilidad": 25 },
    { "id": 16, "nombre": "Calificado", "orden": 3, "color": "#8b5cf6", "tipo": "activa",  "probabilidad": 50 },
    { "id": 19, "nombre": "Propuesta",  "orden": 4, "color": "#f59e0b", "tipo": "activa",  "probabilidad": 75 },
    { "id": 22, "nombre": "Ganado",     "orden": 5, "color": "#22c55e", "tipo": "ganada",  "probabilidad": 100 },
    { "id": 25, "nombre": "Perdido",    "orden": 6, "color": "#ef4444", "tipo": "perdida", "probabilidad": 0 }
  ]
}
```

Vienen ordenadas por `orden` ascendente — que es el recorrido real del pipeline,
no un detalle de presentacion — asi que `etapas[0]` es la etapa de entrada.
`orden` tiene `UNIQUE` por embudo, o sea que el orden es determinista.

`tipo` es `activa`, `ganada` o `perdida`: las dos ultimas son estados terminales
del pipeline. Un embudo sin etapas devuelve `"etapas": []`, no `null` — es una
lista vacia, no un dato faltante (el ABM permite crear el embudo y cargarle las
etapas despues).

El flag vale para el listado, para `?id=N` y para `?slug=`. Cuesta una query
extra, asi que no viene por default.

---

## `GET /v4/datarocket/embudos` — Listado

### Query params

El listado **no busca: filtra.** Todos los parametros de abajo acotan o presentan
el conjunto; para llegar a una fila puntual estan `?slug=` y `?id=N`.

| Param         | Tipo   | Default | Notas                                                        |
| ------------- | ------ | ------- | ------------------------------------------------------------ |
| `codigo`      | int    | —       | Filtra por `id` exacto.                                       |
| `proyecto_id` | int    | —       | Filtra por proyecto.                                          |
| `activo`      | `1`/`0`| —       | Sin el parametro vienen los dos.                              |
| `order_by`    | enum   | `slug`  | `id`, `proyecto_id`, `slug`, `nombre`, `activo`, `fecha_creacion`, `fecha_modificacion`. Tambien se acepta como `orden`. |
| `dir`         | enum   | ver nota| `asc` / `desc`.                                               |
| `limite`      | int    | `100`   | Clampeado a `[1, 1000]`.                                      |
| `con_conteo`  | flag   | `0`     | Agrega `etapas_count` y `oportunidades_count`.                |
| `con_etapas`  | flag   | `0`     | Agrega `etapas` (array).                                      |

Un `order_by` desconocido cae al default en vez de dar `400` — un parametro mal
escrito no justifica romperle la pantalla al cliente. `q` y `nombre`, en cambio,
si cortan con `400`: no son parametros mal escritos, son busquedas que el
endpoint ya no hace, y contestarlas con el catalogo entero seria mentir.

> **Default del orden:** alfabetico ascendente por `slug`, al reves que
> `/v4/datarocket/prospectos` (que ordena por `id DESC`). Es a proposito: esto es
> un catalogo chico — 7 embudos al 2026-08-18 — que casi siempre termina en un
> combo o en un vistazo, y ahi el orden util es el alfabetico. Para los criterios
> que no son `slug` ni `nombre` el default de `dir` es `desc`.

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 7,
    "items": [
      { "id": 4, "proyecto_id": 109, "proyecto_nombre": "Causam", "slug": "causam-clientes",
        "nombre": "Causam Clientes", "descripcion": "Embudo de captacion / ventas de Causam.",
        "activo": 1, "fecha_creacion": "2026-08-11 23:51:42", "fecha_modificacion": "2026-08-18 11:44:42" },
      { "id": 6, "proyecto_id": 109, "proyecto_nombre": "Causam", "slug": "causam-estudios",
        "nombre": "Causam Estudios", "descripcion": null, "activo": 1,
        "fecha_creacion": "2026-08-11 23:54:45", "fecha_modificacion": "2026-08-18 11:44:42" }
    ]
  }
}
```

`total` es la cantidad de items **devueltos**, no el total de la tabla — si llega
recortado por `limite`, los dos numeros coinciden y no hay forma de distinguirlo
desde la respuesta. Con 7 embudos en dev, el `limite` default lo trae entero.

### Ejemplos `curl`

```bash
# Todos los embudos, alfabetico por slug.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos"

# Los de un proyecto, con etapas y conteos.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?proyecto_id=109&con_etapas=1&con_conteo=1"

# Un embudo puntual NO sale de aca: va por `?slug=` (acepta el texto del nombre).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=Reactor%20Clientes"
```

---

## `GET /v4/datarocket/embudos?id=N` — Consulta individual

Devuelve la fila completa. Acepta `?con_conteo=1` y `?con_etapas=1`.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?id=1&con_conteo=1"
# -> {"ok":true,"data":{"id":1,"proyecto_id":102,"slug":"vigicom-usuarios",...,
#                       "etapas_count":6,"oportunidades_count":1192}}
```

| Codigo | Cuando                   |
| ------ | ------------------------ |
| 404    | `Embudo no encontrado`   |

---

## `GET /v4/datarocket/embudos?slug=...` — Resolucion slug → registro

El caso de uso que motiva el endpoint. Devuelve **la misma forma que `?id=N`** —
un objeto con la fila entera, no una lista. Acepta `?con_conteo=1`,
`?con_etapas=1` y `?proyecto_id=N` (para desambiguar).

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes"
```

```json
{
  "ok": true,
  "data": {
    "id": 4,
    "proyecto_id": 109,
    "proyecto_nombre": "Causam",
    "slug": "causam-clientes",
    "nombre": "Causam Clientes",
    "descripcion": "Embudo de captacion / ventas de Causam.",
    "activo": 1,
    "fecha_creacion": "2026-08-11 23:51:42",
    "fecha_modificacion": "2026-08-18 11:44:42"
  }
}
```

### Errores

| Codigo | Cuerpo                                                                          |
| ------ | ------------------------------------------------------------------------------- |
| 400    | `{"ok":false,"error":"El \`slug\` a buscar no puede estar vacio."}`              |
| 404    | `{"ok":false,"error":"Embudo no encontrado","consulta":{"slug":"no-existe"}}`    |
| 409    | `{"ok":false,"error":"El slug \`X\` existe en mas de un proyecto...","embudos":[...]}` |

`?slug=` sin valor es `400`, no "sin filtro": el cliente pidio buscar un slug y
devolverle el catalogo entero seria contestarle otra pregunta.

El `404` incluye `consulta.slug` con el valor **ya normalizado**, para que se
entienda contra que se busco realmente (`" Causam Clientes "` se busco como
`causam-clientes`). Si se mando `proyecto_id`, tambien viaja ahi.

**No hay variante por aproximacion.** El `?q=` que la ofrecia se retiro (ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id)); un `404` aca significa
que el embudo no existe con ese slug, no que haya que reintentar con otro texto —
la normalizacion ya cubre mayusculas, acentos, espacios y guiones bajos.

---

## Flujo completo: del slug al alta de una oportunidad

> **Para el caso tipico —una consulta que entra por la web— no hace falta este
> flujo.** `POST /v4/datarocket/prospectos` acepta `embudo` (nombre o slug)
> junto con `asunto` y `mensaje`, y crea el prospecto, la oportunidad en la
> primera etapa de ese embudo y la interaccion en una sola llamada. Resuelve el
> slug internamente con la misma logica de aca. Ver
> [prospectos.md](prospectos.md#registrar-una-consulta-embudo-asunto-y-mensaje).
>
> Lo que sigue sirve para el resto: poblar un combo, validar la configuracion al
> arrancar, o cachear el `embudo_id`.

```bash
# 1) Slug -> embudo + etapas, en una sola llamada.
EM=$(curl -s -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes&con_etapas=1")

EMBUDO_ID=$(echo "$EM" | jq -r '.data.id')          # -> 4
ETAPA_ID=$(echo "$EM" | jq -r '.data.etapas[0].id') # -> 10  ("Nuevo", orden 1)
PROYECTO_ID=$(echo "$EM" | jq -r '.data.proyecto_id')

# 2) Con esos ids ya se puede operar contra el resto del CRM.
#    (`embudo_id` / `etapa_id` son las columnas de datarocket_oportunidades.)
```

El `id` es estable dentro de esta base, asi que un cliente que corre seguido
puede cachearlo. Lo que **no** conviene cachear entre entornos es el numero en
si: el `id` de `causam-clientes` en dev no tiene por que ser el mismo que en
produccion — el slug si.

---

## Por que es de solo lectura

No hay `POST`, `PUT`, `PATCH` ni `DELETE` en este endpoint. No es un olvido.

Un embudo no es un dato que llegue de una integracion, es **configuracion del
CRM**. Crearlo implica ademas cargarle las etapas — sin etapas el embudo no sirve
para nada, porque no hay donde poner la oportunidad — y elegir el proyecto al que
pertenece. Y borrarlo esta bloqueado a nivel base por la FK `RESTRICT` de
`datarocket_oportunidades.embudo_id` mientras tenga oportunidades colgando
(`causam-clientes` tiene 31 al 2026-08-18; `vigicom-usuarios`, 1.192).

Todo eso es curaduria, no integracion, asi que vive unicamente en el ABM del
panel cloud, donde hay usuario identificado, permisos (`datarocket.embudos.*`) y
suceso asociado. Desde afuera el embudo se consulta; no se toca.

Un `slug` que el integrador espera y no existe es un `404` que alguien tiene que
ir a resolver al panel — deliberadamente, no creando el embudo al vuelo. No hay
un `?resolver=1` como el de [etiquetas](etiquetas.md) por esa misma razon: una
etiqueta nueva es inofensiva, un embudo vacio es un pipeline roto.

---

## Referencias

- Implementacion: [embudos.php](embudos.php)
- Principal consumidor del catalogo: el alta con consulta de [prospectos.md](prospectos.md#registrar-una-consulta-embudo-asunto-y-mensaje), que resuelve el `embudo` y abre la oportunidad en su primera etapa.
- ABM interno del panel: [cloud/api/datarocket_embudos.php](../../../cloud/api/datarocket_embudos.php)
- Catalogo de etiquetas (mismo patron de resolucion texto → id): [etiquetas.md](etiquetas.md)
- Endpoint de prospectos: [prospectos.md](prospectos.md)
- Esquema de la base: [db/schema.sql](../../../db/schema.sql)
- Migraciones de la tabla: `cloud/sql/migrations/20260812_0200_crear_datarocket_embudos.sql`,
  `20260812_0600_datarocket_embudos_agregar_proyecto_id.sql`,
  `20260812_0700_datarocket_embudos_dropear_color_orden.sql`,
  `20260818_1400_datarocket_embudos_agregar_slug.sql`
- Tabla de etapas: `cloud/sql/migrations/20260812_0300_crear_datarocket_etapas.sql`
