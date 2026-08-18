# `/v4/datarocket/etiquetas`

> Documentacion online: <https://api.databox.net.ar/v4/datarocket/etiquetas.md>

Microservicio del **CRM Datarocket** sobre la tabla `datarocket_etiquetas` — el
catalogo de etiquetas reutilizables que se aplican a los prospectos a traves de
la tabla puente `datarocket_prospectos_etiquetas`. Un unico archivo `.php`
([etiquetas.php](etiquetas.php)) que sirve todos los verbos del recurso — sin
framework ni router aparte.

Las operaciones que motivan el microservicio:

| Quiero…                                              | Uso                                                |
| ---------------------------------------------------- | -------------------------------------------------- |
| Ver que etiquetas hay disponibles                    | `GET /v4/datarocket/etiquetas`                     |
| Tengo el nombre y necesito el id                     | `GET /v4/datarocket/etiquetas?nombre=expo`         |
| Crear una etiqueta nueva                             | `POST /v4/datarocket/etiquetas`                    |
| "Dame el id, y si no existe creala"                  | `POST /v4/datarocket/etiquetas?resolver=1`         |
| Ponerle la etiqueta a un prospecto                   | `PATCH /v4/datarocket/prospectos?id=N` (ver abajo) |

**Buscar no distingue mayusculas ni acentos.** `EXPO`, `Expo`, `expo` y `expó`
son la misma etiqueta, y `prueba_nandu` encuentra a `prueba_ñandú`. Vale para
las dos formas de buscar y tambien para el control de duplicados del alta. Ver
[Mayusculas, acentos y espacios](#mayusculas-acentos-y-espacios).

**Este endpoint no modifica ni borra etiquetas.** Solo consulta y alta; no hay
`PUT`, `PATCH` ni `DELETE`. Ver
[Por que no se puede modificar ni borrar](#por-que-no-se-puede-modificar-ni-borrar).

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`, ver
`docker-compose.yml`). La URL va **sin extension** — el `.htaccess` de `api/` la
resuelve contra el `.php` correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/datarocket/etiquetas
```

Es el punto de entrada **externo** (llamado por otras aplicaciones del grupo via
HTTP). La UI de administracion interna (panel cloud > Sistemas > Datarocket >
Etiquetas) usa su propio endpoint
[cloud/api/datarocket_etiquetas.php](../../../cloud/api/datarocket_etiquetas.php),
que ademas expone la modificacion, la baja y el recalculo del contador. Ambos
caminos escriben en la misma tabla; la diferencia es la capa de auth (permisos
de sesion vs. Bearer estatico) y el alcance de las operaciones.

---

## Autenticacion

Bearer estatico contra `aplicaciones.apikey` (misma tabla que el resto del
stack). El header debe llegar como:

```
Authorization: Bearer <apikey>
```

Cualquier apikey habilitada pasa — no hay scope por endpoint. Cada llamada
exitosa incrementa `aplicaciones.usos` (best-effort), y cada alta deja un
registro en `sucesos` con el nombre de la aplicacion que la creo.

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

Base URL: `https://api.databox.net.ar/v4/datarocket/etiquetas`

| Metodo | Path                                       | Uso                                              |
| ------ | ------------------------------------------ | ------------------------------------------------ |
| GET    | `/v4/datarocket/etiquetas`                 | Listado con filtros (query string).              |
| GET    | `/v4/datarocket/etiquetas?id=N`            | Consulta individual de la etiqueta N.            |
| GET    | `/v4/datarocket/etiquetas?nombre=expo`     | Resolucion exacta nombre → id. `404` si no esta. |
| POST   | `/v4/datarocket/etiquetas`                 | Alta. `409` si el nombre ya existe.              |
| POST   | `/v4/datarocket/etiquetas?resolver=1`      | Alta idempotente: devuelve la existente o la crea. |

Cualquier otro metodo devuelve `405 Metodo no soportado`.

Precedencia de los parametros en `GET`: `?id=N` gana sobre `?nombre=`, y
`?nombre=` gana sobre el listado. `?resolver=1` en un `GET` devuelve `405` con
la aclaracion de que va por `POST` — no cae silenciosamente al listado.

### `?nombre=` o `?resolver=1`

Los dos resuelven un nombre a un id. La diferencia es que uno escribe:

| | `GET ?nombre=expo` | `POST ?resolver=1` |
| ------------------------------- | ------------------------- | ----------------------------- |
| Si la etiqueta existe           | La devuelve (`200`)       | La devuelve (`200`)           |
| Si no existe                    | `404`                     | La crea y la devuelve (`201`) |
| Escribe en la base              | Nunca                     | Solo cuando no existia        |
| Como se distingue un caso del otro | Por el codigo HTTP     | Por `data.creada` (bool)      |

Regla practica: **`GET ?nombre=` cuando el catalogo lo curan personas** (si la
etiqueta no esta, es un error de datos que alguien tiene que mirar);
**`POST ?resolver=1` cuando el catalogo lo alimenta la integracion** — un
importador que trae etiquetas nuevas junto con los prospectos y no quiere
frenarse ni manejar un `409`.

---

## Modelo de datos

Tabla `datarocket_etiquetas` (migraciones `20260811_1200`, `_1400`, `_1800` y
`20260812_0000`).

| Campo                | Tipo           | En el JSON | Notas                                                        |
| -------------------- | -------------- | ---------- | ------------------------------------------------------------ |
| `id`                 | `int`          | `int`      | Autoincremental. Es lo que se manda en `etiqueta_ids`.       |
| `nombre`             | `varchar(80)`  | `string`   | **UNIQUE.** Clave logica del catalogo. Se normaliza (ver abajo). |
| `descripcion`        | `varchar(500)` | `string?`  | Nota interna opcional. Vacia se guarda como `null`.          |
| `etiquetados`        | `int unsigned` | `int`      | Contador denormalizado. **Puede estar atrasado** (ver abajo). |
| `fecha_creacion`     | `datetime`     | `string`   | La pone la base (`CURRENT_TIMESTAMP`).                       |
| `fecha_modificacion` | `datetime`     | `string`   | La pone la base (`ON UPDATE CURRENT_TIMESTAMP`).             |

Una fila tal como sale del endpoint:

```json
{
  "id": 5,
  "nombre": "expo",
  "descripcion": null,
  "etiquetados": 5066,
  "fecha_creacion": "2026-08-11 19:43:28",
  "fecha_modificacion": "2026-08-11 22:50:24"
}
```

Los `POST` agregan la clave `creada` (bool) y `?con_conteo=1` agrega
`etiquetados_reales` (int).

### Mayusculas, acentos y espacios

**La busqueda es insensible a mayusculas y a acentos, y no hay que normalizar
nada del lado del cliente.** Los tres ejemplos devuelven la misma fila:

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?nombre=expo"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?nombre=EXPO"
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?nombre=ExP%C3%B3"   # ExPó
```

Vale para las dos busquedas (`?nombre=` por igualdad y `?q=` por coincidencia
parcial) y **tambien para el control de duplicados**: dar de alta `PRUEBA_NANDU`
choca con la `prueba_ñandú` que ya estaba, y `POST ?resolver=1` devuelve esa
misma fila en vez de crear una segunda.

Lo resuelven dos capas:

1. **La collation `utf8mb4_general_ci` de la columna**, que pliega mayusculas y
   diacriticos precompuestos. Para la base `cafe` = `café`, `nandu` = `ñandú` y
   `pinguino` = `pingüino`. Es la misma collation que respalda el `UNIQUE`, asi
   que la busqueda y el control de duplicados no pueden divergir.
2. **El plegado del lado PHP** (`etqPlegarTexto`), que ademas de `trim`,
   colapsar corridas de espacios y pasar a minusculas saca las **marcas
   combinantes** `U+0300`–`U+036F`. Eso cubre el agujero que la collation no
   tapa: el texto en forma **NFD**, donde la `é` viaja como `e` + tilde suelta
   (lo que mandan varios teclados de macOS / iOS). Ahi el valor tiene un
   caracter mas que la fila guardada y la igualdad falla aunque la collation sea
   accent-insensitive.

Consecuencias practicas al **dar de alta**:

- `"   Prueba   MicroServicio  "` se guarda como `prueba microservicio`.
- `"prueba_ñandú"` se guarda tal cual **si viene en NFC**; si viene en NFD se
  guarda como `prueba_nandu`. Las dos grafias no pueden coexistir (para la base
  son la misma etiqueta) y cualquiera de las dos encuentra a la otra, que es lo
  que importa. Guardar el NFD tal cual seria peor: quedaria una fila que ninguna
  busqueda normal vuelve a encontrar.
- El nombre **no puede contener parentesis** (`400`). Eran los delimitadores del
  formato historico `(expo)(visitante)` con el que las tablas legacy sin guion
  bajo todavia guardan sus etiquetas.
- `descripcion` **no** se normaliza: es texto libre y conserva acentos y
  mayusculas.

### `etiquetados` es un snapshot, no un contador vivo

`etiquetados` es un denormalizado que se recalcula **a mano** desde el ABM del
panel cloud (boton "Recalcular etiquetados"), no un trigger. Entre recalculos
queda atrasado respecto de la puente: una etiqueta recien creada y ya aplicada a
un prospecto sigue mostrando `0`.

Se publica igual porque para pintar un listado alcanza y sale gratis. Cuando el
numero tiene que ser exacto va **`?con_conteo=1`**, que agrega
`etiquetados_reales` contado en vivo contra `datarocket_prospectos_etiquetas`:

```json
{
  "id": 36,
  "nombre": "prueba microservicio",
  "etiquetados": 0,
  "etiquetados_reales": 1
}
```

El flag vale para el listado, para `?id=N` y para `?nombre=`. Cuesta una query
agrupada extra sobre la puente (53.741 filas en dev al 2026-08-18), asi que no
viene por default.

---

## `GET /v4/datarocket/etiquetas` — Listado

### Query params

| Param        | Tipo   | Default  | Notas                                                          |
| ------------ | ------ | -------- | -------------------------------------------------------------- |
| `q`          | string | —        | Coincidencia parcial sobre `nombre` **y** `descripcion`.        |
| `codigo`     | int    | —        | Filtra por `id` exacto.                                         |
| `order_by`   | enum   | `nombre` | `id`, `nombre`, `etiquetados`, `fecha_creacion`, `fecha_modificacion`. Tambien se acepta como `orden`. |
| `dir`        | enum   | ver nota | `asc` / `desc`.                                                 |
| `limite`     | int    | `100`    | Clampeado a `[1, 1000]`.                                        |
| `con_conteo` | flag   | `0`      | Agrega `etiquetados_reales` a cada item.                        |

Un `order_by` desconocido cae al default en vez de dar `400` — un parametro mal
escrito no justifica romperle la pantalla al cliente.

> **Default del orden:** alfabetico ascendente (`nombre ASC`), al reves que
> `/v4/datarocket/prospectos` (que ordena por `id DESC`). Es a proposito: esto es
> un catalogo chico que casi siempre termina en un combo, y ahi el orden util es
> el alfabetico. Para los demas criterios el default de `dir` es `desc`.

### Respuesta (200)

```json
{
  "ok": true,
  "data": {
    "total": 3,
    "items": [
      { "id": 13, "nombre": "barrio_privado", "descripcion": null, "etiquetados": 258,
        "fecha_creacion": "2026-08-11 19:43:28", "fecha_modificacion": "2026-08-11 22:50:24" },
      { "id": 35, "nombre": "Causam", "descripcion": null, "etiquetados": 27,
        "fecha_creacion": "2026-08-12 00:21:21", "fecha_modificacion": "2026-08-17 20:04:49" },
      { "id": 17, "nombre": "club", "descripcion": null, "etiquetados": 62,
        "fecha_creacion": "2026-08-11 19:43:28", "fecha_modificacion": "2026-08-11 22:50:24" }
    ]
  }
}
```

`total` es la cantidad de items **devueltos**, no el total de la tabla — si
llega recortado por `limite`, los dos numeros coinciden y no hay forma de
distinguirlo desde la respuesta. El catalogo tiene 29 filas en dev al
2026-08-18, asi que con el `limite` default entra entero.

### Ejemplo `curl`

```bash
# Todas las etiquetas, alfabetico.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas"

# Las 10 mas usadas, con el conteo real.
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?order_by=etiquetados&limite=10&con_conteo=1"

# Buscar por aproximacion (insensible a mayusculas y acentos).
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?q=jur%C3%ADdico"
# -> {"ok":true,"data":{"total":1,"items":[{"id":14,"nombre":"estudio_juridico",...}]}}
```

---

## `GET /v4/datarocket/etiquetas?id=N` — Consulta individual

Devuelve la fila completa. Acepta `?con_conteo=1`.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?id=5"
# -> {"ok":true,"data":{"id":5,"nombre":"expo","descripcion":null,"etiquetados":5066,...}}
```

| Codigo | Cuando                          |
| ------ | ------------------------------- |
| 404    | `Etiqueta no encontrada`        |

---

## `GET /v4/datarocket/etiquetas?nombre=expo` — Resolucion nombre → id

El caso de uso que motiva el endpoint: el cliente tiene el **texto** de la
etiqueta y necesita el **id** para mandarlo en `etiqueta_ids` de
`/v4/datarocket/prospectos`.

Devuelve la misma forma que `?id=N` (un objeto, no una lista). La comparacion es
por igualdad, insensible a mayusculas y acentos. Acepta `?con_conteo=1`.

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/datarocket/etiquetas?nombre=EXPO"
# -> {"ok":true,"data":{"id":5,"nombre":"expo",...}}
```

### Errores

| Codigo | Cuerpo                                                                  |
| ------ | ----------------------------------------------------------------------- |
| 400    | `{"ok":false,"error":"El \`nombre\` a buscar no puede estar vacio."}`    |
| 404    | `{"ok":false,"error":"Etiqueta no encontrada","consulta":{"nombre":"no_existe_xyz"}}` |

`?nombre=` sin valor es `400`, no "sin filtro": el cliente pidio buscar un nombre
y devolverle el catalogo entero seria contestarle otra pregunta.

El `404` incluye `consulta.nombre` con el valor **ya normalizado**, para que se
entienda contra que se busco realmente (`" Expo "` se busco como `expo`).

Para buscar por aproximacion en vez de por igualdad esta `?q=`, que devuelve
listado y nunca `404`.

---

## `POST /v4/datarocket/etiquetas` — Alta

### Body

```json
{
  "nombre": "expo",
  "descripcion": "Contactos levantados en ferias y exposiciones"
}
```

| Campo         | Obligatorio | Notas                                                    |
| ------------- | ----------- | -------------------------------------------------------- |
| `nombre`      | Si          | Se normaliza. Maximo 80 caracteres. Sin parentesis.       |
| `descripcion` | No          | Maximo 500 caracteres. Vacia o ausente se guarda `null`.  |

### Respuesta (201)

La fila completa mas `creada`:

```json
{
  "ok": true,
  "data": {
    "id": 36,
    "nombre": "prueba microservicio",
    "descripcion": "tag de prueba v4",
    "etiquetados": 0,
    "fecha_creacion": "2026-08-18 09:28:32",
    "fecha_modificacion": "2026-08-18 09:28:32",
    "creada": true
  }
}
```

`etiquetados` arranca en `0`: una etiqueta recien creada no esta aplicada a
ningun prospecto todavia.

### Errores

| Codigo | Cuerpo                                                                    |
| ------ | ------------------------------------------------------------------------- |
| 400    | `{"ok":false,"error":"Cuerpo no es JSON valido"}`                         |
| 400    | `{"ok":false,"error":"El \`nombre\` es obligatorio."}`                    |
| 400    | `{"ok":false,"error":"El \`nombre\` no puede superar los 80 caracteres."}` |
| 400    | `{"ok":false,"error":"El \`nombre\` no puede contener parentesis."}`      |
| 400    | `{"ok":false,"error":"La \`descripcion\` no puede superar los 500 caracteres."}` |
| 409    | `{"ok":false,"error":"Ya existe una etiqueta con ese nombre.","etiqueta":{...}}` |

**El `409` trae la etiqueta que choco en la clave `etiqueta`**, con su `id`. Un
cliente que solo queria el id puede quedarse con ese y seguir, sin tener que
volver a preguntar — o directamente usar `?resolver=1` y ahorrarse el error.

### Ejemplo `curl`

```bash
curl -X POST "https://api.databox.net.ar/v4/datarocket/etiquetas" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"nombre":"expo 2027","descripcion":"Feria de Buenos Aires"}'
# -> {"ok":true,"data":{"id":40,"nombre":"expo 2027",...,"creada":true}}
```

---

## `POST /v4/datarocket/etiquetas?resolver=1` — Alta idempotente

Mismo body que el alta. La diferencia es que **nunca da `409`**: si la etiqueta
existe la devuelve, y si no existe la crea.

| Codigo | Significa                                    | `data.creada` |
| ------ | -------------------------------------------- | ------------- |
| 200    | Ya existia — no se escribio nada             | `false`       |
| 201    | No existia y se creo                         | `true`        |

```bash
curl -X POST "https://api.databox.net.ar/v4/datarocket/etiquetas?resolver=1" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"nombre":"PRUEBA MicroServicio"}'
# -> 200 {"ok":true,"data":{"id":36,"nombre":"prueba microservicio",...,"creada":false}}
```

Es idempotente de verdad: llamarlo N veces con el mismo nombre deja una sola
fila. Dos llamadas simultaneas tampoco duplican — si la carrera se cuela entre
la busqueda y el `INSERT`, el `UNIQUE` de `nombre` desempata y el endpoint
devuelve la fila que gano, no un `500`.

**No es un upsert.** Si la etiqueta ya existe, la `descripcion` del body se
**ignora** en vez de pisar la que hay. Modificar el catalogo se hace desde el
ABM del panel cloud.

`?resolver=1` en un `GET` devuelve `405`:

```json
{"ok":false,"error":"`?resolver=1` requiere POST (crea la etiqueta si no existe). Para buscar sin crear usa `GET ?nombre=...`."}
```

---

## Flujo completo: etiquetar un prospecto

Las etiquetas se **asignan** desde el endpoint de prospectos, no desde este.
Este resuelve el nombre; el otro aplica el id.

```bash
# 1) Nombre -> id. Con `?resolver=1` la etiqueta se crea sola si es nueva.
ET=$(curl -s -X POST "https://api.databox.net.ar/v4/datarocket/etiquetas?resolver=1" \
       -H "Authorization: Bearer $APIKEY" \
       -H "Content-Type: application/json" \
       -d '{"nombre":"expo"}')
# -> {"ok":true,"data":{"id":5,"nombre":"expo",...,"creada":false}}

# 2) Aplicarla al prospecto.
curl -X PATCH "https://api.databox.net.ar/v4/datarocket/prospectos?id=149309" \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{"etiqueta_ids":[5]}'
# -> {"ok":true,"data":{"id":149309,"campos":["etiqueta_ids"]}}
```

> **`etiqueta_ids` es reemplazo total, no "agregar".** El `PATCH` borra las
> asignaciones que tenia el prospecto y deja exactamente las del array — mandar
> `[5]` a un prospecto que ya tenia otras dos etiquetas lo deja con una sola, y
> `[]` lo deja sin ninguna. Para **sumar** una etiqueta hay que leer primero:
>
> ```bash
> # GET del prospecto -> data.etiqueta_ids: [12, 18]
> # PATCH con la union -> {"etiqueta_ids":[12,18,5]}
> ```
>
> El `GET` de prospectos devuelve `etiqueta_ids` (int[]) y `etiqueta_nombres`
> (string[]) alineados, tanto en el listado como en la consulta individual, asi
> que la lectura previa no cuesta una llamada extra si ya venias trayendo el
> prospecto.

Borrar un prospecto limpia sus asignaciones solo (la puente cae por el
`ON DELETE CASCADE` de la FK); las etiquetas del catalogo no se tocan.

---

## Por que no se puede modificar ni borrar

No hay `PUT`, `PATCH` ni `DELETE` en este endpoint. No es un olvido.

Una etiqueta no es un dato de un cliente: es una **clave compartida** por todos
los prospectos que la tienen puesta. Renombrarla les cambia el significado a
todos a la vez, y borrarla se lleva puestas las asignaciones — la puente cae por
el `ON DELETE CASCADE` de `fk_dpe_etiqueta`, sin aviso y sin vuelta atras. Con
8.255 prospectos colgando de `estudio_juridico`, un `DELETE` mal apuntado desde
una integracion es un incidente, no un error de tipeo.

Son operaciones de **curaduria del catalogo**, no de integracion, asi que viven
unicamente en el ABM del panel cloud, donde hay usuario identificado, permisos
(`datarocket.etiquetas.editar` / `.eliminar`) y suceso asociado. Desde afuera un
integrador puede consultar el catalogo y sumarle etiquetas nuevas; lo que ya
existe no lo puede tocar.

Ahi mismo esta el boton **"Recalcular etiquetados"**, que es lo unico que
actualiza la columna `etiquetados`.

---

## Referencias

- Implementacion: [etiquetas.php](etiquetas.php)
- ABM interno del panel: [cloud/api/datarocket_etiquetas.php](../../../cloud/api/datarocket_etiquetas.php)
- Endpoint que aplica las etiquetas: [prospectos.md](prospectos.md)
- Esquema de la base: [db/schema.sql](../../../db/schema.sql)
- Migraciones de la tabla: `cloud/sql/migrations/20260811_1200_crear_datarocket_etiquetas.sql`,
  `20260811_1400_datarocket_etiquetas_quitar_activo.sql`,
  `20260811_1800_datarocket_etiquetas_quitar_color.sql`,
  `20260812_0000_datarocket_etiquetas_agregar_etiquetados.sql`
- Tabla puente con prospectos: `cloud/sql/migrations/20260811_1600_crear_datarocket_contactos_etiquetas.sql`
  (la tabla se llama `datarocket_prospectos_etiquetas` desde el rename del 2026-08-17)
