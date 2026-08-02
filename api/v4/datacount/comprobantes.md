# POST /v4/datacount/comprobantes

Da de alta un comprobante en `datacount_comprobantes` (+ sus renglones en
`datacount_comprobantes_renglones`) usando el **id de talonario** como unica
referencia estructural. `proyecto`, `empresa`, `tipo`, `punto` y `fiscal`
se heredan del talonario — el caller no los pisa.

## Autorizacion sincronica

Si el talonario es fiscal (`fiscal='1'`) y el comprobante queda en estado
`'2'` (default), el microservicio **autoriza el comprobante contra AFIP en
la misma request** delegando en la lib compartida
`cloud/api/lib/datacount_comprobantes_autorizar.php::dccAutAutorizar()` —
mismo canal que usa el cron batch y el boton "Autorizar" del ABM.

- **OK**: el comprobante queda en estado `'3'` con `cae`, `cae_vto`,
  `serie` (= `cbte_nro`), `autorizado` y `caeres` seteados; la response
  HTTP 201 los incluye en `data`.
- **Error**: el comprobante queda persistido en estado `'2'` con el
  mensaje en `caeres` como evidencia. La response es HTTP 422 con el
  mensaje + el id/uuid para que el caller pueda referenciarlo despues.
  Si el error es **permanente** (rechazo AFIP, cert vencido, empresa mal
  configurada, etc.), ademas se detiene el motor automatico
  (`parametros.datacount.comprobantes.autorizar='0'`) — un fallo
  sistemico romperia igual al cron del proximo minuto. Los transitorios
  (AFIP/Apache caidos, timeouts) NO detienen el motor: el cron los
  reintenta solo en el proximo tick.

Cuando NO se intenta autorizar (talonario no fiscal, o el caller pisa el
`estado` con `'1'` para dejarlo en borrador), el comprobante se devuelve
tal como fue creado, con `cae`/`cae_vto`/`cbte_nro`/etc. en `null`.

## Auth

Bearer con `apikey` de la tabla `aplicaciones` (mismo esquema que el resto
de los microservicios `/v4`).

```
Authorization: Bearer <apikey>
```

## Request

`Content-Type: application/json`

| Campo           | Tipo         | Obligatorio | Detalle |
|-----------------|--------------|-------------|---------|
| `talonario`     | int          | si          | id de `datacount_talonarios`. De aca salen `proyecto`, `empresa`, `tipo`, `punto`, `fiscal`. |
| `renglones`     | array        | si          | Al menos 1 item. Cada item: `{ cantidad, unitario, iva, detalle?, articulo?, orden?, estado? }`. `iva` es la alicuota en % (ej. `21`). |
| `cliente`       | int          | no          | id interno del cliente. |
| `razon`         | string(250)  | no          | Razon social. |
| `condicion`     | string(2)    | no          | Condicion IVA. |
| `cuit`          | string(50)   | no          | CUIT / DNI. |
| `domicilio`     | string(250)  | no          | |
| `correo`        | string(100)  | no          | |
| `celular`       | string(100)  | no          | |
| `emision`       | date         | no          | `YYYY-MM-DD`. Default: hoy (America/Argentina/Buenos_Aires). |
| `vencimiento`   | date         | no          | `YYYY-MM-DD`. Default: hoy + 7. |
| `concepto`      | int (1/2/3)  | no          | AFIP: 1=Productos, 2=Servicios, 3=Prod+Serv. Default: 3. |
| `asociado`      | int          | no          | Id del comprobante que se acredita. **Obligatorio para notas de credito** (NA/NB/NC/NM): AFIP exige `cbtes_asoc` y se arma con el `tipo`/`punto`/`serie` del asociado, que debe estar autorizado (`estado='3'`, fiscal, factura). |
| `contrato`      | int          | no          | |
| `medio`         | int          | no          | |
| `observaciones` | string(2000) | no          | |
| `comentarios`   | string(2000) | no          | |
| `estado`        | string(1)    | no          | Default: `'2'` (Pendiente) — dispara la autorizacion sincronica. Pasar `'1'` (Preparacion) para dejarlo como borrador sin llamar a AFIP. |

`neto`, `iva` y `total` se **recalculan siempre server-side** desde
`renglones` — cualquier valor en el body para esas claves se ignora.

## Response

### 201 Created — autorizado OK

```json
{
  "ok": true,
  "data": {
    "id": 24682,
    "uuid": "3a1b7c9d02",
    "talonario": 42,
    "proyecto": 5,
    "empresa": 12,
    "tipo": "FA",
    "punto": 1,
    "fiscal": "1",
    "concepto": 3,
    "emision": "2026-08-01",
    "vencimiento": "2026-08-08",
    "neto": "100.00",
    "iva": "21.00",
    "total": "121.00",
    "estado": "3",
    "registrado": "2026-08-01 10:15:34",
    "cae": "76234567890123",
    "cae_vto": "20260811",
    "cbte_nro": 4271,
    "serie": 4271,
    "autorizado": "2026-08-01 10:15:35",
    "caeres": "OK CAE 76234567890123 (vto 20260811)"
  }
}
```

### 201 Created — sin autorizacion (no fiscal o estado != '2')

Los campos `cae`, `cae_vto`, `cbte_nro`, `serie`, `autorizado`, `caeres`
salen en `null`, `estado` refleja lo que se pidio (default `'2'`).

### 422 Unprocessable Entity — autorizacion AFIP fallo

El comprobante **quedo creado** en estado `'2'`; `data` trae el mismo
payload que la 201, con `caeres` = mensaje del error. `fuente` indica si
la falla fue en la validacion previa (`'validacion'`) o en el
microservicio ARCA / AFIP (`'microservicio'`).

```json
{
  "ok": false,
  "error": "(10015) Fecha del comprobante posterior a la fecha de proceso",
  "fuente": "microservicio",
  "data": {
    "id": 24682,
    "uuid": "3a1b7c9d02",
    "estado": "2",
    "caeres": "(10015) Fecha del comprobante posterior a la fecha de proceso",
    "cae": null,
    "cae_vto": null,
    "cbte_nro": null,
    "...": "..."
  }
}
```

## Errores

| Codigo | Cuando |
|--------|--------|
| 400    | Falta `talonario`, `renglones` vacio, `concepto` invalido. |
| 401    | Bearer ausente / apikey desconocida / aplicacion deshabilitada. |
| 404    | Talonario inexistente. |
| 405    | Metodo distinto de POST. |
| 422    | Comprobante creado pero autorizacion AFIP fallo (transitorio o permanente). Ver `data.id` para reintentar. Un error permanente **detiene el motor automatico** (`parametros.datacount.comprobantes.autorizar='0'`). |
| 500    | Excepcion no controlada (se propaga el mensaje). |

## Ejemplo

```bash
curl -X POST https://api.databox.net.ar/v4/datacount/comprobantes \
     -H "Authorization: Bearer XXXXXXXX" \
     -H "Content-Type: application/json" \
     -d '{
       "talonario": 42,
       "razon": "ACME SRL",
       "cuit": "20111111112",
       "condicion": "RI",
       "concepto": 2,
       "renglones": [
         {"cantidad": 1, "detalle": "Servicio mensual", "unitario": 100, "iva": 21}
       ]
     }'
```
