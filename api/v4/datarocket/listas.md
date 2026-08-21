# `/v4/datarocket/listas`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/listas.md>

Microservicio del **CRM Datarocket** sobre la tabla `datarocket_listas` — el
catalogo de listas de suscripcion. Cada lista agrupa prospectos a traves de la
tabla puente `datarocket_prospectos_listas`, y es lo que despues consume un
envio masivo (correo / WhatsApp). Un unico archivo `.php`
([listas.php](listas.php)) que sirve todo el recurso — sin framework ni router
aparte.

La operacion que motiva el microservicio:

| Quiero…                                          | Uso                                                    |
| ------------------------------------------------ | ------------------------------------------------------ |
| **Tengo el slug y necesito el `id` de la lista** | `GET /v4/datarocket/listas?slug=vigicom-usuarios`       |
| …y ademas cuanta gente tiene hoy, exacto         | `GET /v4/datarocket/listas?slug=...&con_conteo=1`       |
| Ver que listas hay                               | `GET /v4/datarocket/listas`                             |
| Ver las de un proyecto                           | `GET /v4/datarocket/listas?proyecto_id=104`             |
| Consultar una que ya tengo identificada          | `GET /v4/datarocket/listas?id=136`                      |

**Se busca por `slug` o por `id`, nada mas.** No hay busqueda por texto libre:
`?q=` se retiro y `?nombre=` no existe — los dos devuelven `400`. Ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

**Este endpoint es de solo lectura.** No hay `POST`, `PUT`, `PATCH` ni `DELETE`.
Ver [Por que es de solo lectura](#por-que-es-de-solo-lectura).

**El slug es unico por proyecto, no global** — y entre las listas *sin* proyecto
no es unico en absoluto. Un `?slug=` que matchea en dos lados devuelve `409` con
los candidatos, no "el primero". Ver
[El slug es unico por proyecto](#el-slug-es-unico-por-proyecto).

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/datarocket/listas
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo via
HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket >
Listas) usa su propio endpoint
[cloud/api/datarocketlistas.php](../../../cloud/api/datarocketlistas.php), que
ademas expone el alta, la modificacion y la baja. Los dos leen la misma tabla; la
diferencia es la capa de auth (permisos de sesion vs. Bearer estatico) y el
alcance de las operaciones.

Es el gemelo de [`/v4/datarocket/embudos`](embudos.md): mismo patron de
resolucion, mismos flags, mismo contrato. Las dos diferencias que importan estan
abajo — aca `proyecto_id` es **nullable** y `suscriptos` es un **snapshot**, no
un contador vivo.

---

## Para que existe: resolver el slug a un id

Lo que un integrador tiene a mano es un identificador estable escrito en su
configuracion (`vigicom-usuarios`), no el `id` autoincremental que le toco a la
lista en esta base. Y el `nombre` tampoco sirve como referencia: es texto libre,
editable desde el panel, y trae acentos, espacios y mayusculas
(`Vigicom Prospectos Fríos`).

El **`slug`** es exactamente esa referencia estable — kebab-case, maximo 40
caracteres, `UNIQUE` por proyecto (agregado por la migracion
`20260821_1000`, mismo criterio que `datarocket_embudos.slug` y
`datacount_empresas.slug`). Este endpoint lo traduce al `id` que despues viaja
dentro de `lista_ids` hacia `/v4/datarocket/prospectos`, y de paso devuelve **la
fila entera**, incluido `proyecto_id`.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=vigicom-usuarios"
# -> {"ok":true,"data":{"id":136,"proyecto_id":102,...}}
#                        ^^^^^^^^^ esto es lo que se manda dentro de lista_ids
```

> **Por que hace falta este endpoint y no alcanza con prospectos.**
> `POST /v4/datarocket/prospectos` acepta `embudo` por nombre o slug, pero
> `lista_ids` **solo por id** — mandar nombres ahi no funciona (ver
> [prospectos.md](prospectos.md#mandar-el-nombre-de-la-lista-en-vez-del-id-no-funciona)).
> Sin este catalogo no habia forma de conseguir ese numero desde afuera del
> panel.

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
integracion que resuelve su lista por aproximacion sobre esas columnas queda
atada a como este redactado el catalogo hoy, y el dia que alguien lo retoca desde
el ABM la resolucion empieza a devolver otra fila —o ninguna— sin que nada falle
de forma visible. **Con listas eso no es un detalle: la fila equivocada es la
audiencia equivocada de un envio masivo.**

**Perder `?q=` no le saca alcance a nadie**, porque `?slug=` acepta el texto del
nombre sin formatear: lo que antes se pedia como `?q=Vigicom Usuarios` ahora se
pide como `?slug=Vigicom Usuarios` y resuelve `vigicom-usuarios` (ver
[El slug](#el-slug)). La diferencia es que devuelve **la** fila, no un conjunto
parecido.

Los dos parametros **no se ignoran en silencio**. Mandar `?q=` y recibir el
catalogo entero con `200` seria una respuesta silenciosamente incorrecta —el
cliente creeria que filtro— asi que se corta con `400` y el error dice como se
hace ahora:

```json
{"ok":false,"error":"El parametro `q` no esta soportado: `/v4/datarocket/listas` se consulta por `?slug=...` o por `?id=N`, nada mas. `?slug=` acepta el texto del nombre sin formatear (`?slug=Vigicom Usuarios` resuelve `vigicom-usuarios`)."}
```

`proyecto_id`, `order_by`, `dir` y `limite` siguen estando: son filtros y
presentacion del listado, no formas de buscar.

Si de verdad hace falta una aproximacion, el catalogo entra entero en una sola
llamada (29 listas al 2026-08-21) y se filtra del lado del consumidor.

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

Todo error (incluidos los `401`) queda registrado en la tabla `sucesos`, que es
lo que lee el **Visor de sucesos** del panel: los `4xx` como `alerta` y los `5xx`
como `error`, con origen `v4/datarocket.listas`. Las respuestas exitosas no se
registran.

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

Base URL: `https://api.databox.net.ar/v4/datarocket/listas`

| Metodo | Path                                            | Uso                                             |
| ------ | ----------------------------------------------- | ----------------------------------------------- |
| GET    | `/v4/datarocket/listas`                         | Listado con filtros (query string).             |
| GET    | `/v4/datarocket/listas?id=N`                    | Consulta individual de la lista N.              |
| GET    | `/v4/datarocket/listas?slug=vigicom-usuarios`   | Resolucion slug → registro completo. `404` si no esta. |

Cualquier otro metodo devuelve `405`:

```json
{"ok":false,"error":"Metodo no soportado. `/v4/datarocket/listas` es de solo lectura: las listas se crean, editan y borran desde el ABM del panel cloud. Para suscribir un prospecto a una lista usa `lista_ids` en /v4/datarocket/prospectos."}
```

Precedencia de los parametros: `?id=N` gana sobre `?slug=`, y `?slug=` gana sobre
el listado. `?q=` y `?nombre=` cortan con `400` antes de todo eso — ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id).

---

## Modelo de datos

Tabla `datarocket_listas` (migraciones `20260811_1200`, `20260811_1300`,
`20260815_1200` y `20260821_1000`).

| Campo         | Tipo           | En el JSON | Notas                                                        |
| ------------- | -------------- | ---------- | ------------------------------------------------------------ |
| `id`          | `int`          | `int`      | Autoincremental. Es lo que se manda dentro de `lista_ids`.   |
| `proyecto_id` | `int` NULL     | `int?`     | FK a `proyectos`. **Opcional** — el ABM ofrece "— Sin proyecto —". |
| `slug`        | `varchar(40)`  | `string`   | **UNIQUE por proyecto.** Referencia estable (ver abajo).     |
| `nombre`      | `varchar(255)` NULL | `string?` | Texto libre, editable.                                   |
| `descripcion` | `varchar(500)` NULL | `string?` | Nota interna opcional. Vacia se guarda como `null`.      |
| `suscriptos`  | `int` NULL     | `int?`     | Contador **denormalizado**, puede estar atrasado (ver abajo). |

La tabla **no tiene `activo` ni timestamps** — a diferencia de
`datarocket_embudos`, esas seis columnas son todo el registro. El JSON agrega
`proyecto_nombre` (`string?`), resuelto con un `LEFT JOIN` a `proyectos`; es
`null` cuando la lista no tiene proyecto o cuando el proyecto no resuelve — la
lista se devuelve igual en vez de desaparecer del listado.

Una fila tal como sale del endpoint:

```json
{
  "id": 136,
  "proyecto_id": 102,
  "proyecto_nombre": "Vigicom",
  "slug": "vigicom-usuarios",
  "nombre": "Vigicom Usuarios",
  "descripcion": null,
  "suscriptos": 7038
}
```

`?con_conteo=1` agrega `suscriptos_reales` (int). La clave **no aparece** si no
se pidio: una clave ausente y una clave en `null` significan cosas distintas y
mezclarlas obliga al consumidor a adivinar.

Los tres campos nullables se publican como `null`, sin aplanar:

- `proyecto_id: null` → lista sin proyecto, que es una opcion real del ABM.
- `nombre: null` → la columna lo permite. Hoy no pasa, pero nada lo impide.
- `suscriptos: null` → **nunca se recalculo**, distinto de `0`. Es el `DEFAULT`
  de la columna, o sea lo que queda al crear una lista nueva desde el ABM.

### El slug

Kebab-case estricto — `^[a-z0-9]+(-[a-z0-9]+)*$`, maximo 40 caracteres. Cuando el
operador no lo carga a mano, el ABM del panel lo deriva del `nombre`.

**No hace falta mandarlo perfectamente formateado.** El endpoint aplica al
termino de busqueda la misma transformacion con la que se deriva al dar de alta
(`lisSlugify()`, espejo de `drliSlugify()` del ABM, de su gemela JS en `app.js` y
del backfill de la migracion `20260821_1000`): acentos plegados, minusculas, todo
lo que no sea `[a-z0-9]` a guion, guiones de los bordes recortados, corte a 40.
Los tres ejemplos devuelven la misma fila:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=reactor-prospectos-frios"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=Reactor%20Prospectos%20Fr%C3%ADos"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=%20REACTOR_PROSPECTOS_FRIOS%20"
```

Que la busqueda use la misma transformacion que el alta es lo que garantiza que
el cliente pueda mandar el slug ya armado **o** el texto del nombre y los dos
caigan en la misma clave. El plegado cubre tambien el texto en forma **NFD**
(donde la `í` viaja como `i` + tilde suelta, lo que mandan varios teclados de
macOS / iOS): sin eso la tilde suelta se convertiria en un guion en el medio de
la palabra y `frios` quedaria como `fri-os`.

Un `?slug=` que despues de normalizar no deja nada aprovechable (`?slug=#$%`) es
un `400`, no un `404`: no llego a ser una busqueda.

### El slug es unico por proyecto

El `UNIQUE` de la tabla es **(`proyecto_id`, `slug`)**, no `slug` a secas: dos
proyectos distintos pueden tener cada uno su `clientes`.

Y hay una vuelta de tuerca que **no** tiene `datarocket_embudos`: aca
`proyecto_id` es nullable, y MySQL / MariaDB tratan cada `NULL` como distinto
dentro de un indice unico. O sea que el `UNIQUE` **no restringe a las filas sin
proyecto**: dos listas huerfanas pueden compartir slug y la base las acepta sin
chistar. Es una degradacion asumida a nivel esquema (ver el encabezado de la
migracion `20260821_1000`), no algo que esta capa pueda arreglar.

Por eso un `?slug=` suelto puede matchear mas de una fila. Devolver "la primera"
seria una respuesta silenciosamente incorrecta — el cliente se llevaria la lista
de otro proyecto sin enterarse, y con listas eso termina en un envio masivo a la
audiencia equivocada — asi que la ambiguedad se contesta con **`409` y la lista
completa de candidatos**:

```json
{
  "ok": false,
  "error": "El slug `clientes` existe en mas de una lista. Agrega `&proyecto_id=N` para desambiguar (`&proyecto_id=0` = las que no tienen proyecto).",
  "consulta": { "slug": "clientes" },
  "listas": [
    { "id": 9002, "proyecto_id": 102, "proyecto_nombre": "Vigicom", "slug": "clientes", ... },
    { "id": 9001, "proyecto_id": 104, "proyecto_nombre": "Reactor", "slug": "clientes", ... }
  ]
}
```

Se resuelve agregando `&proyecto_id=N`:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=clientes&proyecto_id=104"
# -> 200 {"ok":true,"data":{"id":9001,...}}
```

**Salvo que las coincidencias sean todas huerfanas.** Si el slug se repite entre
listas sin proyecto, no hay valor de `proyecto_id` que las separe — ni siquiera
`0`. El `409` lo detecta y cambia la recomendacion en vez de mandar al cliente a
intentar algo que no puede funcionar:

```json
{
  "ok": false,
  "error": "El slug `huerfana` existe en mas de una lista. Hay 2 coincidencias sin proyecto y `proyecto_id` no las separa: elegi una de la lista y consultala por `?id=N`.",
  "consulta": { "slug": "huerfana" },
  "listas": [ { "id": 9003, "proyecto_id": null, ... }, { "id": 9004, "proyecto_id": null, ... } ]
}
```

Al 2026-08-21 las 29 listas tienen proyecto y no hay ningun slug repetido, o sea
que en la practica el `409` no aparece. El endpoint no se apoya en eso porque
nada lo garantiza: alcanza con que alguien cree desde el panel un `clientes` en
un segundo proyecto.

### `proyecto_id=0` significa "sin proyecto"

Como `proyecto_id` es nullable, hacen falta **dos** cosas distintas que un entero
solo no distingue: "no filtres por proyecto" y "dame las que no tienen ninguno".
`?proyecto_id=` vacio (o ausente) ya significa lo primero, asi que el `0` se usa
como sentinel de lo segundo — se traduce a `proyecto_id IS NULL`.

No es un invento de esta capa: es lo que la columna guardaba **antes** de la
migracion `20260815_1200`, que hizo
`UPDATE datarocket_listas SET proyecto_id = NULL WHERE proyecto_id = 0` para
poder colgarle la FK.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?proyecto_id=0"
# -> las listas huerfanas. Hoy: {"total":0,"items":[]}
```

Vale igual en el listado y en `?slug=`, y el `404` de la resolucion por slug
reporta el sentinel con el que entro:

```json
{"ok":false,"error":"Lista no encontrada","consulta":{"slug":"no-existe","proyecto_id":0}}
```

### `suscriptos` es un snapshot, no un contador vivo

La columna `suscriptos` es un denormalizado que se recalcula **a mano** desde el
ABM del panel cloud (boton "Recalcular suscriptos" →
`cloud/api/datarocketlistas_recalcular.php`), no un trigger. Entre recalculos
queda atrasada respecto de la puente: al 2026-08-21 `reactor-usuarios` dice
`2393` y `datarocket_prospectos_listas` tiene `2392`.

Se publica igual porque para pintar un listado alcanza y sale gratis. Cuando el
numero tiene que ser exacto va **`?con_conteo=1`**, que agrega
`suscriptos_reales` contado en vivo contra la puente:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=reactor-usuarios&con_conteo=1"
```

```json
{
  "ok": true,
  "data": {
    "id": 135,
    "slug": "reactor-usuarios",
    "suscriptos": 2393,
    "suscriptos_reales": 2392
  }
}
```

`suscriptos_reales` nunca es `null`: una lista sin nadie da `0`. La diferencia
entre los dos numeros es exactamente el desfasaje acumulado desde el ultimo
recalculo, asi que compararlos sirve para decidir si conviene pedirlo.

El flag vale para el listado, para `?id=N` y para `?slug=`. Cuesta una query
extra sobre una tabla que crece con los prospectos (decenas de miles de filas),
asi que no viene por default.

---

## `GET /v4/datarocket/listas` — Listado

### Query params

El listado **no busca: filtra.** Todos los parametros de abajo acotan o presentan
el conjunto; para llegar a una fila puntual estan `?slug=` y `?id=N`.

| Param         | Tipo   | Default | Notas                                                        |
| ------------- | ------ | ------- | ------------------------------------------------------------ |
| `codigo`      | int    | —       | Filtra por `id` exacto.                                       |
| `proyecto_id` | int    | —       | Filtra por proyecto. **`0` = las que no tienen proyecto.**    |
| `order_by`    | enum   | `slug`  | `id`, `proyecto_id`, `slug`, `nombre`, `suscriptos`. Tambien se acepta como `orden`. |
| `dir`         | enum   | ver nota| `asc` / `desc`.                                               |
| `limite`      | int    | `100`   | Clampeado a `[1, 1000]`.                                      |
| `con_conteo`  | flag   | `0`     | Agrega `suscriptos_reales` a cada item.                       |

Un `order_by` desconocido cae al default en vez de dar `400` — un parametro mal
escrito no justifica romperle la pantalla al cliente. `q` y `nombre`, en cambio,
si cortan con `400`: no son parametros mal escritos, son busquedas que el
endpoint ya no hace, y contestarlas con el catalogo entero seria mentir.

> **Default del orden:** alfabetico ascendente por `slug`, al reves que
> `/v4/datarocket/prospectos` (que ordena por `id DESC`). Es a proposito: esto es
> un catalogo chico — 29 listas al 2026-08-21 — que casi siempre termina en un
> combo o en un vistazo, y ahi el orden util es el alfabetico. Para los criterios
> que no son `slug` ni `nombre` el default de `dir` es `desc`, que con
> `suscriptos` deja arriba las listas grandes.

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 29,
    "items": [
      { "id": 124, "proyecto_id": 109, "proyecto_nombre": "Causam", "slug": "causam-clientes",
        "nombre": "Causam Clientes", "descripcion": null, "suscriptos": 1 },
      { "id": 125, "proyecto_id": 109, "proyecto_nombre": "Causam", "slug": "causam-exclientes",
        "nombre": "Causam Exclientes", "descripcion": null, "suscriptos": 0 }
    ]
  }
}
```

`total` es la cantidad de items **devueltos**, no el total de la tabla — si llega
recortado por `limite`, los dos numeros coinciden y no hay forma de distinguirlo
desde la respuesta. Con 29 listas en dev, el `limite` default las trae enteras.

### Ejemplos `curl`

```bash
# Todas las listas, alfabetico por slug.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas"

# Las de un proyecto, las mas pobladas primero, con el conteo exacto.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?proyecto_id=104&order_by=suscriptos&con_conteo=1"

# Una lista puntual NO sale de aca: va por `?slug=` (acepta el texto del nombre).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=Causam%20Prospectos%20Fr%C3%ADos"

# Las listas huerfanas (sin proyecto asignado).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?proyecto_id=0"
```

---

## `GET /v4/datarocket/listas?id=N` — Consulta individual

Devuelve la fila completa. Acepta `?con_conteo=1`.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?id=136&con_conteo=1"
# -> {"ok":true,"data":{"id":136,"proyecto_id":102,"slug":"vigicom-usuarios",...,
#                       "suscriptos":7038,"suscriptos_reales":7038}}
```

| Codigo | Cuando                   |
| ------ | ------------------------ |
| 404    | `Lista no encontrada`    |

Es tambien la salida de emergencia cuando `?slug=` da `409` y `proyecto_id` no
alcanza para desambiguar: los candidatos del `409` vienen con su `id`.

---

## `GET /v4/datarocket/listas?slug=...` — Resolucion slug → registro

El caso de uso que motiva el endpoint. Devuelve **la misma forma que `?id=N`** —
un objeto con la fila entera, no una lista. Acepta `?con_conteo=1` y
`?proyecto_id=N` (para desambiguar).

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=vigicom-usuarios"
```

```json
{
  "ok": true,
  "data": {
    "id": 136,
    "proyecto_id": 102,
    "proyecto_nombre": "Vigicom",
    "slug": "vigicom-usuarios",
    "nombre": "Vigicom Usuarios",
    "descripcion": null,
    "suscriptos": 7038
  }
}
```

### Errores

| Codigo | Cuerpo                                                                          |
| ------ | ------------------------------------------------------------------------------- |
| 400    | `{"ok":false,"error":"El \`slug\` a buscar no puede estar vacio."}`              |
| 404    | `{"ok":false,"error":"Lista no encontrada","consulta":{"slug":"no-existe"}}`     |
| 409    | `{"ok":false,"error":"El slug \`X\` existe en mas de una lista...","listas":[...]}` |

`?slug=` sin valor es `400`, no "sin filtro": el cliente pidio buscar un slug y
devolverle el catalogo entero seria contestarle otra pregunta.

El `404` incluye `consulta.slug` con el valor **ya normalizado**, para que se
entienda contra que se busco realmente (`" Vigicom Usuarios "` se busco como
`vigicom-usuarios`). Si se mando `proyecto_id`, tambien viaja ahi.

**No hay variante por aproximacion.** El `?q=` que la ofrecia se retiro (ver
[Se busca por slug o por id](#se-busca-por-slug-o-por-id)); un `404` aca significa
que la lista no existe con ese slug, no que haya que reintentar con otro texto —
la normalizacion ya cubre mayusculas, acentos, espacios y guiones bajos.

---

## Flujo completo: del slug a la suscripcion

```bash
# 1) Slug -> lista. Una sola llamada.
LI=$(curl -s -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=vigicom-usuarios")

LISTA_ID=$(echo "$LI" | jq -r '.data.id')          # -> 136
PROYECTO_ID=$(echo "$LI" | jq -r '.data.proyecto_id')

# 2) Con ese id ya se puede suscribir gente desde el endpoint de prospectos.
curl -s -X POST -H "Authorization: Bearer $APIKEY" -H 'Content-Type: application/json' \
  "https://api.databox.net.ar/v4/datarocket/prospectos" \
  -d "{\"nombre\":\"Ada\",\"correo\":\"ada@example.com\",\"lista_ids\":[$LISTA_ID]}"
```

Ojo con `lista_ids` en `PUT`: es **reemplazo total**, no "agregar" — manda el set
completo o el prospecto pierde las suscripciones que no repitas. En `POST` con
consulta (`asunto` + `mensaje`) sobre un prospecto que ya existe, en cambio, se
**suman** sin borrar las anteriores. Los detalles estan en
[prospectos.md](prospectos.md#lista_ids-es-reemplazo-total-no-agregar).

Un id inexistente dentro de `lista_ids` se descarta en silencio del lado de
prospectos, asi que conviene resolver el slug aca primero y no adivinar numeros.

El `id` es estable dentro de esta base, asi que un cliente que corre seguido
puede cachearlo. Lo que **no** conviene cachear entre entornos es el numero en
si: el `id` de `vigicom-usuarios` en dev no tiene por que ser el mismo que en
produccion — el slug si.

---

## Por que es de solo lectura

No hay `POST`, `PUT`, `PATCH` ni `DELETE` en este endpoint. No es un olvido.

Una lista no es un dato que llegue de una integracion, es **configuracion del
CRM**: define a quien le va a llegar un envio masivo. Crearla implica elegir el
proyecto al que pertenece, y borrarla se lleva puestas **todas** las
suscripciones por el `ON DELETE CASCADE` de `fk_dpl_lista`, sin aviso y sin
vuelta atras (`vigicom-usuarios` tiene 7.038 al 2026-08-21;
`causam-prospectos-frios`, 19.684).

Todo eso es curaduria, no integracion, asi que vive unicamente en el ABM del
panel cloud, donde hay usuario identificado, permisos (`datarocket.listas.*`) y
suceso asociado. Desde afuera la lista se consulta; no se toca.

**Lo que si es una operacion de integracion —suscribir un prospecto a una lista—
ya existe y esta en otro endpoint:** `lista_ids` en el `POST` / `PUT` / `PATCH`
de [prospectos.md](prospectos.md). El `405` de aca lo dice explicitamente, porque
es el error mas probable: quien intenta un `POST` contra `/listas` casi siempre
esta buscando eso.

Tampoco hay un `?resolver=1` como el de [etiquetas](etiquetas.md): una etiqueta
nueva es inofensiva, una lista creada al vuelo es una audiencia fantasma que
nadie configuro y a la que despues alguien le manda una campaña. Un `slug` que el
integrador espera y no existe es un `404` que alguien tiene que ir a resolver al
panel, deliberadamente.

---

## Referencias

- Implementacion: [listas.php](listas.php)
- Principal consumidor del catalogo: `lista_ids` en [prospectos.md](prospectos.md#seleccionar-las-listas)
- ABM interno del panel: [cloud/api/datarocketlistas.php](../../../cloud/api/datarocketlistas.php)
- Recalculo de `suscriptos`: [cloud/api/datarocketlistas_recalcular.php](../../../cloud/api/datarocketlistas_recalcular.php)
- Catalogo hermano, mismo patron de resolucion por slug: [embudos.md](embudos.md)
- Catalogo de etiquetas (resolucion texto → id, con alta idempotente): [etiquetas.md](etiquetas.md)
- Esquema de la base: [db/schema.sql](../../../db/schema.sql) — ojo, ahi vive la
  tabla legacy `datarocketlistas` (sin guion bajo), que es otra cosa; la estructura
  vigente de `datarocket_listas` sale de las migraciones.
- Migraciones de la tabla: `cloud/sql/migrations/20260811_1200_datarocket_listas_descripcion.sql`,
  `20260811_1300_datarocket_listas_renombrar_suscripciones_a_suscriptos.sql`,
  `20260815_1200_datarocket_fks_proyecto.sql`,
  `20260821_1000_datarocket_listas_agregar_slug.sql`
- Tabla puente: `cloud/sql/migrations/20260811_1400_crear_datarocket_contactos_listas.sql`
  (renombrada a `datarocket_prospectos_listas` por `20260817_2700_datarocket_contactos_a_prospectos.sql`)
