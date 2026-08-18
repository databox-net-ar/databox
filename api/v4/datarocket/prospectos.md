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
| Etiquetarlo                                | `etiqueta_ids` + [/v4/datarocket/etiquetas](etiquetas.md) |
| **Registrar una consulta que entra por la web** | `POST` con `embudo` + `asunto` + `mensaje`       |

**Un `POST` con `embudo`, `asunto` y `mensaje` crea tres registros: el
prospecto, la oportunidad en ese embudo y la interaccion con el mensaje.** Es el
alta de un formulario web, y es una sola llamada transaccional. Sin esas tres
claves el alta crea unicamente el prospecto, como siempre. Ver
[Registrar una consulta](#registrar-una-consulta-embudo-asunto-y-mensaje).

**Un prospecto no se puede dar de alta si su `correo` o su `celular` ya estan
registrados.** El `POST` corta con `409`; `?verificar=1` permite preguntarlo
antes. Ver [Unicidad de correo y celular](#unicidad-de-correo-y-celular). La
excepcion es el alta con consulta: ahi el prospecto que ya existe **se
reutiliza** en vez de rechazarse — el cliente que vuelve a escribir no es un
error.

**Para seleccionar el pais, la provincia y la localidad se usa
[/v4/databox/ubicaciones](../databox/ubicaciones.md).** Este endpoint recibe
`pais_id` / `provincia_id` / `localidad_id` (ids del catalogo geografico, no
texto) y rechaza con `400` los que no existan; `ubicaciones` es el que traduce
un nombre a esos ids, con la misma apikey. Ver
[Seleccionar pais, provincia y localidad](#seleccionar-pais-provincia-y-localidad).

**Las etiquetas tampoco se mandan como texto: `etiqueta_ids` son ids del
catalogo [/v4/datarocket/etiquetas](etiquetas.md).** Ese endpoint —misma
apikey— traduce el nombre a un id (`GET ?nombre=expo`) y, si la etiqueta todavia
no existe, **la crea al vuelo** (`POST ?resolver=1`), asi que un importador que
trae etiquetas nuevas no se frena. Ver
[Seleccionar las etiquetas](#seleccionar-las-etiquetas).

**El `embudo` se manda por nombre o por slug, no por id.** Se resuelve contra el
catalogo [/v4/datarocket/embudos](embudos.md) —`Causam Clientes` y
`causam-clientes` caen en la misma fila— y de el salen el `proyecto_id` y la
etapa de entrada de la oportunidad. La columna `embudo_id` no existe en
`datarocket_prospectos`: vive en `datarocket_oportunidades`. Ver
[El embudo no es una columna del prospecto](#el-embudo-no-es-una-columna-del-prospecto).

> **Renombrado el 2026-08-17.** Este recurso se llamaba `/v4/datarocket/contactos`
> hasta la migracion `20260817_2700`. Esa ruta **ya no responde** (`404`): el
> concepto "contacto" dejo de existir en Datarocket, no quedo alias de
> compatibilidad. Ademas las claves que antes eran `contacto_*` ahora son
> `prospecto_*`, asi que una integracion vieja tiene que actualizar las dos
> cosas: la URL y la lectura del JSON.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/datarocket/prospectos
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

## Seleccionar las etiquetas

Las etiquetas de un prospecto **no se mandan como texto**. `etiqueta_ids` es un
`int[]` con ids de `datarocket_etiquetas`, y el catalogo se consulta —o se
amplia— desde **[/v4/datarocket/etiquetas](etiquetas.md)**, con la misma apikey.
Ese endpoint es el unico camino desde afuera: el ABM del panel tiene su propio
lookup pero autentica por sesion, no por Bearer.

### A) Ver que hay disponible (combo, pantalla de seleccion)

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas"
# -> {"ok":true,"data":{"total":29,"items":[{"id":13,"nombre":"barrio_privado",...},...]}}
```

Viene ordenado alfabeticamente y con `limite` default de 100 — el catalogo tiene
29 filas, asi que entra entero en una llamada.

### B) Ya tenes el nombre y solo queres el id

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?nombre=EXPO"
# -> {"ok":true,"data":{"id":5,"nombre":"expo",...}}
```

`404` si no existe — **no la crea**. Es la opcion para cuando el catalogo lo
curan personas y una etiqueta desconocida es un error de datos que alguien tiene
que mirar.

La comparacion es **insensible a mayusculas y acentos** (`EXPO`, `expo`, `expó`
son la misma etiqueta), asi que no hace falta normalizar el texto del CSV antes
de consultar.

### C) "Dame el id, y si no existe creala"

```bash
curl -X POST "https://api.databox.net.ar/v4/datarocket/etiquetas?resolver=1" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"nombre":"expo 2027","descripcion":"Feria de Buenos Aires"}'
# -> 201 {"ok":true,"data":{"id":40,"nombre":"expo 2027",...,"creada":true}}
# -> 200 {"ok":true,"data":{"id":40,"nombre":"expo 2027",...,"creada":false}}  (si ya estaba)
```

Nunca da `409`: `data.creada` distingue si la acaba de crear (`201`) o si ya
existia (`200`). Es idempotente — llamarlo N veces con el mismo nombre deja una
sola fila. Es la opcion para un importador que trae etiquetas nuevas junto con
los prospectos y no quiere frenarse.

Tambien existe el `POST` sin `?resolver=1`, que corta con `409` si el nombre ya
esta cargado (el body del error trae la fila que choco en la clave `etiqueta`,
con su `id`).

### Un id inexistente en `etiqueta_ids` se descarta en silencio

A diferencia de `pais_id` / `provincia_id` / `localidad_id` —que cortan con
`400`—, los ids de `etiqueta_ids` que no existan en el catalogo **se filtran
antes del INSERT y el alta responde `201` igual**, con esa etiqueta simplemente
sin aplicar. Los duplicados dentro del array tambien se colapsan. Por eso
conviene resolver el id contra `/v4/datarocket/etiquetas` en vez de inventarlo:
no hay error que avise.

Para confirmar que quedo aplicada, releer con
`GET /v4/datarocket/prospectos?id=N` y mirar `etiqueta_ids` /
`etiqueta_nombres`.

### Mandar nombres en vez de ids no funciona

`etiqueta_nombres` es **solo de lectura**: se devuelve en el `GET`, pero si viene
en el body de un `POST` / `PUT` / `PATCH` se ignora en silencio, como cualquier
clave desconocida. No hay alias por nombre — primero se resuelve el id, despues
se postea.

### `etiqueta_ids` es reemplazo total, no "agregar"

En `PUT` y `PATCH` la puente se reemplaza por completo con lo que traiga el
array: mandar `[5]` a un prospecto que ya tenia otras dos etiquetas lo deja con
una sola, y `[]` lo deja sin ninguna. Para **sumar** una etiqueta hay que leer
primero el prospecto y postear la union (`GET ?id=N` -> `etiqueta_ids: [12,18]`
-> `PATCH {"etiqueta_ids":[12,18,5]}`).

Si la clave **no viene** en el body de un `PUT` o un `PATCH`, la puente no se
toca. Lo mismo vale para `lista_ids`, con el catalogo `datarocket_listas`.

### Flujo completo

```bash
# 1) Nombre -> id, creando la etiqueta si es nueva.
ET=$(curl -s -X POST "https://api.databox.net.ar/v4/datarocket/etiquetas?resolver=1" \
       -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
       -d '{"nombre":"expo"}')
# -> {"ok":true,"data":{"id":5,"nombre":"expo",...,"creada":false}}

# 2) Aplicarla en el alta del prospecto…
curl -X POST "https://api.databox.net.ar/v4/datarocket/prospectos" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"tipo":"persona","persona_nombre":"Juan Perez","etiqueta_ids":[5]}'

# 3) …o sobre un prospecto que ya existe.
curl -X PATCH "https://api.databox.net.ar/v4/datarocket/prospectos?id=149309" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"etiqueta_ids":[5]}'
# -> {"ok":true,"data":{"id":149309,"campos":["etiqueta_ids"]}}
```

### Modificar y borrar el catalogo no se puede desde afuera

`/v4/datarocket/etiquetas` solo consulta y da de alta: no tiene `PUT`, `PATCH`
ni `DELETE`. Renombrar una etiqueta le cambia el significado a todos los
prospectos que la tienen puesta, y borrarla se lleva las asignaciones por el
`ON DELETE CASCADE` de la puente. Esas operaciones viven unicamente en el ABM
del panel cloud. Ver
[Por que no se puede modificar ni borrar](etiquetas.md#por-que-no-se-puede-modificar-ni-borrar).

---

## Registrar una consulta: embudo, asunto y mensaje

Un formulario web no genera "un prospecto": genera una **consulta**. Quien la
manda es el prospecto, lo que pregunta es la interaccion, y el trabajo que eso
abre es la oportunidad. Los tres registros nacen del mismo evento, asi que
nacen del mismo `POST`:

```
POST /v4/datarocket/prospectos
  { …datos del prospecto…, "embudo": …, "asunto": …, "mensaje": … }

  -> datarocket_prospectos      (quien escribio)
  -> datarocket_oportunidades   (el trabajo que se abre, en el embudo indicado)
  -> datarocket_interacciones   (el mensaje concreto, colgado de esa oportunidad)
```

Todo bajo una sola transaccion: **o entran los tres o no entra ninguno**. No
queda un prospecto cargado cuya oportunidad fallo.

### Los tres campos van juntos

| Body trae…                          | Resultado                                      |
| ----------------------------------- | ---------------------------------------------- |
| `embudo` + `asunto` + `mensaje`     | Prospecto + oportunidad + interaccion.          |
| Ninguno de los tres                 | **Solo el prospecto** — el alta de siempre.     |
| Alguno pero no los tres             | `400`, diciendo cual falta.                     |

No hay descarte en silencio. Un `mensaje` sin `embudo` no tendria kanban donde
colgarse, y un `embudo` sin mensaje abriria una oportunidad vacia: ninguna de
las dos es lo que el cliente quiso, asi que se le avisa en vez de adivinar.

```json
{"ok":false,"error":"Para registrar la consulta hacen falta `embudo`, `asunto` y `mensaje`: falta `asunto`. Sin las tres claves el alta crea solo el prospecto."}
```

### Que aporta el embudo

Es el unico dato de ruteo que manda el cliente. De el salen los otros tres, que
**no se aceptan del body**:

| Campo de la oportunidad | De donde sale                                                        |
| ----------------------- | -------------------------------------------------------------------- |
| `embudo_id`             | El embudo que resolvio `embudo`.                                     |
| `proyecto_id`           | El `proyecto_id` **de ese embudo**.                                  |
| `etapa_id`              | La **primera etapa** del embudo (la de menor `orden`).               |

Aceptar el proyecto por separado permitiria una oportunidad cuyo proyecto no es
el del embudo en el que vive; y elegir la etapa desde afuera obligaria al
integrador a conocer el kanban. La entrada del pipeline es siempre la primera
etapa — `UNIQUE(embudo_id, orden)` hace que "primera" sea deterministico.

`sentido` queda en `E` (entrante) y la interaccion en `entrante`: la consulta la
inicia el prospecto, no nosotros.

### Alcanza con el nombre del embudo

`embudo` acepta el slug ya armado **o** el nombre tal como se ve en el panel. Se
le aplica la misma transformacion con la que se deriva el slug al dar de alta
(acentos plegados, minusculas, todo lo que no sea `[a-z0-9]` a guion, corte a
40), asi que los tres caen en la misma fila:

```json
{"embudo": "causam-clientes"}
{"embudo": "Causam Clientes"}
{"embudo": " CAUSAM_CLIENTES "}
```

Tambien se acepta `embudo_id` con el id directo, para el cliente que ya lo
resolvio contra [/v4/datarocket/embudos](embudos.md) y lo tiene cacheado.

### `canal` y `origen` son opcionales

Los defaults estan puestos para el caso que motiva todo esto — el formulario
web — pero el mismo alta sirve para una consulta que entra por otro lado:

| Clave    | Default | Valores                                                                       |
| -------- | ------- | ----------------------------------------------------------------------------- |
| `canal`  | `web`   | `correo`, `whatsapp`, `telegram`, `sms`, `web`, `telefono`, `presencial`      |
| `origen` | `Web`   | `Web`, `Correo`, `Facebook`, `Instagram`, `Linkedin`, `Google`, `Youtube`, `Tiktok`, `Referido`, `Lading` |

Salen del catalogo `estados` (`datarocket_interaccion_canal` y
`datarocket_oportunidad_origen`), asi que la lista de arriba es la de hoy y
puede crecer desde el panel. La comparacion es **insensible a mayusculas** y se
guarda la variante canonica del catalogo (`"instagram"` entra como `Instagram`,
`"WhatsApp"` como `whatsapp`) — una mayuscula de mas no tiene por que costar un
`400`. Un valor que no este en el catalogo si corta con `400`, con la lista de
validos en el mensaje.

### Si el prospecto ya existe, se reutiliza

Aca el `409` por correo o celular duplicado **no aplica**. Con bloque de
consulta el `POST` ya no significa "dar de alta un prospecto" sino "registrar
una consulta", y que quien la manda ya este en la base es lo normal: es un
cliente que vuelve. Se reutiliza su fila, la oportunidad se le cuelga ahi y la
respuesta trae `prospecto.creado: false` con `200` en vez de `201`.

**No se le pisa nada de lo que ya tenia cargado.** Los datos de un formulario
suelen ser mas pobres que los de la ficha (un alta previa con domicilio y cargo,
una consulta nueva con solo el nombre y el correo), asi que el body se usa para
identificar al prospecto, no para actualizarlo. Si hay que corregirle un campo,
eso es un [`PATCH`](#patch-v4datarocketprospectosidn--modificacion-parcial).

`lista_ids` y `etiqueta_ids` son la excepcion: **se suman** a las que ya tenia
—son informacion nueva ("ademas vino por la expo")— en vez de reemplazarlas como
hacen el `PUT` y el `PATCH`.

> **Sin bloque de consulta el `409` sigue vivo.** Un `POST` que solo trae datos
> del prospecto sigue siendo un alta a secas, y ahi el duplicado sigue siendo el
> error que el `409` previene. Ver
> [Unicidad de correo y celular](#unicidad-de-correo-y-celular).

Hay un caso que si corta con `409`: cuando el **correo matchea contra un
prospecto y el celular contra otro**. No hay forma de elegir a cual pertenece la
consulta sin adivinar, y adivinar mal la manda al legajo equivocado.

### Si ya hay una oportunidad abierta, se reutiliza

Antes de crear la oportunidad se busca una **abierta del mismo prospecto en el
mismo embudo**. Si existe, la consulta se cuelga de esa y la respuesta trae
`oportunidad.creada: false`. Es lo que evita que tres consultas del mismo
cliente dejen tres tarjetas duplicadas en el kanban.

- **Abierta** = etapa de `tipo='activa'`, o sin etapa asignada (un dato
  incompleto no es un cierre).
- **Cerrada** = etapa `ganada` o `perdida`. Ahi se abre una oportunidad
  **nueva**: el cliente al que ya le vendimos y vuelve a escribir es una venta
  nueva, no la vieja reabierta.
- Al reutilizar **no se mueve la etapa**: si alguien ya la avanzo a "Propuesta",
  una consulta nueva no tiene por que devolverla al principio del pipeline. Solo
  se toca `actualizado`, que es lo que ordena el kanban.
- La **interaccion siempre se crea**. El mensaje concreto nunca se pisa ni se
  colapsa: el historial de la oportunidad se lee de ahi.
- La interaccion **nace pendiente**: el alta no escribe `respondida`, porque una
  consulta que acaba de entrar no la contesto nadie. Se sella unicamente a mano
  desde el panel (Datarocket > Interacciones > Marcar respondida), que es lo que
  alimenta la metrica de demora de respuesta.

### La respuesta

`201` si se creo el prospecto, `200` si se reutilizo uno existente.

```json
{
  "ok": true,
  "data": {
    "id": 149311,
    "uuid": "14268d59-db2f-42a5-86da-0e5b9b709fb6",
    "registrado": "2026-08-18 12:20:51",
    "prospecto":  { "id": 149311, "uuid": "…", "registrado": "…", "creado": true },
    "oportunidad": {
      "id": 4958, "creada": true,
      "embudo_id": 4, "embudo_slug": "causam-clientes", "proyecto_id": 109,
      "etapa_id": 10, "etapa_nombre": "Nuevo"
    },
    "interaccion": { "id": 1090, "creada": true, "sentido": "entrante", "canal": "web" }
  }
}
```

`id` / `uuid` / `registrado` siguen en la raiz: son el contrato que ya consumen
los clientes del alta simple, y un formulario que empieza a mandar el bloque de
consulta no deberia tener que mover de donde los lee. `prospecto.id` es el
mismo valor.

Los bloques `oportunidad` e `interaccion` **no aparecen** en un alta sin
consulta — una clave ausente y una clave en `null` significan cosas distintas.

Los dos `creado` / `creada` son lo que distingue los cuatro escenarios sin tener
que releer nada:

| `prospecto.creado` | `oportunidad.creada` | Que paso                                    |
| ------------------ | -------------------- | ------------------------------------------- |
| `true`             | `true`               | Cliente nuevo, consulta nueva.              |
| `false`            | `true`               | Cliente conocido, consulta sobre algo nuevo.|
| `false`            | `false`              | Cliente conocido insistiendo sobre lo mismo.|
| `true`             | `false`              | No puede pasar (un prospecto recien creado no tiene oportunidades). |

### Errores

| Codigo | Cuando                                                                              |
| ------ | ----------------------------------------------------------------------------------- |
| 400    | El bloque vino incompleto (falta `embudo`, `asunto` o `mensaje`).                   |
| 400    | El embudo no existe. **No se crea al vuelo** — ver abajo.                            |
| 400    | `canal` u `origen` fuera del catalogo (el mensaje lista los validos).               |
| 409    | El embudo existe pero **no tiene etapas cargadas**: no hay donde ubicar la oportunidad. |
| 409    | El slug del embudo existe en mas de un proyecto (mandar tambien `proyecto_id`).      |
| 409    | El `correo` y el `celular` pertenecen a prospectos **distintos**.                    |

Mas los del alta simple (`tipo` obligatorio, identidad, correo invalido, FK de
ubicacion).

Un embudo que no existe es `400` y no se crea solo. No hay un `?resolver=1` como
el de [etiquetas](etiquetas.md), y es a proposito: una etiqueta nueva es
inofensiva, un embudo vacio es un pipeline roto. Crearlo implica ademas cargarle
las etapas y elegirle el proyecto — curaduria, no integracion. Se hace en el
panel (Sistemas > Datarocket > Embudos). Ver
[Por que es de solo lectura](embudos.md#por-que-es-de-solo-lectura).

### Ejemplo: el formulario web completo

```bash
curl -X POST https://api.databox.net.ar/v4/datarocket/prospectos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "tipo":           "persona",
    "persona_nombre": "Marta Web",
    "correo":         "marta@example.com",
    "celular":        "+54 9 11 4444-7788",
    "embudo":         "Causam Clientes",
    "asunto":         "Consulta por estudio de suelos",
    "mensaje":        "Hola, necesito presupuesto para un estudio de suelos en Quilmes."
  }'
```

Eso es todo lo que el formulario tiene que saber: los datos de quien escribe, el
embudo donde cae y el mensaje. El `proyecto_id`, la `etapa_id`, los `sentido` y
las fechas los pone el endpoint.

### Consultar el catalogo de embudos aparte

Para poblar un combo, para validar la configuracion en el arranque de la
integracion o para resolver el `embudo_id` una sola vez y cachearlo, esta
**[/v4/datarocket/embudos](embudos.md)** — misma apikey, solo lectura:

```bash
# Todos los embudos, alfabetico por slug.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos"
# -> {"ok":true,"data":{"total":7,"items":[{"id":4,"slug":"causam-clientes",...},...]}}

# Uno solo, con sus etapas.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/embudos?slug=causam-clientes&con_etapas=1"
```

El `id` es estable dentro de una base, asi que se puede cachear — lo que **no**
vale es hardcodearlo: el `id` de `causam-clientes` en dev no tiene por que ser el
mismo que en produccion. Lo que se guarda en la configuracion es el slug (o
directamente el nombre, que este endpoint tambien acepta).

### El slug es unico por proyecto, no global

El `UNIQUE` de `datarocket_embudos` es (`proyecto_id`, `slug`): dos proyectos
distintos pueden tener cada uno su `captacion-general`. Si el `embudo` que se
manda matchea en mas de uno, el alta corta con `409` y la lista de candidatos en
vez de elegir "el primero" —que le abriria la oportunidad en el proyecto
equivocado sin que nadie se entere—, y se desambigua agregando `proyecto_id` al
body:

```json
{ "embudo": "captacion-general", "proyecto_id": 109, "asunto": "…", "mensaje": "…" }
```

Al 2026-08-18 no hay ningun slug repetido en la base, o sea que en la practica el
`409` no aparece; alcanza con que alguien cree el mismo slug en un segundo
proyecto desde el panel para que empiece a aparecer.

### El embudo no es una columna del prospecto

`datarocket_prospectos` **no tiene `embudo_id` ni `etapa_id`** — el `embudo` del
body es un dato de ruteo del alta, no un campo que se guarde en la ficha. Esas
dos columnas viven en `datarocket_oportunidades`.

La division es a proposito: el prospecto es *quien* es (la persona o la empresa,
con su correo y su celular unicos), el embudo es *en que estamos trabajando con
el*. El mismo prospecto puede tener a la vez una oportunidad en
`causam-clientes` y otra en `causam-estudios`, cada una en su etapa, asi que "el
embudo del prospecto" no seria un dato unico.

Por eso `embudo` no aparece en el [modelo de datos](#modelo-de-datos), no se
devuelve en el `GET` y no se puede filtrar por el en el listado. Y por eso
tampoco lo aceptan el `PUT` ni el `PATCH`: mover una oportunidad de embudo es
una operacion sobre la oportunidad, y vive en el ABM del panel.

| Dato                        | Vive en                     | Se resuelve con                    |
| --------------------------- | --------------------------- | ---------------------------------- |
| `pais_id` / `provincia_id` / `localidad_id` | `datarocket_prospectos` | [/v4/databox/ubicaciones](../databox/ubicaciones.md) |
| `etiqueta_ids`              | puente del prospecto        | [/v4/datarocket/etiquetas](etiquetas.md) |
| `embudo_id` / `etapa_id`    | `datarocket_oportunidades`  | [/v4/datarocket/embudos](embudos.md) |

> **El ABM de oportunidades todavia no esta expuesto en `v4`.** Este alta las
> crea, pero para listarlas, moverlas de etapa o cerrarlas hay que ir al panel
> cloud ([cloud/api/datarocket_oportunidades.php](../../../cloud/api/datarocket_oportunidades.php)):
> `api/v4/datarocket/oportunidades.php` esta vacio al 2026-08-18.

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
| `etiqueta_ids`     | int[]      | Etiquetas en `datarocket_prospectos_etiquetas`. Ids del catalogo — se obtienen de [/v4/datarocket/etiquetas](etiquetas.md). |
| `etiqueta_nombres` | string[]   | Mismos indices que `etiqueta_ids`. **Solo lectura**: en el body se ignora.      |

En `POST` se aceptan `lista_ids` y `etiqueta_ids` como int[]. En `PUT` y en
`PATCH` son opcionales: si no vienen no se toca la puente; si vienen (aun `[]`)
se reemplazan por completo.

> **¿De donde saco los `etiqueta_ids`?** De
> [/v4/datarocket/etiquetas](etiquetas.md), que ademas **crea la etiqueta si no
> existe** con `POST ?resolver=1` — ver
> [Seleccionar las etiquetas](#seleccionar-las-etiquetas).

> **No hay `embudo_id` ni `etapa_id` en esta tabla** — son columnas de
> `datarocket_oportunidades`. El `embudo` que acepta el `POST` es un dato de
> ruteo del alta, no un campo de la ficha: no se devuelve en el `GET` ni filtra
> el listado. Ver
> [El embudo no es una columna del prospecto](#el-embudo-no-es-una-columna-del-prospecto).

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

El alta tiene **dos modos**, y los distingue el body:

| Modo                 | El body trae…                    | Crea                                              |
| -------------------- | -------------------------------- | ------------------------------------------------- |
| Alta simple          | Solo datos del prospecto         | El prospecto.                                     |
| Alta con **consulta**| Ademas `embudo` + `asunto` + `mensaje` | Prospecto + oportunidad + interaccion.      |

El segundo es el del formulario web y esta documentado entero en
[Registrar una consulta](#registrar-una-consulta-embudo-asunto-y-mensaje) —
incluido que ahi el prospecto duplicado **se reutiliza** en vez de dar `409`.
Lo que sigue vale para los dos.

### Body

Cualquier subconjunto de las columnas del modelo. **Obligatorios:**

- `tipo`: `persona` o `empresa`.
- `persona_nombre` si `tipo='persona'`, `empresa_nombre` si `tipo='empresa'`.

Claves del bloque de consulta (las tres juntas o ninguna):

| Clave         | Tipo   | Notas                                                                                  |
| ------------- | ------ | -------------------------------------------------------------------------------------- |
| `embudo`      | string | Nombre o slug. Se slugifica antes de buscar. Inexistente -> `400`.                      |
| `asunto`      | string | Va a la oportunidad **y** a la interaccion. Se trunca a 500.                             |
| `mensaje`     | string | Texto de la interaccion (`mediumtext`).                                                  |
| `embudo_id`   | int    | Alternativa a `embudo` si ya se resolvio el id.                                          |
| `proyecto_id` | int    | **Solo** para desambiguar un slug repetido en dos proyectos. El proyecto de la oportunidad sale del embudo. |
| `canal`       | string | Canal de la interaccion. Default `web`.                                                  |
| `origen`      | string | Origen de la oportunidad. Default `Web`.                                                 |

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
- **`etiqueta_ids`**: son **ids del catalogo, no nombres**. Se obtienen de
  [/v4/datarocket/etiquetas](etiquetas.md), que tambien **crea la etiqueta si no
  existe** (`POST ?resolver=1`) — ver
  [Seleccionar las etiquetas](#seleccionar-las-etiquetas). A diferencia de las
  ubicaciones, un id inexistente **no** corta con `400`: se descarta en silencio.

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

Con bloque de consulta la respuesta suma `prospecto`, `oportunidad` e
`interaccion`, y puede ser `200` (prospecto reutilizado) en vez de `201` — ver
[La respuesta](#la-respuesta).

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

Los tres `409` de duplicado **no se aplican al alta con consulta**: ahi el
prospecto existente se reutiliza. Los errores propios de ese modo (bloque
incompleto, embudo inexistente, embudo sin etapas, canal u origen invalidos)
estan en [Registrar una consulta](#registrar-una-consulta-embudo-asunto-y-mensaje).

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

**Si el formulario es una consulta** (tiene un campo "mensaje" y cae en un
pipeline), no hay flujo: es un solo `POST` con `embudo` + `asunto` + `mensaje`.
El duplicado no hace falta preguntarlo porque no es un error — el prospecto se
reutiliza. Ver
[Registrar una consulta](#registrar-una-consulta-embudo-asunto-y-mensaje).

**Si es un alta a secas** (un formulario de suscripcion, un importador):

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
- Catalogo de etiquetas para resolver (o crear) los `etiqueta_ids`: [/v4/datarocket/etiquetas](etiquetas.md).
- Catalogo de embudos, para resolver el `embudo` del alta con consulta o para poblar un combo: [/v4/datarocket/embudos](embudos.md).
- Tablas que escribe el alta con consulta: `datarocket_oportunidades` y `datarocket_interacciones` — schema en [db/schema.sql](../../../db/schema.sql); las etapas salen de `datarocket_etapas` (migracion `20260812_0300`).
- ABM de oportunidades del panel (mover de etapa, cerrar, listar): [cloud/api/datarocket_oportunidades.php](../../../cloud/api/datarocket_oportunidades.php).
- Catalogos de `canal` y `origen`: tabla `estados`, campos `datarocket_interaccion_canal` y `datarocket_oportunidad_origen` (panel > Herramientas > Editor de estados).
- Indices de la busqueda de duplicados: migracion `20260818_1300_datarocket_prospectos_indices_correo_celular.sql`.
- Helper de auth por Bearer: [cloud/api/lib/apikey_auth.php](../../../cloud/api/lib/apikey_auth.php) (el v4 rueda la logica inline para no arrastrar dependencias, pero el shape es identico).
- Microservicios hermanos del mismo `v4/`: [/v4/evolution/mensajes](../evolution/mensajes.md), [/v4/datacount/comprobantes](../datacount/comprobantes.md).
