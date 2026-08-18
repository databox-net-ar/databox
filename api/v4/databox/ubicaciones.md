# `/v4/databox/ubicaciones`

> Documentacion online: <https://api.databox.net.ar/v4/databox/ubicaciones.md>

Microservicio de consulta del **catalogo geografico**: `paises`, `provincias` y
`localidades` (ver [db/schema.sql](../../../db/schema.sql)). Un unico archivo
`.php` ([ubicaciones.php](ubicaciones.php)).

Existe para responder una sola pregunta: **"¿que id le mando a un endpoint que
pide `pais_id` / `provincia_id` / `localidad_id`?"** — tipicamente el `POST` de
[/v4/datarocket/prospectos](../datarocket/prospectos.md), que valida esos ids
contra estos mismos catalogos y contesta `400` si no existen.

**Solo lectura.** No hay `POST` / `PUT` / `DELETE`: el padron geografico se
mantiene por fuera de la API. Cualquier metodo que no sea `GET` devuelve `405`.

Vive en `databox/` y no en `datarocket/` a proposito: la geografia no es del
CRM, la puede querer cualquier otro microservicio de `v4/`.

Se accede via el vhost `api.databox.net.ar` (puerto interno `8114`). La URL va
**sin extension** — el `.htaccess` de `api/` la resuelve contra el `.php`
correspondiente para todo el arbol:

```
GET https://api.databox.net.ar/v4/databox/ubicaciones
```

---

## Por que un solo endpoint y no tres

Los tres catalogos **no son recursos independientes, son un arbol**. Una
localidad sin su provincia y su pais no le sirve a nadie: el consumidor tipico
necesita los tres ids juntos.

Y el caso de uso que motiva el endpoint — resolver un texto a un id — **cruza
las tres tablas**. Con endpoints separados serian tres llamadas encadenadas que
el cliente tiene que unir a mano:

```
GET /v4/paises?q=argentina                    -> 1
GET /v4/provincias?pais_id=1&q=buenos         -> 6
GET /v4/localidades?provincia_id=6&q=quilmes  -> 6658
```

Aca es una sola, porque cada item viaja con toda su cadena hacia arriba:

```
GET /v4/databox/ubicaciones?q=quilmes
-> { "id": 6658, "nombre": "Quilmes", "provincia_id": 6,
     "provincia_nombre": "Buenos Aires", "pais_id": 1, "pais_nombre": "Argentina" }
```

El costo asumido es que `?tipo=` es un discriminador y no una URL de recurso, o
sea que **no es REST purista**. Se acepta: `v4` ya no lo es (`?id=N`,
`?verificar=1` en prospectos) y la cohesion del arbol pesa mas que la ortodoxia.

---

## Autenticacion

Bearer estatico contra `aplicaciones.apikey`, igual que el resto de `v4`:

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

---

## Contrato de respuesta

Shape unificado del stack:

```json
{ "ok": true,  "data": { "total": <n>, "items": [ … ] } }
{ "ok": false, "error": "<mensaje>" }
```

`total` es la cantidad de items **devueltos** (post-`limite`), no el total
absoluto en el catalogo. Misma semantica que el listado de prospectos.

---

## Query params

Todos opcionales.

| Parametro      | Tipo   | Notas                                                                                                      |
| -------------- | ------ | ---------------------------------------------------------------------------------------------------------- |
| `tipo`         | string | `paises` / `provincias` / `localidades`. Se aceptan tambien en singular (`pais`, `provincia`, `localidad`). |
| `q`            | string | Busqueda `LIKE '%q%'` sobre `nombre`.                                                                       |
| `id`           | int    | Fila puntual. **Requiere `tipo`** (el id 6 son tres filas distintas segun el nivel).                        |
| `pais_id`      | int    | Filtra por pais. Sobre `tipo=paises` se lee como "este pais".                                              |
| `provincia_id` | int    | Filtra por provincia. Sobre `tipo=provincias` se lee como "esta provincia". Ignorado en `tipo=paises`.      |
| `limite`       | int    | Default `50`. Clampeado a `[1, 500]`.                                                                       |

### Que devuelve segun lo que mandes

| Llamada                                     | Resultado                                          |
| ------------------------------------------- | -------------------------------------------------- |
| `?` (nada)                                  | Los paises.                                        |
| `?q=<texto>` sin `tipo`                     | Busca en **los tres niveles**.                     |
| `?tipo=provincias&pais_id=1`                | Provincias de ese pais.                            |
| `?tipo=localidades&provincia_id=6`          | Localidades de esa provincia.                      |
| `?tipo=localidades&q=<texto>`               | Busca solo en localidades.                         |
| `?tipo=localidad&id=6658`                   | Esa localidad, con su cadena. `404` si no existe.   |

El default sin `tipo` ni `q` son los **paises** y no "los tres niveles" porque
una llamada pelada al endpoint es casi siempre el primer combo de una cascada.

> `?tipo=localidades` sin ningun filtro devuelve las primeras `limite`
> alfabeticamente. Es una respuesta valida pero poco util: el padron de
> localidades es grande (~94k filas segun el `AUTO_INCREMENT` de la tabla en
> prod), asi que conviene siempre acotar con `provincia_id`, `pais_id` o `q`.

---

## Modelo de la respuesta

Cada item lleva `tipo` (el nivel al que pertenece) y **toda su cadena hacia
arriba**. Las claves que no aplican al nivel simplemente no vienen.

### `tipo: "pais"`

```json
{ "tipo": "pais", "id": 1, "nombre": "Argentina" }
```

### `tipo: "provincia"`

```json
{
  "tipo": "provincia",
  "id": 6,
  "nombre": "Buenos Aires",
  "categoria": "Provincia",
  "pais_id": 1,
  "pais_nombre": "Argentina"
}
```

`categoria` distingue `Provincia` de `Ciudad Autónoma` (Capital Federal).

### `tipo: "localidad"`

```json
{
  "tipo": "localidad",
  "id": 6658,
  "nombre": "Quilmes",
  "categoria": "Partido",
  "provincia_id": 6,
  "provincia_nombre": "Buenos Aires",
  "pais_id": 1,
  "pais_nombre": "Argentina"
}
```

`categoria` es la division administrativa local: `Partido` (Buenos Aires),
`Departamento` (resto de Argentina), `Barrio`, `Cantón` (Ecuador).

Los ids salen como **int**, no como string — se pueden reenviar tal cual al
`POST` de prospectos sin castear.

> `pais_id` y `provincia_id` son nullable en el schema, asi que el endpoint los
> puede devolver en `null` (junto a su `*_nombre`). Hoy no hay huerfanos, pero
> el consumidor no deberia asumir que siempre vienen.

---

## Busqueda (`q`)

### Es insensible a acentos y a la eñe

Las tres columnas son `utf8mb4_general_ci`, collation que pliega los diacriticos.
Sale gratis, sin normalizar nada:

| Buscas       | Encuentra    |
| ------------ | ------------ |
| `peru`       | `Perú`       |
| `canuelas`   | `Cañuelas`   |
| `QUILMES`    | `Quilmes`    |

**No normalices el termino del lado del cliente** — conviene mandarlo tal cual,
porque es lo que permite que el match exacto gane el orden.

### Orden de los resultados

1. **Por nivel**: paises, despues provincias, despues localidades.
2. **Dentro de cada nivel, por relevancia**: match exacto, despues los que
   empiezan con el termino, despues el resto; a igualdad, alfabetico.

El orden por jerarquia antes que por relevancia global es deliberado: en una
busqueda ambigua el resultado mas general primero es el que desambigua.

```bash
curl -G "https://api.databox.net.ar/v4/databox/ubicaciones" \
  -H "Authorization: Bearer $APIKEY" --data-urlencode "q=buenos aires"
```

```json
{"ok":true,"data":{"total":2,"items":[
  {"tipo":"provincia","id":6,"nombre":"Buenos Aires","categoria":"Provincia","pais_id":1,"pais_nombre":"Argentina"},
  {"tipo":"localidad","id":78035,"nombre":"Lago Buenos Aires","categoria":"Departamento","provincia_id":78,"provincia_nombre":"Santa Cruz","pais_id":1,"pais_nombre":"Argentina"}
]}}
```

El `limite` se reparte entre los niveles: cada uno se consulta con el cupo
entero y recien despues se recorta el total, asi una busqueda que matchea 40
localidades y 1 provincia devuelve las dos cosas en vez de gastar todo el cupo
en el primer nivel.

---

## Errores

| Codigo | Body `error`                                          | Cuando                                        |
| ------ | ----------------------------------------------------- | --------------------------------------------- |
| 400    | `El tipo debe ser paises, provincias o localidades.`  | `tipo` con un valor fuera de la lista.        |
| 400    | `Para consultar por \`id\` hay que indicar el \`tipo\`.` | Vino `id` sin `tipo`.                       |
| 404    | `Ubicación no encontrada`                             | `tipo` + `id` de una fila que no existe.      |
| 405    | `Metodo no soportado`                                 | Cualquier metodo que no sea `GET`.            |
| 500    | `<mensaje de la excepcion>`                           | Falla inesperada (PDO, etc.).                 |

Un filtro que no matchea nada (`?tipo=localidades&provincia_id=99999`) devuelve
`200` con `items: []`, no `404`. El `404` es solo para la consulta por `id`,
donde el cliente pregunto por una fila concreta.

---

## Uso tipico: dar de alta un prospecto con ubicacion

### A) Cascada de combos (formulario)

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/databox/ubicaciones"                              # paises
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/databox/ubicaciones?tipo=provincias&pais_id=1"    # provincias
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/databox/ubicaciones?tipo=localidades&provincia_id=6"
```

### B) Resolver texto suelto (importador de CSV)

```bash
# 1. una sola llamada devuelve los tres ids
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/databox/ubicaciones?tipo=localidades&q=quilmes"
# -> {"id":6658, "provincia_id":6, "pais_id":1, …}

# 2. el alta usa `id` del item como `localidad_id`
curl -X POST https://api.databox.net.ar/v4/datarocket/prospectos \
  -H "Authorization: Bearer $APIKEY" -H "Content-Type: application/json" \
  -d '{
    "tipo":           "persona",
    "persona_nombre": "Juan Perez",
    "correo":         "juan.perez@acme.com",
    "localidad_id":   6658,
    "provincia_id":   6,
    "pais_id":        1
  }'
```

**El mapeo de claves es lo unico a tener en cuenta:** el `id` del item es el
`<tipo>_id` del prospecto. Una localidad con `"id": 6658` se postea como
`"localidad_id": 6658`; sus `provincia_id` y `pais_id` ya vienen con el nombre
correcto y se copian tal cual.

Los tres campos son opcionales en el alta — si no los mandas, el prospecto queda
sin ubicacion, no falla.

---

## Notas sobre los datos

- El catalogo cubre 8 paises (Argentina, Chile, Uruguay, Paraguay, Colombia,
  Perú, México, Ecuador). No es un padron mundial.
- Hay filas con **mojibake preexistente** en `nombre` (ej. `1Â° de Mayo`, id
  22126, donde el `°` quedo doble-codificado en el origen). El endpoint las
  devuelve tal cual — no las corrige ni las oculta. Si vas a mostrar nombres al
  usuario final, tenelo en cuenta.

---

## Referencias

- Tablas: `paises`, `provincias`, `localidades` — schema en [db/schema.sql](../../../db/schema.sql).
- Consumidor principal: [/v4/datarocket/prospectos](../datarocket/prospectos.md).
- Helper de auth por Bearer: [cloud/api/lib/apikey_auth.php](../../../cloud/api/lib/apikey_auth.php) (el v4 rueda la logica inline para no arrastrar dependencias, pero el shape es identico).
- Los mismos catalogos, para la UI interna del panel (auth por sesion, no por apikey): [cloud/api/datarocketprospectos.php](../../../cloud/api/datarocketprospectos.php) — `?lookups=1`, `?provincias=<pais_id>`, `?localidades=<provincia_id>`.
