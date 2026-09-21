# `/v4/evolution/grupoMiembros`

> URL pública de esta documentación: <https://api.databox.net.ar/v4/evolution/grupoMiembros.md>

Administración de los **miembros de un grupo** de WhatsApp: agregarlos,
quitarlos, promoverlos a administrador o degradarlos.

Para ver quiénes son los miembros actuales no hace falta este endpoint: salen en
las propiedades del grupo
(`GET /v4/evolution/grupos?canal_slug=X&grupo=JID`, campo `participants`) —
ver [grupos.md](grupos.md#get--propiedades-de-un-grupo).

---

## Endpoint

Base URL: `https://api.databox.net.ar/v4/evolution/grupoMiembros`

| Método | Path                            | Uso                                          |
|--------|---------------------------------|----------------------------------------------|
| POST   | `/v4/evolution/grupoMiembros`   | Agregar, quitar, promover o degradar.        |

Cualquier otro método devuelve `405`. La ruta va **sin extensión**: la resuelve
el `.htaccess` de `api/`.

Las cuatro operaciones son el mismo endpoint de Evolution
(`/group/updateParticipant`) cambiando un `action`, así que acá son un endpoint
con un campo `accion` — igual que estaban del otro lado, y no tres URLs que
hacen lo mismo como en el legacy.

---

## Autenticación

Bearer con la `apikey` de una fila de `aplicaciones`:

```
Authorization: Bearer <APIKEY>
```

Mismas reglas que el resto del árbol v4 — ver [canales.md](canales.md#autenticación).

---

## Body

Content-Type: `application/json; charset=utf-8`.

| Campo        | Tipo           | Obligatorio | Notas                                                                |
|--------------|----------------|-------------|-----------------------------------------------------------------------|
| `canal_slug` | string         | Sí¹         | Canal desde el que se opera. Tiene que ser administrador del grupo.   |
| `grupo`      | string         | Sí          | JID del grupo (`120363…@g.us`). También se acepta el número pelado.   |
| `accion`     | string         | Sí          | `agregar` · `quitar` · `promover` · `degradar`.                       |
| `miembros`   | array\|string  | Sí²         | Números. Array, o string separado por comas.                          |
| `miembro`    | string         | Sí²         | Alias legacy de `miembros` (un solo número).                          |

¹ O `canal_id` / `canal` — ver [canales.md](canales.md#cómo-se-nombra-un-canal).
² Hace falta uno de los dos. Si vienen los dos, gana `miembros`.

Los números se normalizan solos a JID (`5492644984568` →
`5492644984568@s.whatsapp.net`) y se deduplican. Un JID completo se respeta tal
cual.

### `accion`

| `accion`   | `action` en Evolution | Qué hace                                      |
|------------|-----------------------|-----------------------------------------------|
| `agregar`  | `add`                 | Suma los números al grupo.                    |
| `quitar`   | `remove`              | Los saca del grupo.                           |
| `promover` | `promote`             | Los hace administradores.                     |
| `degradar` | `demote`              | Les quita el rol de administrador.            |

Se aceptan también los verbos en inglés (`add`, `remove`, `promote`, `demote`),
para un caller que ya venía hablando con Evolution directo.

**`accion` no tiene default a propósito**: entre agregar y quitar hay un error
que no se deshace, y un default convertiría un campo olvidado en una operación
silenciosa sobre gente real.

---

## Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "grupo":  "120363421234567890@g.us",
    "accion": "agregar",
    "miembros": [
      "5492644984568@s.whatsapp.net",
      "5491133445566@s.whatsapp.net"
    ],
    "respuesta": [
      { "status": "200", "jid": "5492644984568@s.whatsapp.net", "message": "Success" },
      { "status": "403", "jid": "5491133445566@s.whatsapp.net", "message": "Not authorized" }
    ]
  }
}
```

| Campo       | Qué es                                                                  |
|-------------|--------------------------------------------------------------------------|
| `grupo`     | El JID normalizado sobre el que se operó.                                |
| `accion`    | La acción tal como se interpretó.                                        |
| `miembros`  | Los JID normalizados que se mandaron — para confirmar qué se entendió.   |
| `respuesta` | Lo que devolvió Evolution, crudo: **un resultado por participante**.     |

> **Un `200` no quiere decir que hayan entrado todos.** WhatsApp responde por
> participante y el resultado suele ser mixto: se agregan tres de cuatro porque
> el cuarto tiene la privacidad cerrada, o el número no existe. Eso **no** es un
> error del request — es el resultado normal. **Hay que mirar `respuesta`
> ítem por ítem**; si sólo mirás el status HTTP, vas a creer que entraron todos.

Status típicos por participante:

| `status` | Significado                                                                       |
|----------|-----------------------------------------------------------------------------------|
| `200`    | Aplicado.                                                                         |
| `403`    | La privacidad del contacto no permite que lo agreguen a grupos. Se le puede mandar el enlace de invitación en su lugar. |
| `408`    | El número no está en WhatsApp.                                                    |
| `409`    | Ya era miembro del grupo.                                                         |

---

## Errores

Los comunes a los tres microservicios (canal ausente / inexistente / a medio
configurar) están en [canales.md](canales.md#cómo-se-nombra-un-canal).

| Código | Body `error`                                            | Cuándo                                               |
|--------|---------------------------------------------------------|------------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                              | El body no es JSON.                                  |
| 400    | `Falta grupo (JID del grupo)`                           | Sin `grupo`.                                         |
| 400    | `Falta accion. Opciones: agregar, quitar, promover, degradar.` | Sin `accion`.                                 |
| 400    | `Accion 'X' no valida. Opciones: …`                     | `accion` con un verbo desconocido.                   |
| 400    | `Falta miembro (numero, lista separada por comas, o array 'miembros')` | Sin `miembro` ni `miembros`.       |
| 405    | `Metodo no soportado. Usa POST con 'accion'…`           | Verbo distinto de POST.                              |
| 502    | `Actualizar miembros del grupo: cURL: Operation timed out…` | Evolution no contestó — típicamente un JID de grupo que no existe (ver el aviso en [grupos.md](grupos.md#canal-y-grupo)). |
| 502    | `Actualizar miembros del grupo: HTTP 4xx: …`            | Evolution rechazó el pedido — casi siempre porque el canal no es administrador del grupo. |

Todo `4xx` y `5xx` queda registrado en la tabla `sucesos` (panel → Herramientas →
Visor de sucesos) con la aplicación que lo provocó y su IP.

---

## Ejemplos

### curl — agregar dos personas

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/grupoMiembros \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "grupo":      "120363421234567890@g.us",
    "accion":     "agregar",
    "miembros":   ["5492644984568", "5491133445566"]
  }'
```

### curl — sacar a alguien

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/grupoMiembros \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "grupo":      "120363421234567890@g.us",
    "accion":     "quitar",
    "miembro":    "5492644984568"
  }'
```

### curl — hacer administrador

```bash
curl -X POST https://api.databox.net.ar/v4/evolution/grupoMiembros \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug": "databox-bot",
    "grupo":      "120363421234567890@g.us",
    "accion":     "promover",
    "miembro":    "5492644984568"
  }'
```

---

## Equivalencias con el legacy

| Legacy (`/v2/evolution`)                         | Acá                                        |
|--------------------------------------------------|--------------------------------------------|
| `POST grupoMiembroAnadir`                        | `accion: "agregar"`                        |
| `POST grupoMiembroQuitar`                        | `accion: "quitar"`                         |
| `POST grupoMiembroCambiar` con `administrador=1` | `accion: "promover"`                       |
| `POST grupoMiembroCambiar` con `administrador=0` | `accion: "degradar"`                       |

Qué cambia además de la URL:

- **Auth**: header `Authorization: Bearer <apikey>` en vez de `apikey` en el body.
- **Envelope**: `{"ok":true,"data":{...}}` en vez de `{codigo, mensaje, cuerpo}`
  con códigos propios (603/604/605/606).
- **Status HTTP reales**: el legacy contestaba `200` con el error de cURL adentro
  del cuerpo. Acá un fallo de Evolution es `502` y un error del caller es `400`.
- **Varios miembros de una**: `miembros` acepta un array. El legacy partía el
  string por comas y también funciona acá, pero un array no se rompe con un JID
  raro.
- **`administrador` ya no existe**: era un flag numérico sobre un endpoint
  llamado "Cambiar" que no decía qué cambiaba. Ahora la acción se nombra.

El `/v2` sigue funcionando: esto es aditivo y no lo toca.

---

## Referencias

- Lógica compartida: [cloud/api/lib/evolution_grupos.php](../../../cloud/api/lib/evolution_grupos.php).
- Cliente HTTP de Evolution: [cloud/api/lib/evolution_api.php](../../../cloud/api/lib/evolution_api.php).
- Origen del port: `databox_legacy/databox-api/modulos/evolution.php` (clase `mcEvolution`).
- Grupos: [grupos.md](grupos.md) · Canales: [canales.md](canales.md) · Mensajes: [mensajes.md](mensajes.md).
