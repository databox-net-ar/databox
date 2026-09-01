# `/v4/datarocket/suscripcion`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/suscripcion.md>

Microservicio del **CRM Datarocket** que hace en **una sola llamada** lo que hoy
lleva tres: registrar un prospecto, suscribirlo a una lista **por su slug** y
aplicarle etiquetas **por su nombre**. Un unico archivo `.php`
([suscripcion.php](suscripcion.php)) que sirve todo el recurso — sin framework ni
router aparte.

Es el endpoint que tiene que llamar un **formulario de suscripcion**, una landing
con newsletter o un importador que trae gente con su lista y sus etiquetas ya
decididas.

```bash
curl -X POST "https://api.databox.net.ar/v4/datarocket/suscripcion" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Juan Pérez","correo":"juan@gmail.com","etiquetas":"expo"}'
```

| Quiero…                                                    | Uso                                                          |
| ---------------------------------------------------------- | ------------------------------------------------------------ |
| **Suscribir a alguien a una lista, sepa o no si ya existe** | `POST /v4/datarocket/suscripcion`                             |
| …y ademas etiquetarlo                                       | `"etiquetas": ["expo","vip"]`                                 |
| …y que las etiquetas nuevas se creen solas                  | `POST /v4/datarocket/suscripcion?crear_etiquetas=1`           |
| Cargar la ficha **completa** (domicilio, ubicacion, redes)  | [`/v4/datarocket/prospectos`](prospectos.md)                  |
| Ver a que listas pertenece alguien                          | `GET /v4/datarocket/prospectos?id=N` → `lista_ids`            |
| Ver el catalogo de listas                                   | [`GET /v4/datarocket/listas`](listas.md)                      |

**Solo acepta `POST`.** Cualquier otro metodo devuelve `405`. La baja no se hace
por API — ver [Lo que este endpoint no hace](#lo-que-este-endpoint-no-hace).

**Es idempotente.** Repetir el mismo POST no duplica la ficha, ni el alta en el
historial, ni la etiqueta. Ver [Idempotencia](#idempotencia).

**Nunca contesta `409` por duplicado.** Un suscriptor que vuelve reutiliza su
ficha. Ver [A que prospecto se le cuelga](#a-que-prospecto-se-le-cuelga-la-suscripcion).

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol.

---

## Para que existe si ya esta `/v4/datarocket/prospectos`

`POST /v4/datarocket/prospectos` ya acepta `lista_ids` y `etiqueta_ids`, asi que
"dar de alta y suscribir" parece resuelto. No lo esta, y por cuatro motivos que
juntos hacen que un formulario de suscripcion **no pueda** usarlo:

| | `POST /v4/datarocket/prospectos` | `POST /v4/datarocket/suscripcion` |
| --- | --- | --- |
| **Como se indica la lista** | `lista_ids: [136]` — solo por id | `lista: "vigicom-usuarios"` — por slug |
| **Como se indican las etiquetas** | `etiqueta_ids: [5]` — solo por id | `etiquetas: ["expo"]` — por nombre o slug |
| **Suscriptor que ya estaba** | **`409`** por `correo` repetido | Reutiliza su ficha, `200` |
| **Historial + `suscriptos`** | No escribe historial ni recalcula | Escribe `datarocket_listas_altas` y recalcula |
| **Efecto sobre otras listas** | `lista_ids` es **full replace**: borra las demas | Aditivo: no toca ninguna otra |
| **Alcance de la ficha** | La ficha entera + PUT / PATCH / DELETE | El subconjunto de un formulario, solo alta |

Los cuatro en detalle:

1. **Ahi las listas y las etiquetas van por id.** Lo que un integrador tiene
   escrito en su configuracion es `vigicom-usuarios`, no el `136` que le toco a
   esa lista en esta base. Hoy necesita dos llamadas previas
   (`/v4/datarocket/listas?slug=…` y `/v4/datarocket/etiquetas?slug=…`) solo para
   traducir. Aca el slug viaja en el mismo POST.

2. **Ahi un suscriptor que vuelve se come un `409`.** El alta simple de
   prospectos rechaza el `correo` repetido, que es exactamente lo que hace
   alguien que ya estaba en la base y se suscribe a otra lista — o a la misma dos
   veces desde dos landings distintas. Un formulario de suscripcion no puede
   fallar por eso.

3. **Ahi la suscripcion no pasa por la puerta unica.** `drPrSyncListas()` de
   `prospectos.php` escribe `datarocket_prospectos_listas` a mano: no deja
   historial en `datarocket_listas_altas` y no recalcula
   `datarocket_listas.suscriptos`, que queda mintiendo hasta el proximo recalculo
   manual desde el ABM. Este endpoint delega en `drListaSuscribir()`, que hace
   las dos cosas en la misma transaccion. Ver [Que escribe](#que-escribe).

4. **Ahi `lista_ids` es un full replace.** `drPrSyncListas()` borra **todas** las
   suscripciones del prospecto y deja solo las del body. Un formulario que
   suscribe a la lista A le estaria dando de baja de las B y C sin que nadie se
   entere.

El reparto queda asi: **la ficha se carga y se corrige por
[`/v4/datarocket/prospectos`](prospectos.md); suscribir se hace por aca.**

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

**Quien llamo queda registrado en el historial.** `datarocket_listas_altas.origen`
es fijo (`v4/datarocket.suscripcion` — identifica la puerta, no al cliente) y el
nombre de la aplicacion va siempre en `detalle` como `app: <nombre>`. Es lo
primero que se quiere saber mirando el historial seis meses despues, y la tabla
no tiene columna para el apikey.

Todo error (incluidos los `401`) queda registrado en la tabla `sucesos`, que es
lo que lee el **Visor de sucesos** del panel: los `4xx` como `alerta` y los `5xx`
como `error`, con origen `v4/datarocket.suscripcion`. Las respuestas exitosas no
se registran ahi — el rastro de una suscripcion exitosa es
`datarocket_listas_altas`, que dice mucho mas. La unica excepcion es el alta de
una **etiqueta nueva**, que deja un `info` porque toca un catalogo compartido.

---

## Contrato de respuesta

Todas las respuestas siguen el shape unificado del stack:

```json
{ "ok": true,  "data": <payload> }
{ "ok": false, "error": "<mensaje>" }
```

Body-in y body-out son JSON `utf-8` (`Content-Type: application/json`).

---

## `POST /v4/datarocket/suscripcion`

### Query params

| Param             | Tipo | Default | Descripcion                                                        |
| ----------------- | ---- | ------- | ------------------------------------------------------------------ |
| `crear_etiquetas` | flag | apagado | Crea en el catalogo las etiquetas que no existan, en vez de `400`. |

Como todos los flags del arbol v4, acepta cualquier valor no vacio salvo los
negativos explicitos: `1`, `true` y `si` prenden; `0`, `false`, `no` y el vacio
no.

### Body

**Suscripcion**

| Campo         | Tipo   | Obligatorio | Descripcion                                                                     |
| ------------- | ------ | ----------- | ------------------------------------------------------------------------------- |
| `lista`       | string | **si** \*   | Slug de la lista. Acepta tambien el texto del nombre sin formatear.             |
| `lista_id`    | int    | \*          | Alternativa a `lista` si el cliente ya resolvio el id. Gana si vienen los dos.  |
| `proyecto_id` | int    | no          | Desambigua un slug que exista en mas de un proyecto (ver abajo).                |
| `motivo`      | string | no          | Valor de `datarocket_lista_alta_motivo`. Default `solicitada`.                  |
| `detalle`     | string | no          | Nota libre para el historial. Se guarda detras de `app: <nombre>`. Max 255.     |
| `etiquetas`   | array \| string | no | Nombres o slugs. Acepta `["expo","vip"]` o el CSV `"expo,vip"`. Max 20.   |

\* Hace falta `lista` **o** `lista_id`.

**Prospecto**

| Campo                | Tipo   | Obligatorio | Descripcion                                                            |
| -------------------- | ------ | ----------- | ---------------------------------------------------------------------- |
| `correo`             | string | **si** \*\* | Se normaliza a minuscula validada.                                     |
| `celular`            | string | \*\*        | Se normaliza a 10 digitos argentinos.                                  |
| `nombre`             | string | \*\*\*      | Alias: cae en `persona_nombre` o `empresa_nombre` segun el `tipo`.     |
| `tipo`               | string | no          | `persona` \| `empresa`. Default `persona`.                             |
| `persona_nombre`     | string | \*\*\*      | Gana sobre `nombre` si vienen los dos.                                 |
| `empresa_nombre`     | string | \*\*\*      | Idem, para `tipo: "empresa"`.                                          |
| `empresa_cargo`      | string | no          | |
| `persona_genero`     | string | no          | 1 caracter. |
| `persona_nacimiento` | string | no          | |
| `telefono`           | string | no          | Se normaliza a 10 digitos argentinos.                                  |
| `whatsapp`           | string | no          | Idem. **No** se deriva de `celular`: hay que mandarlo.                 |
| `web`                | string | no          | Se guarda como host + path **sin esquema**.                            |
| `ciudad`             | string | no          | |
| `comentarios`        | string | no          | Max 500. |
| `extraccion_url`     | string | no          | De donde salio. Se guarda **tal cual**, con esquema y mayusculas.      |
| `extraccion_autor`   | string | no          | Quien lo trajo (una persona o un bot). Tampoco se normaliza.           |

\*\* Hace falta `correo` **o** `celular` — ver [Al menos correo o celular](#al-menos-correo-o-celular).
\*\*\* El nombre hace falta **solo si hay que crear la ficha** — ver [esa seccion](#el-nombre-hace-falta-solo-si-hay-que-crear-la-ficha).

Las claves desconocidas se ignoran, igual que en el resto del arbol v4.

> **Que campos NO acepta.** `domicilio`, `localidad_id` / `provincia_id` /
> `pais_id`, `empresa_rubro`, `empresa_actividad`, `persona_dni`, `facebook`,
> `instagram`, `tiktok` y `ubicacion` **no entran por aca**: se cargan y se
> corrigen por [`/v4/datarocket/prospectos`](prospectos.md), que valida las FK de
> los catalogos de ubicacion y expone el `PATCH` parcial.
>
> El recorte es deliberado. Aceptar aca el payload entero convertia este endpoint
> en una segunda implementacion del alta de prospectos, con dos juegos de
> validaciones que se separan a la primera modificacion. Lo que **si** comparte
> con esa alta son las reglas de campo (`prospectos_normalizar.php`): telefonos a
> 10 digitos, correo a minuscula validada, `web` sin esquema.

---

## A que prospecto se le cuelga la suscripcion

Mismo criterio que el alta con consulta de
[`/v4/datarocket/prospectos`](prospectos.md): el **`correo` es el factor
principal** y el **`celular` el de respaldo**, y el respaldo solo entra cuando la
llamada no trae correo.

| La llamada trae… | y ese dato… | Resultado |
| --- | --- | --- |
| `correo` | ya esta en la base | Se usa **esa ficha** (la mas reciente si hay varias) |
| `correo` | no esta | **Prospecto nuevo** |
| solo `celular` | ya esta en la base | Se usa **esa ficha** |
| solo `celular` | no esta | **Prospecto nuevo** |

Que el correo **no** caiga al respaldo cuando no matchea es a proposito: si la
llamada trae correo, ese correo es la identidad que declara, y un celular
compartido (el conmutador de una empresa, un telefono familiar) no debe mandar la
suscripcion al legajo de otra persona.

**Nunca se contesta `409` por duplicado.** Registrar la suscripcion es lo que no
se puede perder: una suscripcion rechazada es alguien que dejo su correo, cree
que se suscribio, y no le va a llegar nada.

### Sobre una ficha que ya existia no se pisa nada

Los datos de un formulario suelen ser **mas pobres** que los que la ficha ya
tiene cargados: un alta previa con cargo y domicilio contra una suscripcion nueva
con solo el correo. Pisarlos seria degradar la ficha cada vez que alguien se
suscribe.

Lo unico que se escribe son los **huecos de contacto** — `correo`, `celular`,
`whatsapp` y `telefono` que esten vacios (`NULL` o `''`) y que esta llamada
traiga. Un valor ya cargado no se toca ni se compara: si difiere del que vino, es
una **correccion**, y eso es un `PATCH /v4/datarocket/prospectos` deliberado, no
un efecto colateral de suscribirse a una lista.

Los campos que se llenaron vuelven en `prospecto.completado`, asi que el cliente
ve que su POST enriquecio la ficha sin tener que releerla y compararla:

```json
"prospecto": { "id": 149365, "creado": false, "completado": ["whatsapp","telefono"] }
```

Se limita a esos cuatro y no a todo el payload a proposito: son los unicos donde
"vacio" es inequivocamente un hueco a llenar. Con `ciudad` o `empresa_cargo` no
es asi — un dato viejo puede estar vacio porque nadie lo cargo o porque no
aplica.

---

## Al menos `correo` o `celular`

Sin una via de contacto no se da de alta nada: ni la ficha, ni la suscripcion.

```json
{"ok":false,"error":"Para suscribir hace falta al menos `correo` o `celular`: una lista es lo que consume un envio masivo, y un suscriptor sin via de contacto no puede recibir nada ni volver a identificarse en una llamada posterior."}
```

Una lista es lo que consume un envio masivo. Un suscriptor sin destino no puede
recibir nada, no se puede reencontrar por ningun campo — cada llamada nueva
abriria otra ficha — y ademas entra al denormalizado `suscriptos` inflando un
numero que despues nadie puede explicar. Es basura, no un lead.

**Esta regla es de este endpoint.** El alta a secas de
[`/v4/datarocket/prospectos`](prospectos.md) sigue aceptando fichas sin contacto
—un padron, un scraping— y esta bien que asi sea: 9.679 de las 43.244 filas no
tienen correo y son legitimas (dev al 2026-08-31). La diferencia es que ahi no se
suscribe a nadie.

Se mira el valor **normalizado**: un `correo` de `"no informado"` o un `celular`
sin ningun digito cuentan como ausentes. Un correo con algo escrito del que no se
pueda extraer ninguna direccion valida corta con `400` aparte.

---

## El nombre hace falta solo si hay que crear la ficha

`datarocket_prospectos` tiene una **invariante de identidad** documentada en el
schema: el campo de nombre del lado que marca `tipo` es obligatorio, y `nombre`
se **deriva** de el.

```
tipo = "persona"  ->  persona_nombre obligatorio  ->  nombre = persona_nombre
tipo = "empresa"  ->  empresa_nombre obligatorio  ->  nombre = empresa_nombre
```

El schema es explicito en que la sostiene la capa PHP y en que *"cualquier tercer
escritor que se agregue tiene que replicarla o la tabla se vuelve a ensuciar"*.
Este endpoint es ese tercer escritor —los otros dos son el ABM cloud y
`/v4/datarocket/prospectos`— y por eso replica la validacion. Fue justamente un
importador sin ella el que dejo 989 filas con `tipo='persona'` y `persona_nombre`
NULL (migracion `20260817_2100`).

`nombre` es un **alias**: cae en el campo que corresponda al `tipo`, asi que un
formulario puede mandar un solo campo sin saber si va a `persona_nombre` o a
`empresa_nombre`. Y `tipo` no es obligatorio — por default es `persona`, que es
lo que se suscribe a una lista salvo excepcion.

> **Ojo con la consecuencia practica.** El nombre es obligatorio para **crear**,
> y se ignora cuando la ficha ya existia. O sea que **el mismo payload sin nombre
> da `400` para alguien nuevo y `200` para alguien que ya estaba**:
>
> ```json
> {"ok":false,"error":"Falta el nombre: no hay ninguna ficha cargada con ese contacto, asi que hay que crearla, y un prospecto de tipo `persona` necesita `persona_nombre` (o `nombre`, que cae ahi solo). Solo hace falta cuando el prospecto es nuevo — si ya estaba en la base se reutiliza su ficha y no se pisa nada —, asi que conviene mandarlo siempre."}
> ```
>
> **La recomendacion para un integrador es mandar siempre el nombre.** Un
> formulario que solo pide el correo va a funcionar con los suscriptores que ya
> estan en la base y a fallar con los nuevos.
>
> Las dos alternativas eran peores: aceptar la ficha sin nombre rompia la
> invariante del schema, y rellenar `nombre` con el correo inventaba un dato que
> despues aparece tal cual en el saludo de una plantilla (*"Hola
> juan@gmail.com"*).

---

## La lista se resuelve por slug

`lista` acepta el slug ya armado o **el texto del nombre sin formatear**: las dos
formas pasan por la misma slugificacion con la que se derivo el slug al crear la
lista, asi que caen en la misma fila.

```
"vigicom-usuarios"    ->  vigicom-usuarios
"Vigicom Usuarios"    ->  vigicom-usuarios
"  VIGICOM USUARIOS " ->  vigicom-usuarios
"Reactor Prospectos Fríos"  ->  reactor-prospectos-frios
```

El plegado saca acentos precompuestos **y** marcas combinantes (texto en forma
NFD, lo que mandan varios teclados de macOS / iOS). Sin eso la tilde suelta
quedaria como un guion en el medio de la palabra (`fri-os`).

Una lista que no existe corta con `400` **antes de escribir nada**:

```json
{"ok":false,"error":"La lista `newsletter` no existe. El catalogo se consulta en `GET /v4/datarocket/listas`; las listas se crean desde el ABM del panel cloud.","consulta":{"lista":"newsletter"}}
```

No hay un `?crear_listas=1` equivalente al de etiquetas, y no es una omision: una
etiqueta nueva es inofensiva, una lista nueva **define una audiencia de envio** y
arrastra la decision de a que proyecto pertenece. Eso es curaduria del CRM y vive
en el ABM del panel.

### El slug es unico por proyecto, no global

El `UNIQUE` de `datarocket_listas` es (`proyecto_id`, `slug`), asi que dos
proyectos pueden tener cada uno su `clientes`. Y como `proyecto_id` es
**nullable** y MySQL/MariaDB tratan cada `NULL` como distinto dentro de un indice
unico, el `UNIQUE` ni siquiera restringe a las listas sin proyecto: dos huerfanas
pueden compartir slug.

Devolver "la primera" seria suscribir a alguien a la lista de otro proyecto sin
que nadie se entere — y con listas eso es la audiencia equivocada de un envio
masivo. Se contesta `409` con los candidatos:

```json
{
  "ok": false,
  "error": "El slug de lista `reactor-usuarios` existe en mas de un proyecto. Agrega `proyecto_id` al body para desambiguar.",
  "consulta": { "lista": "reactor-usuarios" },
  "listas": [
    { "id": 900010, "proyecto_id": 102, "slug": "reactor-usuarios", "nombre": "Reactor Usuarios (otro)" },
    { "id": 135,    "proyecto_id": 104, "slug": "reactor-usuarios", "nombre": "Reactor Usuarios" }
  ]
}
```

Se resuelve agregando `"proyecto_id": 104` al body. Si las coincidencias son
**todas huerfanas**, no hay valor de `proyecto_id` que las separe y la unica
salida es `lista_id`; el error lo dice explicito segun el caso, en vez de
recomendar algo que no puede funcionar.

Hoy (2026-08-31) las 29 listas tienen proyecto y no hay ningun slug repetido, o
sea que en la practica el `409` no aparece. El endpoint no se apoya en eso porque
nada lo garantiza.

### Una lista por llamada

`lista` es **singular**. Para suscribir a varias se llama varias veces, y es
seguro: la operacion es [idempotente](#idempotencia), asi que la segunda llamada
no duplica la ficha ni vuelve a contar el alta.

El motivo de no aceptar un array es el contrato de error. Con varias listas en el
mismo body habria que decidir que pasa cuando una resuelve y otra no: **fallar
entera** es negarle al cliente las que si estaban, y **aplicar las buenas
devolviendo un error parcial** es una respuesta que nadie lee bien. Con una lista
por llamada la respuesta es si o no, y el cliente decide que hacer con cada una.

---

## Etiquetas

`etiquetas` acepta un array (`["expo","vip"]`) o el CSV `"expo,vip"` — que es lo
que sale natural de un campo oculto de formulario. Maximo 20 por llamada.

La resolucion es **por slug primero y por nombre despues**. Las dos columnas son
`UNIQUE` globales (el catalogo no tiene `proyecto_id`), asi que ninguna de las
dos busquedas puede ser ambigua. El slug va primero porque es la referencia
estable: `nombre` es texto libre y se edita desde el ABM (`expo` → `Expo 2027`),
y ahi el slug no se mueve. El fallback por nombre cubre el caso inverso — una
etiqueta cuyo slug quedo con sufijo (`santa-fe-2`) sigue encontrandose por su
nombre exacto.

No distingue mayusculas, acentos ni espacios: `Expo`, `EXPO`, `expó` y
`  expo  ` caen todos en la misma etiqueta.

### Las que no existen cortan con `400`

```json
{"ok":false,"error":"No existe la etiqueta `zzz-test-nueva`. Nunca se descartan en silencio: o las creas antes (`POST /v4/datarocket/etiquetas?resolver=1`), o repetis esta llamada con `?crear_etiquetas=1` para que se creen solas. El catalogo se consulta en `GET /v4/datarocket/etiquetas`.","faltantes":["zzz-test-nueva"]}
```

Descartarla en silencio seria lo peor de los dos mundos: el prospecto entra sin
etiquetar y el cliente se va con un `200` creyendo que quedo etiquetado. Un typo
(`vipp`) no se descubriria nunca.

Crearla sola tampoco puede ser el default: `datarocket_etiquetas` es un catalogo
**unico compartido por todo Datarocket** (30 filas al 2026-08-31), y una
integracion con un typo lo ensucia para todos.

### `?crear_etiquetas=1` las crea

Opt-in explicito, el mismo criterio con el que
[`/v4/datarocket/etiquetas`](etiquetas.md) separa `POST` de `POST ?resolver=1`.
El nombre se guarda **plegado** (minusculas, espacios colapsados, diacriticos
combinantes fuera) y el slug se deriva de el, exactamente igual que en el alta
del catalogo — si una puerta guardara el crudo y la otra el plegado, el mismo
texto terminaria creando dos filas que la collation considera la misma.

Cada etiqueta creada deja un `info` en `sucesos` con el nombre de la app:

```
Alta etiqueta #45 — "zzz test nueva" (app: Postman)
```

### Las etiquetas son aditivas

Nunca se borran las que el prospecto ya tenia. La variante destructiva
(`etiqueta_ids` como full replace) vive en
[`/v4/datarocket/prospectos`](prospectos.md) y no tiene sentido aca: suscribirse
a una lista no es motivo para perder las etiquetas que le puso un operador.

`datarocket_etiquetas.fecha_uso` se estampa sobre **todas** las pedidas, no solo
sobre las nuevas: aplicar una etiqueta que el prospecto ya tenia sigue siendo
usarla, y la columna es *"ultima vez que se uso"*, no *"ultima vez que se
agrego"*.

---

## Que escribe

**La suscripcion no se escribe en este endpoint.** Se delega en
`drListaSuscribir()`, la unica puerta de entrada y salida de las listas
([cloud/api/lib/datarocket_listas_suscripciones.php](../../../cloud/api/lib/datarocket_listas_suscripciones.php)).
Esa funcion:

1. Registra el historial en `datarocket_listas_altas` **antes** de tocar la
   puente — despues del cambio ya no hay de donde sacar quien estaba y con que
   dato.
2. Denormaliza el `destino` del momento, asi que si manana se corrige el correo
   el historial sigue diciendo con que dato entro.
3. Recalcula `datarocket_listas.suscriptos` en la misma transaccion.
4. Solo registra lo que **cambia de verdad**: suscribir a quien ya estaba no es
   un alta.

Lo unico que aporta este endpoint es el contexto: `motivo` (default
`solicitada`), `origen` = `v4/datarocket.suscripcion`, `usuario_id` = `NULL` (lo
pide una integracion, no un operador) y el `detalle` con la app.

Una fila real del historial:

| id | lista_id | prospecto_id | destino | motivo | detalle | origen | usuario_id |
| -- | -------- | ------------ | ------- | ------ | ------- | ------ | ---------- |
| 65547 | 135 | 149365 | `test@gmail.com` | `manual` | `app: Postman — landing de prueba` | `v4/datarocket.suscripcion` | `NULL` |

**Todo el POST corre en una transaccion**: prospecto + etiquetas + suscripcion
entran juntos o no entra ninguno. Sin eso un fallo a mitad de camino dejaria una
ficha creada que nadie pidio y que ninguna lista referencia.

Y todo lo que puede fallar por culpa del cliente —lista inexistente, slug
ambiguo, motivo fuera del catalogo, correo impresentable, etiqueta desconocida—
se valida **antes** de abrir la transaccion.

### Los valores de `motivo`

Salen del catalogo `datarocket_lista_alta_motivo` de la tabla `estados`:

| Valor          | Texto                     | Cuando usarlo                                              |
| -------------- | ------------------------- | ---------------------------------------------------------- |
| `solicitada`   | Solicitada                | **Default.** La persona dejo sus datos en un formulario.   |
| `manual`       | Alta manual               | Un operador o un importador la suscribio.                  |
| `preexistente` | Suscripcion preexistente  | Solo para backfills. No lo mandes desde una integracion.    |

Se resuelve case-insensitive (`"Manual"` → `manual`) y un valor fuera del
catalogo corta con `400` listando los validos.

---

## Idempotencia

Repetir el mismo POST no duplica nada:

* la ficha se **reutiliza** (`prospecto.creado: false`),
* la etiqueta ya aplicada no se vuelve a insertar (`etiquetas[].aplicada: false`),
* `drListaSuscribir()` devuelve `0` y **no escribe historial**
  (`suscripcion.nueva: false`).

La respuesta lo dice campo por campo, asi que el cliente puede distinguir *"lo
hice"* de *"ya estaba"* sin adivinar.

El codigo HTTP sigue el mismo criterio que el alta con consulta de
`/v4/datarocket/prospectos`:

| Codigo | Significa |
| ------ | --------- |
| `201`  | Se **creo** la ficha del prospecto. |
| `200`  | Se **reutilizo** una ficha que ya existia. |

El estado de la suscripcion **no** lo mueve — para eso esta `suscripcion.nueva`.
Un suscriptor que ya estaba en la base y entra a una lista nueva devuelve `200`
con `"nueva": true`.

---

## Respuesta

### `201` — prospecto nuevo

```json
{
  "ok": true,
  "data": {
    "prospecto": {
      "id": 149365,
      "uuid": "d4eafd71-f679-4f59-9da3-fef4a465b32d",
      "nombre": "Juan Pérez",
      "correo": "test.suscripcion+1@gmail.com",
      "celular": "1156781234",
      "registrado": "2026-08-31 22:33:16",
      "creado": true,
      "completado": []
    },
    "suscripcion": {
      "lista_id": 118,
      "lista_slug": "reactor-prospectos-frios",
      "lista_nombre": "Reactor Prospectos Fríos",
      "proyecto_id": 104,
      "nueva": true,
      "motivo": "solicitada",
      "destino": "test.suscripcion+1@gmail.com"
    },
    "etiquetas": [
      { "id": 5, "nombre": "expo", "slug": "expo", "creada": false, "aplicada": true }
    ]
  }
}
```

### `200` — ya estaba todo hecho

```json
{
  "ok": true,
  "data": {
    "prospecto":   { "id": 149365, "creado": false, "completado": [], "…": "…" },
    "suscripcion": { "lista_id": 118, "nueva": false, "…": "…" },
    "etiquetas":   [ { "id": 5, "nombre": "expo", "creada": false, "aplicada": false } ]
  }
}
```

### Campos

| Campo                      | Tipo    | Descripcion                                                          |
| -------------------------- | ------- | -------------------------------------------------------------------- |
| `prospecto.id`             | int     | Id de la ficha, creada o reutilizada.                                |
| `prospecto.uuid`           | string  | UUID v4 de la ficha.                                                 |
| `prospecto.nombre`         | string  | Derivado del campo de identidad del `tipo`.                          |
| `prospecto.correo`         | ?string | Estado **resultante**: si esta llamada lleno el hueco, es el nuevo.  |
| `prospecto.celular`        | ?string | Idem.                                                                |
| `prospecto.registrado`     | string  | `datetime` del alta original de la ficha.                            |
| `prospecto.creado`         | bool    | `false` = la ficha ya existia.                                       |
| `prospecto.completado`     | array   | Campos de contacto vacios que esta llamada lleno.                    |
| `suscripcion.lista_id`     | int     | Id de la lista resuelta.                                             |
| `suscripcion.lista_slug`   | string  | Slug canonico (el guardado, no el que mando el cliente).             |
| `suscripcion.lista_nombre` | ?string | Nombre de la lista.                                                  |
| `suscripcion.proyecto_id`  | ?int    | Proyecto de la lista. `null` = lista sin proyecto.                   |
| `suscripcion.nueva`        | bool    | `false` = ya estaba suscripto; no se escribio historial.             |
| `suscripcion.motivo`       | string  | Valor canonico con el que se registro el alta.                       |
| `suscripcion.destino`      | ?string | Dato de contacto con el que quedo el historial.                      |
| `etiquetas[].creada`       | bool    | La etiqueta no existia y se creo en el catalogo (`?crear_etiquetas=1`). |
| `etiquetas[].aplicada`     | bool    | Se le puso al prospecto en **esta** llamada. `false` = ya la tenia.  |

`etiquetas` sale en el mismo orden en que vinieron en el body.

---

## Errores

| Codigo | Cuando                                                                    |
| ------ | ------------------------------------------------------------------------- |
| 400    | Falta `lista` / `lista_id`, o la lista no existe.                          |
| 400    | El `motivo` no esta en el catalogo (el error lista los validos).           |
| 400    | El `correo` trae algo escrito pero no es una direccion recuperable.        |
| 400    | No vino ni `correo` ni `celular`.                                          |
| 400    | Hay que crear la ficha y no vino el nombre del lado que marca `tipo`.      |
| 400    | El `tipo` no es `persona` ni `empresa`.                                    |
| 400    | Alguna etiqueta no existe y no se mando `?crear_etiquetas=1`.              |
| 400    | Mas de 20 etiquetas, o un nombre de etiqueta invalido (parentesis, >80).   |
| 400    | El cuerpo no es JSON valido.                                               |
| 401    | Apikey ausente, desconocida o deshabilitada.                               |
| 405    | Cualquier metodo que no sea `POST`.                                        |
| 409    | El slug de lista existe en mas de un proyecto (viene con los candidatos).  |
| 500    | Error inesperado (queda en `sucesos` como `error`).                        |

Todos los `4xx` salen **antes** de escribir nada.

---

## Ejemplos `curl`

```bash
APIKEY=...
BASE=https://api.databox.net.ar/v4/datarocket/suscripcion

# 1) Newsletter de una landing: lo minimo que funciona siempre.
curl -X POST "$BASE" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Juan Pérez","correo":"juan@gmail.com"}'

# 2) Con etiquetas que ya existen en el catalogo.
curl -X POST "$BASE" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Juan Pérez","correo":"juan@gmail.com",
       "etiquetas":["expo","vip"]}'

# 3) Stand de una expo: las etiquetas se crean solas y queda la procedencia.
curl -X POST "$BASE?crear_etiquetas=1" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Juan Pérez","correo":"juan@gmail.com",
       "celular":"11 5678-1234","etiquetas":"expo 2027, stand-14",
       "motivo":"manual","detalle":"stand 14 - dia 2",
       "extraccion_autor":"tablet-stand"}'

# 4) Alguien que llega por WhatsApp y no deja correo.
curl -X POST "$BASE" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Contacto WhatsApp",
       "celular":"+54 9 351 555-9988"}'

# 5) Una empresa, con el slug escrito como el nombre de la lista.
curl -X POST "$BASE" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"Reactor Prospectos Fríos","tipo":"empresa",
       "nombre":"Molinos del Sur SA","empresa_cargo":"Compras",
       "correo":"compras@molinos.com.ar"}'

# 6) Slug repetido en dos proyectos -> 409. Se desambigua asi:
curl -X POST "$BASE" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"clientes","proyecto_id":104,"nombre":"Juan Pérez",
       "correo":"juan@gmail.com"}'
```

---

## Flujo completo: de una landing al envio masivo

```bash
# 1) El formulario postea. Una sola llamada: ficha + lista + etiquetas.
curl -X POST "https://api.databox.net.ar/v4/datarocket/suscripcion" \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"lista":"vigicom-usuarios","nombre":"Juan Pérez","correo":"juan@gmail.com",
       "etiquetas":"landing-cotizador"}'
# -> 201 {"ok":true,"data":{"prospecto":{"id":149365,"creado":true,...},
#                           "suscripcion":{"lista_id":136,"nueva":true,...}}}

# 2) Verificar cuanta gente tiene la lista (el conteo vivo, no el snapshot).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/listas?slug=vigicom-usuarios&con_conteo=1"
# -> {"ok":true,"data":{"id":136,...,"suscriptos":7039,"suscriptos_reales":7039}}

# 3) Barrer la audiencia para el envio (keyset, estable ante altas concurrentes).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/prospectos?lista=vigicom-usuarios&desde_id=0&limite=500"

# 4) Completar la ficha despues, si hace falta (domicilio, ubicacion, redes).
curl -X PATCH -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{"domicilio":"Av. Colón 1234","localidad_id":812}' \
  "https://api.databox.net.ar/v4/datarocket/prospectos?id=149365"
```

> Notar que en el paso 2 `suscriptos` y `suscriptos_reales` **coinciden**: este
> endpoint recalcula el denormalizado en la misma transaccion, a diferencia de
> `lista_ids` en `/v4/datarocket/prospectos`.

---

## Lo que este endpoint no hace

**No da de baja.** No hay `DELETE`, y no es una omision. La baja de una lista la
pide el destinatario desde el **enlace firmado** que viaja en sus correos
(`www/datarocket/suscripcion`, con `motivo: solicitada` y su propio historial en
`datarocket_listas_bajas`), o la aplica un operador desde el ABM del panel cloud,
donde hay usuario identificado y permisos. Una baja disparada por una integracion
sin ninguna de las dos cosas es una suscripcion que desaparece sin que nadie
pueda decir quien la saco.

**No crea listas.** Ver [La lista se resuelve por slug](#la-lista-se-resuelve-por-slug).

**No modifica ni borra etiquetas del catalogo.** Solo las crea, y con
`?crear_etiquetas=1`. Renombrar una etiqueta le cambia el significado a todos los
prospectos que la tienen puesta, y borrarla se lleva las asignaciones por el
`ON DELETE CASCADE`. Eso es curaduria y vive en el ABM.

**No pisa la ficha de un prospecto que ya existe** — salvo los huecos de
contacto. Ver [Sobre una ficha que ya existia](#sobre-una-ficha-que-ya-existia-no-se-pisa-nada).

**No carga la ficha completa.** Ver la nota de [Body](#body).

**No registra una consulta.** El bloque `embudo` + `asunto` + `mensaje` que abre
una oportunidad y una interaccion vive en
[`POST /v4/datarocket/prospectos`](prospectos.md). Suscribirse a una lista y
mandar una consulta son dos eventos distintos, con dos destinos distintos.

---

## Referencias

| Recurso | Donde |
| --- | --- |
| Implementacion | [suscripcion.php](suscripcion.php) |
| Puerta unica de las listas | [cloud/api/lib/datarocket_listas_suscripciones.php](../../../cloud/api/lib/datarocket_listas_suscripciones.php) |
| Normalizacion de campos | [cloud/api/lib/prospectos_normalizar.php](../../../cloud/api/lib/prospectos_normalizar.php) |
| Estampado de `fecha_uso` | [cloud/api/lib/datarocket_etiquetas_uso.php](../../../cloud/api/lib/datarocket_etiquetas_uso.php) |
| Log de errores en `sucesos` | [api/v4/_lib/log.php](../_lib/log.php) |
| Catalogo de listas | [`/v4/datarocket/listas`](listas.md) |
| Catalogo de etiquetas | [`/v4/datarocket/etiquetas`](etiquetas.md) |
| ABM completo del prospecto | [`/v4/datarocket/prospectos`](prospectos.md) |
| Pagina publica de alta / baja | `www/datarocket/suscripcion/index.php` |
| Schema | [db/schema.sql](../../../db/schema.sql) — `datarocket_prospectos`, `datarocket_listas`, `datarocket_prospectos_listas`, `datarocket_listas_altas`, `datarocket_etiquetas`, `datarocket_prospectos_etiquetas` |
