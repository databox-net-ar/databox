# `/v4/datarocket/expertos`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/datarocket/expertos.md>

Microservicio del **CRM Datarocket** sobre la tabla `datarocket_expertos` — el
catalogo de personalidades con las que una IA contesta las consultas de los
interesados en un proyecto del grupo. Cada fila tiene un **`contexto`**: el
prompt de sistema que se le antepone al modelo, guardado en Markdown crudo. Un
unico archivo `.php` ([expertos.php](expertos.php)) que sirve todo el recurso —
sin framework ni router aparte.

La operacion que motiva el microservicio:

| Quiero…                                            | Uso                                                        |
| -------------------------------------------------- | ---------------------------------------------------------- |
| **Tengo el slug y necesito el prompt de sistema**   | `GET /v4/datarocket/expertos?slug=reactor-asesor`           |
| …y ademas el nombre, el proyecto y las fechas       | `GET /v4/datarocket/expertos?slug=...&formato=json`         |
| Ver que expertos hay                                | `GET /v4/datarocket/expertos`                               |
| Ver los de un proyecto                              | `GET /v4/datarocket/expertos?proyecto_id=104`               |
| Consultar uno que ya tengo identificado             | `GET /v4/datarocket/expertos?id=3`                          |

**El contexto sale como Markdown crudo, no adentro de un JSON.** `?slug=`
devuelve el cuerpo pelado con `Content-Type: text/markdown`. Ver
[El contexto sale como Markdown crudo](#el-contexto-sale-como-markdown-crudo).

**Se busca por `slug` o por `id`, nada mas.** No hay busqueda por texto libre:
`?q=` y `?nombre=` devuelven `400`. Ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

**Un experto desactivado o sin contexto no se sirve: corta con `409`.** Nunca
devuelve un `200` de cuerpo vacio. Ver
[Un experto sin contexto o desactivado no se sirve](#un-experto-sin-contexto-o-desactivado-no-se-sirve).

**Este endpoint es de solo lectura.** No hay `POST`, `PUT`, `PATCH` ni `DELETE`.
Ver [Por que es de solo lectura](#por-que-es-de-solo-lectura).

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/datarocket/expertos
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo via
HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket >
Expertos) usa su propio endpoint
[cloud/api/datarocket_expertos.php](../../../cloud/api/datarocket_expertos.php),
que ademas expone el alta, la modificacion y la baja. Los dos leen la misma
tabla; la diferencia es la capa de auth (permisos de sesion vs. Bearer estatico),
el alcance de las operaciones y que aca el contexto se entrega como documento.

---

## Para que existe: bajar el prompt de sistema por slug

Lo que un canal de entrada tiene a mano —un bot de WhatsApp, un widget del sitio,
un agente— es un identificador estable escrito en su configuracion
(`reactor-asesor`), no el `id` autoincremental que le toco al experto en esta
base. Y el `nombre` tampoco sirve como referencia: es texto libre, editable desde
el panel, y trae acentos, espacios y mayusculas.

El **`slug`** es exactamente esa referencia estable — kebab-case, maximo 60
caracteres, `UNIQUE` global. Este endpoint lo traduce a lo unico que el canal
necesita para armar la conversacion: **el contexto**, tal cual se le antepone al
modelo.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor"
# -> 200 text/markdown
#    ## Información oficial
#
#    - Sitio web oficial: www.reactor.com.ar
#    ...
```

---

## El contexto sale como Markdown crudo

`?slug=` devuelve por default **el cuerpo del contexto pelado**, con
`Content-Type: text/markdown; charset=utf-8`. No es un capricho de formato: ese
texto es lo que se le pega al modelo como prompt de sistema, asi que el camino
mas corto entre la base y el modelo tiene que ser una lectura, no un parseo.

Mandarlo adentro de `{"ok":true,"data":{"contexto":"..."}}` obligaria a cada
consumidor a decodificar un string con miles de `\n` escapados para recuperar
exactamente los mismos bytes que ya estaban en la columna. Cada paso de esa
cadena es una oportunidad de alterar el prompt sin que nadie se entere.

Por eso el cuerpo se manda **byte a byte como esta guardado**: sin encabezado
agregado, sin front-matter, sin un `# Contexto de Reactor Asesor` adelante.
Cualquier cosa que el endpoint le sumara al texto se convertiria en parte del
prompt. Lo unico que se normaliza es el `rtrim()` de la cola — mismo criterio con
el que lo guarda el ABM, asi que no cambia nada respecto de lo que el operador
vio en el panel.

La metadata que el consumidor igual suele querer viaja en **headers
`X-Experto-*`**, que no tocan el cuerpo:

```
Content-Type: text/markdown; charset=utf-8
ETag: "d478084ac49501e3746cabfb5b317f38"
Cache-Control: no-cache
X-Experto-Id: 3
X-Experto-Slug: reactor-asesor
X-Experto-Nombre: Reactor Asesor
X-Experto-Caracteres: 1383
X-Experto-Fecha-Modificacion: 2026-09-14 17:22:23
X-Experto-Proyecto-Id: 104
```

| Header                         | Notas                                                                    |
| ------------------------------ | ------------------------------------------------------------------------ |
| `X-Experto-Id`                 | El `id` de la base. Util para loguear que experto contesto.               |
| `X-Experto-Slug`              | El slug **ya normalizado** (`?slug=Reactor Asesor` → `reactor-asesor`).   |
| `X-Experto-Nombre`            | Plegado a ASCII (los headers HTTP no son UTF-8). El nombre exacto sale de `&formato=json`. |
| `X-Experto-Caracteres`        | Largo del contexto en **caracteres**, no bytes — no coincide con `Content-Length` si hay acentos. |
| `X-Experto-Fecha-Modificacion`| `fecha_modificacion` de la fila.                                          |
| `X-Experto-Proyecto-Id`       | **Se omite** si el experto es transversal al grupo (`proyecto_id` NULL).  |

Para la version estructurada y completa esta `&formato=json` — ver
[Los defaults de `formato`](#los-defaults-de-formato-son-distintos-en-slug-y-en-id).

---

## Los defaults de `formato` son distintos en `?slug=` y en `?id=`

| Puerta   | Default de `formato` | Que devuelve                                    |
| -------- | -------------------- | ----------------------------------------------- |
| `?slug=` | `md`                 | El contexto crudo (`text/markdown`).            |
| `?id=N`  | `json`               | La fila entera (`{"ok":true,"data":{...}}`).    |

No es una inconsistencia: son **dos preguntas distintas**. Quien entra por
`?slug=` tiene el identificador que escribio en su config y esta pidiendo *el
documento* — es el caso de uso que justifica el endpoint. Quien entra por
`?id=N` ya resolvio el experto y esta pidiendo *el registro*, que es lo que
`?id=N` devuelve en todo el arbol v4.

Las dos puertas aceptan `formato` explicito, asi que ninguna queda atada a su
default:

```bash
# El registro completo, resolviendo por slug.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor&formato=json"

# El Markdown, resolviendo por id.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?id=3&formato=md"
```

Un `formato` desconocido corta con **`400`**, al reves que `order_by` (que cae al
default). La diferencia es que de esto depende **que tipo de documento** se lleva
el cliente: quien escribe `formato=markdown` y recibe un JSON porque el endpoint
ignoro la palabra se lleva una respuesta silenciosamente incorrecta. Un
`order_by` mal escrito, en cambio, devuelve los mismos datos en otro orden.

```json
{"ok":false,"error":"El parametro `formato` solo acepta `md` o `json` (llego `xml`)."}
```

El **listado siempre es JSON**: es un catalogo de varias filas y no existe "el
Markdown" de un conjunto. Un `&formato=md` ahi se ignora a proposito, porque no
hay ninguna lectura razonable de ese pedido.

---

## Se busca por slug o por id

Las dos unicas claves de busqueda son **`?slug=`** y **`?id=N`** (`?codigo=N` en
el listado, que es el mismo id con el nombre que usa el ABM). No hay busqueda por
texto libre:

| Parametro  | En el ABM cloud                                     | Aca                  |
| ---------- | --------------------------------------------------- | -------------------- |
| `?q=`      | Coincidencia parcial sobre `nombre`, `slug` y `contexto` | **`400`**       |
| `?nombre=` | No existe                                            | **`400`**            |

El motivo es el mismo que justifica el endpoint: `nombre` y `contexto` son texto
libre editable desde el panel y no identifican nada de forma estable. Una
integracion que resuelve su experto por aproximacion sobre esas columnas empieza
a bajar **otro prompt** el dia que alguien retoca el catalogo, sin que nada falle
de forma visible — y el sintoma (el bot contesta como si fuera otro producto) no
se parece en nada a la causa.

**Perder `?q=` no le saca alcance a nadie**, porque `?slug=` acepta el texto del
nombre sin formatear: lo que en el ABM se busca como `Reactor Asesor` aca se pide
como `?slug=Reactor Asesor` y resuelve `reactor-asesor` (ver [El slug](#el-slug)).

Los dos parametros **no se ignoran en silencio**. Mandar `?q=` y recibir el
catalogo entero con `200` seria una respuesta silenciosamente incorrecta —el
cliente creeria que filtro— asi que se corta con `400` y el error dice como se
hace ahora:

```json
{"ok":false,"error":"El parametro `q` no esta soportado: `/v4/datarocket/expertos` se consulta por `?slug=...` o por `?id=N`, nada mas. `?slug=` acepta el texto del nombre sin formatear (`?slug=Reactor Asesor` resuelve `reactor-asesor`)."}
```

`proyecto_id`, `activo`, `order_by`, `dir` y `limite` siguen estando: son filtros
y presentacion del listado, no formas de buscar.

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

Los errores quedan registrados en `sucesos` con origen `v4/datarocket.expertos`
(panel cloud > Herramientas > Visor de sucesos): los `4xx` como `alerta`, los
`5xx` como `error`. Las respuestas exitosas no se registran — tampoco las
Markdown, que no llevan el sobre `{"ok":...}` que el logger clasifica.

---

## Contrato de respuesta

Este endpoint tiene **dos formas de respuesta**, y cual sale depende de
`formato`:

```
text/markdown; charset=utf-8   ->  el contexto crudo, sin envoltura
application/json; charset=utf-8 -> { "ok": true,  "data": <payload> }
```

**Todo error es JSON**, venga de donde venga — incluso cuando la llamada pedia
Markdown:

```json
{ "ok": false, "error": "<mensaje>" }
```

O sea que el `Content-Type` de la respuesta no se puede anticipar desde el
request: lo decide el resultado. El codigo HTTP sigue siendo la senal primaria
(`200` / `304` = Markdown si se pidio `md`; cualquier `4xx` / `5xx` = JSON).

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/datarocket/expertos`

| Metodo | Path                                                  | Uso                                                     |
| ------ | ----------------------------------------------------- | ------------------------------------------------------- |
| GET    | `/v4/datarocket/expertos`                             | Listado con filtros (query string). Siempre JSON.        |
| GET    | `/v4/datarocket/expertos?id=N`                        | Consulta individual del experto N. JSON por default.     |
| GET    | `/v4/datarocket/expertos?slug=reactor-asesor`         | **El contexto en Markdown.** `404` si no esta.           |

Cualquier otro metodo devuelve `405`:

```json
{"ok":false,"error":"Metodo no soportado. `/v4/datarocket/expertos` es de solo lectura: los expertos se crean, editan y borran desde el ABM del panel cloud (Sistemas > Datarocket > Expertos)."}
```

Precedencia de los parametros: `?id=N` gana sobre `?slug=`, y `?slug=` gana sobre
el listado. `?q=` y `?nombre=` cortan con `400` antes de todo eso — ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

---

## Modelo de datos

Tabla `datarocket_expertos` (migracion
`20260914_1000_datarocket_expertos_modulo.sql`).

| Campo                | Tipo            | En el JSON | Notas                                                          |
| -------------------- | --------------- | ---------- | -------------------------------------------------------------- |
| `id`                 | `int`           | `int`      | Autoincremental.                                                |
| `proyecto_id`        | `int`           | `int?`     | **Nullable y sin FK.** `null` = experto transversal al grupo.    |
| `slug`               | `varchar(60)`   | `string`   | **UNIQUE global.** Referencia estable (ver abajo).              |
| `nombre`             | `varchar(150)`  | `string`   | Texto libre, editable.                                          |
| `contexto`           | `mediumtext`    | `string?`  | **El prompt de sistema, en Markdown crudo.** Solo en la consulta individual. |
| `activo`             | `tinyint(1)`    | `int`      | `1` / `0`. Un experto inactivo **no se sirve** (ver abajo).     |
| `fecha_creacion`     | `datetime`      | `string`   | La pone la base (`CURRENT_TIMESTAMP`).                          |
| `fecha_modificacion` | `datetime`      | `string`   | La pone la base (`ON UPDATE CURRENT_TIMESTAMP`).                |

El JSON agrega ademas:

| Clave               | Tipo     | Notas                                                                    |
| ------------------- | -------- | ------------------------------------------------------------------------ |
| `proyecto_nombre`   | `string?`| Resuelto con un `LEFT JOIN` a `proyectos`. `null` si `proyecto_id` es `null` o no resuelve. |
| `contexto_largo`    | `int`    | Largo del contexto en caracteres. `0` si no hay.                          |
| `contexto_extracto` | `string` | Primeros 160 caracteres con los saltos colapsados, terminado en `…` si se corto. |

`contexto` **solo aparece en la consulta individual** (`?id=N` o
`?slug=...&formato=json`). El listado manda unicamente `contexto_largo` y
`contexto_extracto`: el texto entero es `MEDIUMTEXT` y puede pesar cientos de KB
por fila, asi que traerlo para todas convertiria un listado de catalogo en una
respuesta de megabytes. Para el listado, la base recorta con `LEFT(contexto, 400)`
y cuenta con `CHAR_LENGTH(contexto)` — el prefijo no viaja nunca al cliente.

Una fila tal como sale de la consulta individual:

```json
{
  "id": 3,
  "proyecto_id": 104,
  "proyecto_nombre": "Reactor",
  "slug": "reactor-asesor",
  "nombre": "Reactor Asesor",
  "contexto_largo": 1383,
  "contexto_extracto": "## Información oficial - Sitio web oficial: www.reactor.com.ar ## Descripción del servicio Reactor es un servicio que permite controlar de manera centralizada l…",
  "activo": 1,
  "fecha_creacion": "2026-09-14 16:29:17",
  "fecha_modificacion": "2026-09-14 17:22:23",
  "contexto": "## Información oficial\n\n- Sitio web oficial: www.reactor.com.ar\n\n## Descripción del servicio\n\n..."
}
```

> **El extracto colapsa los saltos de linea a proposito.** El Markdown crudo
> arranca casi siempre con un `# Titulo` seguido de linea en blanco, y un
> extracto que los conserve se ve como una celda vacia. El `contexto` completo,
> en cambio, sale intacto.

### El slug

Kebab-case estricto — `^[a-z0-9]+(-[a-z0-9]+)*$`, maximo 60 caracteres. Cuando el
operador no lo carga a mano, el ABM del panel lo deriva del `nombre`.

**No hace falta mandarlo perfectamente formateado.** El endpoint aplica al
termino de busqueda la misma transformacion con la que se deriva al dar de alta
(`exSlugify()`, espejo de `drexSlugify()` del ABM y de su gemela en `app.js`):
acentos plegados, minusculas, todo lo que no sea `[a-z0-9]` a guion, guiones de
los bordes recortados, corte a 60. Los tres ejemplos devuelven el mismo contexto:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=Reactor%20Asesor"    # "Reactor Asesor"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=%20REACTOR_ASESOR%20"
```

Que la busqueda use la misma transformacion que el alta es lo que garantiza que
el cliente pueda mandar el slug ya armado **o** el texto del nombre y los dos
caigan en la misma clave. El plegado cubre tambien el texto en forma **NFD**
(donde la `í` de `Vigía Asesor` viaja como `i` + tilde suelta, lo que mandan
varios teclados de macOS / iOS): sin eso la tilde suelta se convertiria en un
guion en el medio de la palabra.

### El slug es unico global

El `UNIQUE` de la tabla es **`slug` a secas** (`uq_drex_slug`), no
(`proyecto_id`, `slug`) como en [embudos](embudos.md#el-slug-es-unico-por-proyecto).
O sea que aca `?slug=` resuelve siempre **una fila o ninguna**, y no existe el
`409` por ambiguedad que en embudos obliga a mandar `&proyecto_id=N`.

Es a proposito: el slug del experto es lo que un canal de entrada usa para pedir
"contesta con este", y ese pedido no viaja acompanado de un proyecto.
`proyecto_id` ademas es **nullable** (experto transversal al grupo), asi que
desambiguar por proyecto ni siquiera seria posible en todos los casos.

### Un experto sin contexto o desactivado no se sirve

Los dos casos podrian contestarse con un `200` de cuerpo vacio. Seria la peor
respuesta posible: del otro lado hay algo que va a usar ese cuerpo como prompt de
sistema, y **un prompt vacio no falla** — deja al modelo contestando sin
personalidad ni datos del producto, que es exactamente el sintoma que nadie
relaciona con "el endpoint devolvio 200".

Por eso los dos cortan con **`409`**:

| Situacion              | Respuesta                                                                       |
| ---------------------- | ------------------------------------------------------------------------------- |
| `activo = 0`           | `409`. Desactivar es la forma que tiene el operador de decir "no contesten mas con este experto"; servirlo igual haria que el toggle del ABM no signifique nada. |
| `contexto` NULL o vacio| `409`. Al 2026-09-29, **tres de los cuatro** expertos de la base estan asi, o sea que no es un caso teorico. |

```json
{
  "ok": false,
  "error": "El experto `vigia-asesor` no tiene contexto cargado. Cargaselo desde el ABM del panel cloud (Sistemas > Datarocket > Expertos): sin contexto no hay prompt de sistema que mandar.",
  "experto": { "id": 5, "proyecto_id": 103, "proyecto_nombre": "Vigia", "slug": "vigia-asesor",
               "nombre": "Vigia Asesor", "contexto_largo": 0, "contexto_extracto": "", "activo": 1,
               "fecha_creacion": "2026-09-14 16:29:42", "fecha_modificacion": "2026-09-14 16:29:42" }
}
```

Es `409` y **no `404`** porque el slug si existe: un `404` mandaria a quien
integra a revisar si lo escribio mal, cuando lo que hay que hacer es cargarle el
contexto o reactivarlo en el panel. El `experto` del cuerpo trae la fila para que
se vea de cual se trata sin una segunda llamada.

El corte por `activo` se puede saltear con **`?incluir_inactivos=1`** —
pensado para previsualizar un experto desde el panel antes de reactivarlo, no
para produccion:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor&incluir_inactivos=1"
```

El corte por contexto vacio **no tiene bypass**: no hay nada que servir.

> **Los dos cortes son solo de la salida Markdown.** `&formato=json` devuelve la
> fila igual, este el experto activo o no y tenga contexto o no — ahi el
> consumidor esta pidiendo el registro, y `activo` y `contexto_largo` son parte
> de lo que vino a ver. El listado, por la misma razon, devuelve activos e
> inactivos salvo que se pida `?activo=1`.

### Cache: `ETag` y `304`

La salida Markdown manda `ETag` y `Cache-Control: no-cache`. `no-cache` no
prohibe cachear: obliga a **revalidar**, que es justo lo que habilita el `304`.
Un cliente que reenvia el ETag en `If-None-Match` se ahorra el cuerpo cuando el
contexto no cambio:

```bash
curl -s -D- -o /dev/null -H "Authorization: Bearer $APIKEY" \
  -H 'If-None-Match: "d478084ac49501e3746cabfb5b317f38"' \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor"
# -> HTTP/1.1 304 Not Modified   (sin cuerpo)
```

Es el patron util para un prompt de sistema, que se relee seguido —tipico: una
vez por conversacion nueva— y cambia poco. Una edicion en el panel se ve en la
llamada siguiente, no cuando vence un TTL.

El ETag se calcula sobre **el contexto**, no sobre `fecha_modificacion`: la fecha
cambia con cualquier edicion de la fila (renombrar el experto, desactivarlo) y
eso invalidaria el cache de un prompt que no cambio. El hash cambia si y solo si
cambio el texto, que es lo unico que ese cuerpo transporta. Se acepta la lista
separada por comas, el `*` y el prefijo `W/` de los validadores debiles.

---

## `GET /v4/datarocket/expertos` — Listado

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

Un `order_by` desconocido cae al default en vez de dar `400` — un parametro mal
escrito no justifica romperle la pantalla al cliente. `q`, `nombre` y un
`formato` invalido, en cambio, si cortan con `400`: no son parametros mal
escritos, son pedidos que el endpoint no puede contestar sin mentir.

> **Default del orden:** alfabetico ascendente por `slug`, al reves que
> `/v4/datarocket/prospectos` (que ordena por `id DESC`). Es a proposito: esto es
> un catalogo chico — 4 expertos al 2026-09-29 — que casi siempre termina en un
> combo o en un vistazo, y ahi el orden util es el alfabetico. Para los criterios
> que no son `slug` ni `nombre` el default de `dir` es `desc`. El desempate
> siempre es por `nombre ASC`, asi que el orden es determinista aun cuando la
> columna elegida empata (pasa siempre con `activo`).

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 4,
    "items": [
      { "id": 6, "proyecto_id": 109, "proyecto_nombre": "Causam", "slug": "causam-asesor",
        "nombre": "Causam Asesor", "contexto_largo": 0, "contexto_extracto": "", "activo": 1,
        "fecha_creacion": "2026-09-14 16:29:51", "fecha_modificacion": "2026-09-14 16:29:51" },
      { "id": 3, "proyecto_id": 104, "proyecto_nombre": "Reactor", "slug": "reactor-asesor",
        "nombre": "Reactor Asesor", "contexto_largo": 1383,
        "contexto_extracto": "## Información oficial - Sitio web oficial: www.reactor.com.ar ## Descripción del servicio Reactor es un servicio que permite controlar de manera centralizada l…",
        "activo": 1, "fecha_creacion": "2026-09-14 16:29:17", "fecha_modificacion": "2026-09-14 17:22:23" }
    ]
  }
}
```

`total` es la cantidad de items **devueltos**, no el total de la tabla — si llega
recortado por `limite`, los dos numeros coinciden y no hay forma de distinguirlo
desde la respuesta. Con 4 expertos en dev, el `limite` default lo trae entero.

`contexto_largo: 0` es la senal de que ese experto **no tiene prompt cargado** y
por lo tanto su `?slug=` va a contestar `409` — ver
[Un experto sin contexto o desactivado no se sirve](#un-experto-sin-contexto-o-desactivado-no-se-sirve).

### Ejemplos `curl`

```bash
# Todos los expertos, alfabetico por slug.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos"

# Solo los de un proyecto que esten activos.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?proyecto_id=104&activo=1"

# El contexto de uno puntual NO sale de aca: va por `?slug=`.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=Reactor%20Asesor"
```

---

## `GET /v4/datarocket/expertos?id=N` — Consulta individual

Devuelve la fila completa, con el `contexto` entero en `data.contexto`. Acepta
`&formato=md` para entregarlo como documento.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?id=3"
# -> {"ok":true,"data":{"id":3,"proyecto_id":104,"slug":"reactor-asesor",...,
#                       "contexto_largo":1383,"contexto":"## Información oficial\n\n..."}}
```

| Codigo | Cuando                                                                   |
| ------ | ------------------------------------------------------------------------ |
| 404    | `Experto no encontrado`                                                   |
| 400    | `formato` distinto de `md` / `json`                                       |
| 409    | Solo con `&formato=md`, si el experto esta desactivado o no tiene contexto |

---

## `GET /v4/datarocket/expertos?slug=...` — El contexto en Markdown

El caso de uso que motiva el endpoint. Devuelve el `contexto` crudo, listo para
anteponerle al modelo como prompt de sistema.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=reactor-asesor"
```

```
## Información oficial

- Sitio web oficial: www.reactor.com.ar

## Descripción del servicio

Reactor es un servicio que permite controlar de manera centralizada los accesos
de usuarios en diferentes puntos de un predio, como un edificio o un barrio
privado.
...
```

Sin envoltura, sin encabezado agregado, sin front-matter — ver
[El contexto sale como Markdown crudo](#el-contexto-sale-como-markdown-crudo).

| Param                | Tipo | Default | Notas                                                    |
| -------------------- | ---- | ------- | -------------------------------------------------------- |
| `slug`               | str  | —       | Obligatorio. Acepta el texto del nombre sin formatear.   |
| `formato`            | enum | `md`    | `md` / `json`.                                            |
| `incluir_inactivos`  | flag | `0`     | Sirve el contexto de un experto con `activo = 0`.         |

### Errores

| Codigo | Cuerpo                                                                                  |
| ------ | --------------------------------------------------------------------------------------- |
| 400    | `{"ok":false,"error":"El \`slug\` a buscar no puede estar vacio."}`                      |
| 400    | `{"ok":false,"error":"El parametro \`formato\` solo acepta \`md\` o \`json\`..."}`       |
| 404    | `{"ok":false,"error":"Experto no encontrado","consulta":{"slug":"no-existe"}}`           |
| 409    | `{"ok":false,"error":"El experto \`X\` no tiene contexto cargado...","experto":{...}}`   |
| 409    | `{"ok":false,"error":"El experto \`X\` existe pero esta desactivado...","experto":{...}}`|

`?slug=` sin valor es `400`, no "sin filtro": el cliente pidio buscar un slug y
devolverle el catalogo entero seria contestarle otra pregunta.

El `404` incluye `consulta.slug` con el valor **ya normalizado**, para que se
entienda contra que se busco realmente (`" Reactor Asesor "` se busco como
`reactor-asesor`).

**No hay variante por aproximacion.** Un `404` aca significa que el experto no
existe con ese slug, no que haya que reintentar con otro texto — la normalizacion
ya cubre mayusculas, acentos, espacios y guiones bajos.

---

## Flujo completo: del slug al prompt de sistema

```bash
SLUG=reactor-asesor

# 1) Bajar el prompt. Es una sola lectura: el cuerpo YA es el prompt.
CONTEXTO=$(curl -s -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/expertos?slug=$SLUG")

# 2) Armar la llamada al modelo con ese texto como system.
jq -n --arg sys "$CONTEXTO" --arg msg "$CONSULTA_DEL_INTERESADO" '{
  model: "claude-sonnet-5",
  system: $sys,
  messages: [ { role: "user", content: $msg } ]
}' > payload.json
```

Para revalidar sin volver a bajar el cuerpo, guardar el `ETag` de la respuesta y
mandarlo en `If-None-Match` la proxima vez — ver
[Cache: ETag y 304](#cache-etag-y-304).

El `id` es estable dentro de esta base, asi que un cliente que corre seguido
puede cachearlo. Lo que **no** conviene cachear entre entornos es el numero en
si: el `id` de `reactor-asesor` en dev no tiene por que ser el mismo que en
produccion — el slug si.

> **Chequeo al arrancar, no en el primer mensaje.** Un `409` por contexto vacio o
> experto desactivado aparece recien cuando alguien escribe. Si el canal valida
> su slug al levantar (una llamada a `&formato=json` y mirar `contexto_largo` y
> `activo`), el problema se ve en el deploy y no en la primera consulta real.

---

## Por que es de solo lectura

No hay `POST`, `PUT`, `PATCH` ni `DELETE` en este endpoint. No es un olvido.

Un experto no es un dato que llegue de una integracion: es **curaduria** —
redactar el prompt con el que un producto del grupo se presenta ante sus
interesados. Eso define que dice el bot sobre precios, instalacion y soporte, y
se revisa antes de publicarlo.

Todo eso vive unicamente en el ABM del panel cloud, donde hay usuario
identificado, permisos (`datarocket.expertos.*`) y suceso asociado — ademas del
editor con vista previa y del asistente de mejora
([datarocket_expertos_mejorar.php](../../../cloud/api/datarocket_expertos_mejorar.php)).
Desde afuera el experto se consulta; no se toca.

Un `slug` que el integrador espera y no existe es un `404` que alguien tiene que
ir a resolver al panel — deliberadamente, no creando el experto al vuelo. Y un
experto creado al vuelo, sin contexto, seria justo el caso que el `409` esta
tratando de evitar.

---

## Referencias

- Implementacion: [expertos.php](expertos.php)
- ABM interno del panel: [cloud/api/datarocket_expertos.php](../../../cloud/api/datarocket_expertos.php)
- Chat de prueba del experto (panel): [cloud/api/datarocket_expertos_chat.php](../../../cloud/api/datarocket_expertos_chat.php)
- Asistente de mejora del contexto (panel): [cloud/api/datarocket_expertos_mejorar.php](../../../cloud/api/datarocket_expertos_mejorar.php)
- Catalogo de embudos (mismo patron de resolucion por slug, pero unico **por proyecto**): [embudos.md](embudos.md)
- Endpoint de prospectos: [prospectos.md](prospectos.md)
- Esquema de la base: [db/schema.sql](../../../db/schema.sql)
- Migracion de la tabla: `cloud/sql/migrations/20260914_1000_datarocket_expertos_modulo.sql`
