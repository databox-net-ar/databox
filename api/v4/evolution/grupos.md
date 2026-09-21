# `/v4/evolution/grupos`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/evolution/grupos.md>

Gestión de **grupos de WhatsApp** a través de Evolution API: listarlos, ver sus
propiedades, sacar el enlace de invitación, crearlos y cambiarles nombre,
descripción, foto y permisos.

Los **miembros** van aparte, en [`/v4/evolution/grupoMiembros`](grupoMiembros.md).

> **Este microservicio no guarda nada.** Evolution (y detrás, WhatsApp) es la
> fuente de verdad de los grupos; no hay tabla local que los espeje. Todo GET es
> una consulta en vivo y todo POST/PATCH impacta en WhatsApp de inmediato, sin
> cola y sin posibilidad de deshacer.

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/evolution/grupos`

| Método | Path                                                            | Uso                                      |
|--------|-----------------------------------------------------------------|------------------------------------------|
| GET    | `/v4/evolution/grupos?canal_slug=X`                             | Listar todos los grupos del canal.       |
| GET    | `/v4/evolution/grupos?canal_slug=X&grupo=JID`                   | Propiedades de un grupo.                 |
| GET    | `/v4/evolution/grupos?canal_slug=X&grupo=JID&invitacion=1`      | Código y enlace de invitación.           |
| POST   | `/v4/evolution/grupos`                                          | Crear un grupo.                          |
| PATCH  | `/v4/evolution/grupos`                                          | Nombre / descripción / ícono / permisos. |

Cualquier otro método devuelve `405`. La ruta va **sin extensión**: la resuelve
el `.htaccess` de `api/`, que cubre todo el árbol con un rewrite interno al
`.php`.

---

## Autenticación

Bearer con la `apikey` de una fila de `aplicaciones`:

```
Authorization: Bearer <APIKEY>
```

Mismas reglas que el resto del árbol v4 — ver [canales.md](canales.md#autenticación).

---

## Canal y grupo

**El canal** se nombra con `canal_slug` (canónico), `canal_id` o el alias legacy
`canal`. Los detalles y los errores están en
[canales.md](canales.md#cómo-se-nombra-un-canal).

**El grupo** se nombra con su **JID**: `120363404912440827@g.us`. Se acepta
también el número pelado (`120363404912440827`) — se le agrega el sufijo `@g.us`
automáticamente. El JID sale del listado de grupos, en el campo `id`.

> **Ojo con el JID inexistente.** Si le pedís a Evolution una escritura sobre un
> grupo que no existe (o en el que el bot no está), la llamada **no da error**:
> se queda esperando hasta que corta nuestro timeout de 30 s y volvés con un
> `502 ... Operation timed out`. Es comportamiento de Evolution/Baileys, no algo
> que podamos detectar antes sin duplicar la latencia de cada escritura.
>
> Y el costo no es sólo tuyo: **mientras una llamada está colgada, las demás
> llamadas a ESE canal se encolan detrás** (Evolution serializa por instancia),
> así que un JID mal copiado le mete 30 s de demora a todo lo que pase por ese
> bot — incluido el envío de mensajes. Si vas a operar en lote, validá los JID
> contra el listado primero.

---

## GET — listar los grupos del canal

```
GET /v4/evolution/grupos?canal_slug=databox-bot
GET /v4/evolution/grupos?canal_slug=databox-bot&participantes=1
```

| Parámetro       | Default | Notas                                                          |
|-----------------|---------|-----------------------------------------------------------------|
| `participantes` | `0`     | `1` incluye la lista de miembros de **cada** grupo.             |

> **`participantes=1` es caro.** Evolution resuelve los miembros grupo por grupo;
> en una cuenta con cientos de grupos eso se pasa de los 30 s de timeout y el
> request muere entero. Para los miembros de uno solo, pedí sus propiedades.

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "total": 1,
    "items": [
      {
        "id":                  "120363391060546392@g.us",
        "subject":             "Dirección de Recursos Energéticos",
        "subjectOwner":        "112077272293471@lid",
        "subjectTime":         1737242813,
        "pictureUrl":          "https://pps.whatsapp.net/...",
        "size":                8,
        "creation":            1737242813,
        "owner":               "112077272293471@lid",
        "restrict":            true,
        "announce":            true,
        "isCommunity":         false,
        "isCommunityAnnounce": false
      }
    ]
  }
}
```

Los objetos de `items` vienen **crudos de Evolution**, sin renombrar campos. Los
dos que importan para los permisos:

- `announce: true` → **solo administradores escriben**.
- `restrict: true` → **solo administradores editan** la info del grupo.

(Son el estado que se cambia con `escribir` / `editar` en el PATCH — ojo que
están invertidos respecto de esos flags; ver la tabla más abajo.)

---

## GET — propiedades de un grupo

```
GET /v4/evolution/grupos?canal_slug=databox-bot&grupo=120363391060546392@g.us
```

Devuelve la ficha completa del grupo, incluida la lista de participantes con su
rol. `data` es lo que devuelve `GET /group/findGroupInfos`, tal cual.

```json
{
  "ok": true,
  "data": {
    "id":           "120363391060546392@g.us",
    "subject":      "Dirección de Recursos Energéticos",
    "desc":         "Coordinación del área",
    "owner":        "112077272293471@lid",
    "size":         8,
    "participants": [
      { "id": "5491163219578@s.whatsapp.net", "admin": "superadmin" },
      { "id": "5492644984568@s.whatsapp.net", "admin": null }
    ]
  }
}
```

`admin` vale `superadmin` (creador), `admin` o `null` (miembro común).

---

## GET — enlace de invitación

```
GET /v4/evolution/grupos?canal_slug=databox-bot&grupo=120363391060546392@g.us&invitacion=1
```

```json
{
  "ok": true,
  "data": {
    "inviteUrl":  "https://chat.whatsapp.com/JlZu63eMGOs5vH5bMmznDq",
    "inviteCode": "JlZu63eMGOs5vH5bMmznDq"
  }
}
```

El enlace deja entrar a **cualquiera que lo tenga**, sin que un administrador lo
apruebe. Tratalo como una credencial: quien lo reenvía está repartiendo acceso al
grupo.

---

## POST — crear un grupo

Content-Type: `application/json; charset=utf-8`.

### Body

| Campo        | Tipo            | Obligatorio | Notas                                                                    |
|--------------|-----------------|-------------|---------------------------------------------------------------------------|
| `canal_slug` | string          | Sí¹         | Canal que crea el grupo (queda como administrador).                       |
| `nombre`     | string          | Sí          | Nombre (subject) del grupo.                                               |
| `miembros`   | array\|string   | No          | Números a incluir. Array, o string separado por comas.                    |
| `miembro`    | string          | No          | Alias legacy de `miembros` (un solo número).                              |

¹ O `canal_id` / `canal`.

**Si no mandás miembros** se usa el `celular` del propio canal, igual que hacía el
legacy: WhatsApp no permite crear un grupo vacío. Queda un grupo con el bot
adentro, al que después se le agregan participantes por
[`/v4/evolution/grupoMiembros`](grupoMiembros.md). Si el canal tampoco tiene
`celular` cargado → `400 Falta miembro: el canal no tiene 'celular' cargado...`.

Los números se normalizan solos a JID (`5492644984568` →
`5492644984568@s.whatsapp.net`) y se deduplican.

### Respuesta — 201 Created

`data` es lo que devuelve `POST /group/create`, crudo:

```json
{
  "ok": true,
  "data": {
    "id":           "120363421234567890@g.us",
    "subject":      "Clientes 2026",
    "owner":        "5491163219578@s.whatsapp.net",
    "creation":     1758400000,
    "participants": [
      { "id": "5492644984568@s.whatsapp.net", "admin": null }
    ]
  }
}
```

Guardate el `id`: es el JID con el que se opera el grupo de ahí en adelante.

---

## PATCH — nombre, descripción, ícono y permisos

Un PATCH y no cuatro endpoints: del lado de Evolution son cuatro llamadas
distintas, pero del lado del integrador es una sola intención (*cambiar la
configuración de este grupo*) y la mitad de las veces se tocan dos cosas juntas.
**Se aplica únicamente lo que venga en el body.**

### Body

| Campo         | Tipo           | Notas                                                                     |
|---------------|----------------|----------------------------------------------------------------------------|
| `canal_slug`  | string         | Obligatorio (o `canal_id` / `canal`).                                      |
| `grupo`       | string         | Obligatorio. JID del grupo.                                                |
| `nombre`      | string         | Nuevo nombre (subject).                                                    |
| `descripcion` | string         | Nueva descripción. **Cadena vacía = borrar la descripción** (es un valor válido, no se ignora). |
| `icono`       | string         | URL pública de la imagen. Ver el aviso de abajo.                           |
| `escribir`    | bool           | `true` = escriben todos · `false` = solo administradores.                  |
| `editar`      | bool           | `true` = todos editan la info · `false` = solo administradores.            |

Los booleanos aceptan `true`/`false`, `1`/`0`, `"si"`/`"no"`, `"on"`/`"off"`.
Cualquier otra cosa → `400`. **Un campo ausente no se toca**: un PATCH que sólo
manda `nombre` no pisa los permisos del grupo.

Si no viene ninguno de los cinco → `400 Nada que cambiar...`.

> **La URL del `icono` la descarga Evolution, no nosotros.** Tiene que ser
> alcanzable *desde el servidor de Evolution*, y si no responde la llamada **se
> cuelga hasta el timeout** y volvés con un `502 ... Operation timed out` que no
> dice nada de la imagen. Antes de culpar al endpoint, probá la URL con
> `curl -I`. (Le pasó al ejemplo que traía el legacy: apuntaba a un host que ya
> no existe.)

Relación con lo que se lee en el listado (están invertidos — cuidado):

| Flag del PATCH | Valor   | `action` en Evolution | Se ve en el listado como |
|----------------|---------|-----------------------|--------------------------|
| `escribir`     | `true`  | `not_announcement`    | `announce: false`        |
| `escribir`     | `false` | `announcement`        | `announce: true`         |
| `editar`       | `true`  | `unlocked`            | `restrict: false`        |
| `editar`       | `false` | `locked`              | `restrict: true`         |

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "grupo": "120363391060546392@g.us",
    "aplicado": {
      "nombre":      { "update": "success" },
      "descripcion": { "update": "success" },
      "permisos": {
        "escribir": { "update": "success" }
      }
    }
  }
}
```

`aplicado` trae una clave por cada cosa que se cambió, con la respuesta cruda de
Evolution para esa llamada.

> **El PATCH no es atómico.** Son hasta cinco requests contra Evolution y WhatsApp
> no ofrece forma de agruparlos: si el tercero falla, los dos primeros ya se
> aplicaron. El error corta ahí (no sigue con los que faltan) y la respuesta de
> error **no** lleva `aplicado`. Si te importa saber exactamente qué quedó
> aplicado, mandá un PATCH por campo.

---

## Errores

Los comunes a los tres microservicios (canal ausente / inexistente / a medio
configurar) están en [canales.md](canales.md#cómo-se-nombra-un-canal).

| Código | Body `error`                                  | Cuándo                                                        |
|--------|-----------------------------------------------|---------------------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                    | El body de POST/PATCH no es JSON.                             |
| 400    | `Falta grupo (JID del grupo)`                 | PATCH sin `grupo`.                                            |
| 400    | `Falta nombre del grupo`                      | POST sin `nombre`, o PATCH con `nombre` vacío.                |
| 400    | `Falta icono (URL de la imagen)`              | `icono` presente pero vacío.                                  |
| 400    | `Falta miembro: el canal no tiene 'celular'…` | POST sin miembros y con el canal sin `celular` cargado.       |
| 400    | `Valor 'X' no es un booleano valido…`         | `escribir` / `editar` con algo que no es booleano.            |
| 400    | `Nada que cambiar. Campos aceptados: …`       | PATCH sin ninguno de los cinco campos.                        |
| 405    | `Metodo no soportado. Usa GET…`               | Verbo distinto de GET/POST/PATCH.                             |
| 502    | `<operacion>: cURL: Operation timed out…`     | Evolution no contestó — típicamente un JID que no existe.     |
| 502    | `<operacion>: HTTP 4xx: …`                    | Evolution rechazó el pedido (permisos, grupo ajeno, URL de imagen inaccesible). |

Todo `4xx` y `5xx` queda registrado en la tabla `sucesos` (panel → Herramientas →
Visor de sucesos) con la aplicación que lo provocó y su IP.

---

## Ejemplos

### curl — listar grupos

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/evolution/grupos?canal_slug=databox-bot"
```

### curl — crear un grupo con tres miembros

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/grupos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "nombre":     "Clientes 2026",
    "miembros":   ["5492644984568", "5491133445566", "5491199887766"]
  }'
```

### curl — renombrar y cerrar la escritura a administradores

```bash
curl -X PATCH https://api.databox.net.ar/v4/evolution/grupos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "grupo":      "120363421234567890@g.us",
    "nombre":     "Clientes 2026 — Avisos",
    "escribir":   false
  }'
```

### curl — cambiar la foto del grupo

```bash
curl -X PATCH https://api.databox.net.ar/v4/evolution/grupos \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "grupo":      "120363421234567890@g.us",
    "icono":      "https://ejemplo.databox.net.ar/imagenes/grupo-clientes.jpg"
  }'
```

### curl — sacar el enlace de invitación

```bash
curl -H "Authorization: Bearer $APIKEY" \
  "https://api.databox.net.ar/v4/evolution/grupos?canal_slug=databox-bot&grupo=120363421234567890@g.us&invitacion=1"
```

---

## Equivalencias con el legacy

| Legacy (`/v2/evolution`)                       | Acá                                                              |
|------------------------------------------------|------------------------------------------------------------------|
| `POST canalGrupos`                             | `GET  /v4/evolution/grupos?canal_slug=X`                         |
| `POST grupoPropiedadesObtener`                 | `GET  /v4/evolution/grupos?canal_slug=X&grupo=JID`               |
| `POST grupoInvitacionObtener`                  | `GET  /v4/evolution/grupos?canal_slug=X&grupo=JID&invitacion=1`  |
| `POST grupoCrear`                              | `POST /v4/evolution/grupos`                                      |
| `POST grupoNombreCambiar`                      | `PATCH /v4/evolution/grupos` con `nombre`                        |
| `POST grupoDescripcionCambiar`                 | `PATCH /v4/evolution/grupos` con `descripcion`                   |
| `POST grupoIconoCambiar`                       | `PATCH /v4/evolution/grupos` con `icono`                         |
| `POST grupoPermisosCambiar`                    | `PATCH /v4/evolution/grupos` con `escribir` y/o `editar`         |

Qué cambia además de la URL:

- **Auth**: header `Authorization: Bearer <apikey>` en vez de `apikey` en el body.
- **Envelope**: `{"ok":true,"data":{...}}` en vez de `{codigo, mensaje, cuerpo}`
  con códigos propios (603/604/605/606).
- **Status HTTP reales**: el legacy contestaba `200` con el error de cURL adentro
  del cuerpo. Acá un fallo de Evolution es `502` y un error del caller es `400`.
- **Permisos granulares**: `grupoPermisosCambiar` mandaba **siempre los dos**
  flags, así que cambiar uno pisaba el otro con el default del caller (`0`). Acá
  se manda sólo el que pediste. Y devolvía los dos JSON de Evolution
  concatenados — un string inválido; acá van separados en `aplicado.permisos`.

El `/v2` sigue funcionando: esto es aditivo y no lo toca.

---

## Fuera del alcance

Operaciones que Evolution expone y **no** están acá porque tampoco estaban en el
legacy. Si alguna hace falta, se agrega a
[cloud/api/lib/evolution_grupos.php](../../../cloud/api/lib/evolution_grupos.php)
y se expone acá:

- Salir de un grupo (`/group/leaveGroup`) — **no hay forma de borrar un grupo
  creado por error**: hay que sacar a los miembros y salirse a mano desde el
  teléfono.
- Revocar el código de invitación (`/group/revokeInviteCode`).
- Entrar a un grupo por código (`/group/inviteCode` inverso).
- Aprobar/rechazar solicitudes de ingreso.
- Comunidades (crear, vincular grupos).

---

## Referencias

- Lógica compartida: [cloud/api/lib/evolution_grupos.php](../../../cloud/api/lib/evolution_grupos.php).
- Cliente HTTP de Evolution: [cloud/api/lib/evolution_api.php](../../../cloud/api/lib/evolution_api.php).
- Origen del port: `databox_legacy/databox-api/modulos/evolution.php` (clase `mcEvolution`).
- Canales: [canales.md](canales.md) · Miembros: [grupoMiembros.md](grupoMiembros.md) · Mensajes: [mensajes.md](mensajes.md).
