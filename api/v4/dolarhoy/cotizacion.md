# /v4/dolarhoy/cotizacion

> URL pública de esta documentación: <https://api.databox.net.ar/v4/dolarhoy/cotizacion.md>

Microservicio de consulta de la cotización del dólar guardada en el ABM cloud
→ Plataformas → DolarHoy → Cotizaciones.

Es un endpoint **de solo lectura** — sirve la fila de la tabla
`dolarhoy_cotizaciones` (schema en [db/schema.sql](../../../db/schema.sql)).
El alta, edición y baja manual de cotizaciones vive en el ABM interno
([cloud/api/dolarhoycotizaciones.php](../../../cloud/api/dolarhoycotizaciones.php)),
y la fila de cada día la graba automáticamente el job
`dolarhoy_cotizacion_actualizar` (lunes a viernes 07:00).

Para obtener la cotización **realtime** (scrapeada al vuelo desde dolarhoy.com,
sin persistir) el panel usa otro endpoint interno,
[cloud/api/dolarhoy_realtime.php](../../../cloud/api/dolarhoy_realtime.php),
que hoy **no** está expuesto vía v4.

---

## Endpoint

Base URL: `https://api.databox.net.ar/v4/dolarhoy/cotizacion`

| Método | Path                                             | Uso                                       |
|--------|--------------------------------------------------|-------------------------------------------|
| GET    | `/v4/dolarhoy/cotizacion`                        | Última cotización cargada.                |
| GET    | `/v4/dolarhoy/cotizacion?fecha=YYYY-MM-DD`       | Cotización de esa fecha específica.       |

Cualquier otro método devuelve `405 Metodo no soportado`. La ruta va **sin
extensión**: la resuelve el `.htaccess` de `api/`, que cubre todo el árbol con
un rewrite interno al `.php`.

---

## Autenticación

Bearer con la `apikey` de una fila de `aplicaciones` (misma tabla que usa el
resto del stack v4).

```
Authorization: Bearer <APIKEY>
```

Reglas:

- Sin header → `401 Bearer token ausente`.
- Apikey inexistente → `401 API key desconocida`.
- Aplicación con `habilitada != '1'` → `401 Aplicacion deshabilitada`.
- El contador `aplicaciones.usos` se incrementa por request (best-effort; un
  fallo en el UPDATE no tira el request).

Apache no siempre propaga `Authorization` — el handler chequea
`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` y como último recurso
`getallheaders()`.

---

## GET — última cotización

Sin query string, devuelve la fila más reciente ordenada por `fecha DESC,
id DESC` (el desempate por `id` cubre el caso de varias cotizaciones cargadas
para la misma fecha).

```
GET /v4/dolarhoy/cotizacion
```

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "id":     8617,
    "fecha":  "2026-07-31",
    "compra": 1240.50,
    "venta":  1280.00
  }
}
```

Campos:

| Campo    | Tipo         | Notas                                                  |
|----------|--------------|--------------------------------------------------------|
| `id`     | int          | Clave primaria en `dolarhoy_cotizaciones`.             |
| `fecha`  | string       | `YYYY-MM-DD`. Puede ser `null` si la fila la tiene así.|
| `compra` | number\|null | Cotización de compra (decimal 11,2).                   |
| `venta`  | number\|null | Cotización de venta (decimal 11,2).                    |

---

## GET — cotización de una fecha específica

Filtra por igualdad exacta contra `dolarhoy_cotizaciones.fecha`. Si hay más de
una fila cargada para ese día, se devuelve la de mayor `id`. Si no hay
ninguna fila para esa fecha, la respuesta es `404 Cotizacion no encontrada`
(el endpoint **no** hace fallback a fechas anteriores).

```
GET /v4/dolarhoy/cotizacion?fecha=2026-07-30
```

Formato aceptado: `YYYY-MM-DD` estricto. Cualquier otra cosa (incluido
`YYYY/MM/DD`, `DD-MM-YYYY`, timestamps con hora, etc.) devuelve
`400 Formato de fecha invalido (esperado YYYY-MM-DD)`.

### Respuesta — 200 OK

Idéntica a la de la última cotización.

---

## Errores

| Código | Body `error`                                         | Cuándo                                              |
|--------|------------------------------------------------------|-----------------------------------------------------|
| 400    | `Formato de fecha invalido (esperado YYYY-MM-DD)`    | `?fecha=` no matchea `^\d{4}-\d{2}-\d{2}$`.         |
| 401    | `Bearer token ausente`                               | Falta el header `Authorization: Bearer …`.          |
| 401    | `API key desconocida`                                | La apikey no existe en `aplicaciones`.              |
| 401    | `Aplicacion deshabilitada`                           | `aplicaciones.habilitada != '1'`.                   |
| 404    | `Cotizacion no encontrada`                           | Tabla vacía, o la fecha pedida no tiene fila.       |
| 405    | `Metodo no soportado`                                | Método distinto de GET.                             |
| 500    | `<mensaje de la excepción>`                          | Falla inesperada (PDO, etc.).                       |

---

## Ejemplos

### curl — última cotización

```bash
curl -H "Authorization: Bearer $APIKEY" \
  https://api.databox.net.ar/v4/dolarhoy/cotizacion
```

### curl — cotización de una fecha específica

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/dolarhoy/cotizacion?fecha=2026-07-30"
```

---

## Fuera del alcance

Este microservicio es intencionalmente mínimo: **solo consulta puntual**. Los
siguientes casos existen en el ABM cloud pero **no** están expuestos vía v4
(hoy los consume únicamente la UI interna):

- Listado con filtros y stats  → `GET  cloud/api/dolarhoycotizaciones.php`
- Alta                         → `POST cloud/api/dolarhoycotizaciones.php`
- Modificación                 → `PUT  cloud/api/dolarhoycotizaciones.php?id=N`
- Baja                         → `DELETE cloud/api/dolarhoycotizaciones.php?id=N`
- Cotización realtime          → `GET  cloud/api/dolarhoy_realtime.php` (scrapea dolarhoy.com al vuelo)

Si algún consumidor externo llegara a necesitar alguno de esos, se agrega acá
como endpoint separado.

---

## Referencias

- Tabla fuente: `dolarhoy_cotizaciones` — schema en [db/schema.sql](../../../db/schema.sql).
- ABM interno equivalente: [cloud/api/dolarhoycotizaciones.php](../../../cloud/api/dolarhoycotizaciones.php).
- Cotización realtime (scraper): [cloud/api/dolarhoy_realtime.php](../../../cloud/api/dolarhoy_realtime.php).
- Scraper compartido: [cloud/api/lib/dolarhoy_cotizacion.php](../../../cloud/api/lib/dolarhoy_cotizacion.php).
- Job que puebla la tabla: [cloud/jobs/dolarhoy_cotizacion_actualizar.php](../../../cloud/jobs/dolarhoy_cotizacion_actualizar.php) (`0 7 * * 1-5`).
