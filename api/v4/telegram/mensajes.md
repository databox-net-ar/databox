# /v4/telegram/mensajes

> URL pública de esta documentación: <https://api.databox.net.ar/v4/telegram/mensajes.md>

Microservicio de envío de mensajes de Telegram en modo **usuario** (MTProto
via [MadelineProto](https://docs.madelineproto.xyz)). El envío es **síncrono**:
el POST no vuelve hasta que Telegram aceptó o rechazó el mensaje.

Soporta **múltiples cuentas remitentes** (canales) — cada canal es una
cuenta de Telegram distinta autenticada una única vez. El body del POST
elige por cuál canal se envía (por slug lógico). Cada canal vive en un
directorio **totalmente aislado** en
`api/v4/telegram/canales/<telefono>/` con su propio phar de MadelineProto,
su propio archivo de sesión, su propio log y su propio `.phar.lock`. Nada
se comparte entre canales — probamos compartir el bootstrap y el lock y
terminó generando `AUTH_KEY_DUPLICATED` intermitente al usar dos canales
sucesivamente. El nombre del subdirectorio se deriva del teléfono E.164
del canal (no del slug), de modo que renombrar el slug no muda la sesión.

A diferencia de:

- **`/v4/evolution/mensajes`** (WhatsApp, cola asíncrona con motor).
- **`cloud/api/telegrammensajes.php`** (ABM del panel — no envía por sí mismo:
  encola en `telegram_mensajes` y el cron worker despacha contra este mismo
  endpoint).

acá el remitente es una **cuenta de usuario real** autenticada vía MTProto.
Ventaja: el destinatario ve un mensaje "de una persona", puede responder
libremente y no hace falta que haya iniciado una conversación con un bot.
Costo: cada cuenta tiene una sesión persistente (`session_<slug>/`) que se
genera **una única vez en desarrollo** vía CLI (`login.php --canal=<slug>`)
y viaja a producción con el deploy. **Prod nunca inicia sesión**.

---

## Modelo de canales

Los canales viven en la tabla **`telegram_canales`** (una fila por cuenta):

| Columna       | Tipo        | Notas                                                                   |
|---------------|-------------|-------------------------------------------------------------------------|
| `id`          | INT PK      | Autoincremental.                                                        |
| `slug`        | VARCHAR(50) | Identificador humano, `[a-z0-9-]`. Es lo que se pasa como `canal_slug`. |
| `proyecto`    | INT NULL    | FK lógica a `proyectos.id`.                                             |
| `nombre`      | VARCHAR     | Nombre humano ("Javier Alvarez (personal)").                            |
| `telefono`    | VARCHAR(20) | E.164 sin `+` — cuenta con la que se logueó la sesión.                  |
| `habilitado`  | VARCHAR(1)  | `'1'` = enviable. Otros valores → el endpoint rechaza.                  |
| `actualizado` | DATETIME    | Última modificación.                                                    |

La sesión asociada vive en `api/v4/telegram/canales/<telefono>/session.madeline/` (donde
`<telefono>` es el valor del campo `telefono` de la fila, en E.164 sin `+`).
El `login.php --canal=<slug>` mira la fila para derivar el nombre del
directorio, así que cargar bien `telefono` en la DB es prerrequisito para
loguear.

---

## Endpoints

Base URL: `https://api.databox.net.ar/v4/telegram/mensajes`

| Método | Path                    | Uso                              |
|--------|-------------------------|----------------------------------|
| POST   | `/v4/telegram/mensajes` | Enviar un mensaje por un canal.  |

Cualquier otro método devuelve `405 Metodo no soportado`. La ruta va **sin
extensión**: la resuelve el `.htaccess` de `api/`, que cubre todo el árbol con
un rewrite interno al `.php`.

---

## Autenticación

Bearer con la `apikey` de una fila de `aplicaciones` (misma tabla que usa el
resto del stack).

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

## POST — enviar un mensaje

Content-Type: `application/json; charset=utf-8`.

### Body

| Campo          | Tipo   | Obligatorio                                              | Notas                                                                        |
|----------------|--------|----------------------------------------------------------|------------------------------------------------------------------------------|
| `destinatario` | string | Sí                                                       | Teléfono en formato E.164 con o sin `+` (ej. `"+542644984568"`).             |
| `mensaje`      | string | Sí                                                       | Texto. Se envía tal cual, sin markdown ni templating.                        |
| `canal_slug`   | string | Sí si hay más de un canal habilitado ¹                   | Slug del canal remitente (ej. `"javier"`). Case-sensitive.                   |
| `canal_id`     | int    | Alternativa numérica a `canal_slug` (mismo criterio) ¹   | Si mandás los dos, `canal_slug` gana.                                        |
| `proyecto_id`  | int    | No                                                       | Para el historial. Si no viene, se hereda de `telegram_canales.proyecto`.    |
| `prospecto_id` | int    | No                                                       | Cuelga la interacción en el historial del prospecto de Datarocket.           |
| `tags`         | string | No                                                       | Etiqueta libre que queda en la fila del historial.                           |
| `mensaje_id`   | int    | No — **uso interno** ²                                   | Id de una fila de `telegram_mensajes` ya existente. Desactiva el registro.   |

¹ **Selección del canal**:
- Si hay **un solo** canal habilitado en `telegram_canales`, el endpoint lo
  auto-selecciona cuando el body no pasa `canal_slug`/`canal_id`.
- Si hay **varios** habilitados y el body no elige, `400` con la lista de
  slugs disponibles.
- `canal_slug` (o `canal_id`) que no existe → `400 Canal '<x>' no encontrado`.
- Canal existe pero `habilitado != '1'` → `400 Canal '<x>' esta deshabilitado`.

² **`mensaje_id`** lo usa el worker de la cola del panel
([cloud/api/lib/telegram_mensajes_enviar.php](../../../cloud/api/lib/telegram_mensajes_enviar.php)),
que ya tiene su propia fila y la persiste él mismo. Ver
[Historial en `telegram_mensajes`](#historial-en-telegram_mensajes). Una
aplicación externa **no** debe mandarlo: sin fila propia que actualizar, el
envío no quedaría registrado en ningún lado.

El handler normaliza el `destinatario` sacando el `+` y cualquier carácter
no numérico, así que aceptar variantes con espacios / paréntesis
(`"+54 264 498-4568"`) también funciona.

### Respuesta — 200 OK

```json
{
  "ok": true,
  "data": {
    "canal": {
      "id":       1,
      "slug":     "javier",
      "nombre":   "Javier Alvarez (personal)",
      "telefono": "+541163219578"
    },
    "destinatario": "+542644984568",
    "mensaje":      "PRUEBA",
    "message_id":   1234,
    "fecha":        "2026-07-30 19:45:12",
    "mensaje_id":   87
  }
}
```

`canal` es el remitente resuelto (útil cuando dependés del auto-pick).
`message_id` es el id del mensaje que asignó Telegram (útil para
correlacionar respuestas). `fecha` es el timestamp del mensaje según
Telegram, convertido a `America/Argentina/Buenos_Aires`. `mensaje_id` es el
id de la fila en `telegram_mensajes` — con él ubicás el envío en el ABM del
panel (ver abajo).

---

## Historial en `telegram_mensajes`

Cada envío que entra por acá queda registrado en la tabla
`telegram_mensajes`, que es lo que lista el ABM del panel en
**Plataformas > Telegram > Mensajes**. No hay que hacer nada del lado del
cliente: alcanza con llamar al endpoint.

La fila se abre **antes** de hablar con Telegram y se cierra después:

| Estado      | Cuándo                                                              |
|-------------|---------------------------------------------------------------------|
| `enviando`  | Fila recién abierta; el envío está en curso en ese mismo request.    |
| `enviado`   | Telegram aceptó el mensaje.                                          |
| `error`     | Falló la sesión, la resolución del destinatario o el envío. El motivo queda en la columna `error`. |

Abrirla antes es deliberado: si MadelineProto se cuelga o el proceso muere a
mitad de camino, en el panel queda un `enviando` visible en vez de no quedar
rastro de que se intentó. Se abre en `enviando` (y no en `pendiente`) para
que el cron worker de la cola **nunca** la levante — su `SELECT` toma sólo
`pendiente` —, así que no hay riesgo de que el mensaje salga dos veces.

Los campos se completan solos a partir del canal resuelto: `remitente` =
`telegram_canales.nombre`, `remite` = `telegram_canales.telefono` y
`proyecto_id` = `telegram_canales.proyecto` (salvo que el body traiga
`proyecto_id`). El `cuerpo` es exactamente el `mensaje` que se mandó por el
cable.

El registro es **best effort**: el producto del endpoint es el mensaje
entregado, la fila es contabilidad. Si la inserción falla (DB caída, drift de
schema), queda el rastro en Herramientas > Visor de sucesos y el envío sigue
su curso — la respuesta trae `mensaje_id: null`.

> **Mensajes encolados desde el panel.** El ABM cloud no llama a este endpoint
> directamente: encola en `telegram_mensajes` con estado `pendiente` y el cron
> worker despacha contra `/v4/telegram/mensajes` pasando `mensaje_id`. Ese
> campo desactiva el registro acá, porque la fila ya existe y la persiste el
> worker. Sin ese guard cada mensaje del ABM aparecería duplicado.

### Errores

| Código | Body `error`                                                                | Cuándo                                                                                                     |
|--------|-----------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------|
| 400    | `Cuerpo no es JSON valido`                                                  | El body no es JSON válido.                                                                                 |
| 400    | `Falta destinatario`                                                        | Body sin `destinatario` (o string vacío).                                                                  |
| 400    | `Falta mensaje`                                                             | Body sin `mensaje` (o string vacío).                                                                       |
| 400    | `destinatario invalido: se espera telefono en formato E.164 (con o sin +)`  | El destinatario no tiene al menos 8 dígitos después de normalizar.                                         |
| 400    | `Canal '<x>' no encontrado`                                                 | El `canal_slug` (o `canal_id`) del body no existe en `telegram_canales`.                                   |
| 400    | `Canal '<x>' esta deshabilitado`                                            | El canal existe pero `habilitado != '1'`.                                                                  |
| 400    | `No hay canales de Telegram habilitados. ...`                               | La tabla no tiene ninguna fila con `habilitado='1'`.                                                       |
| 400    | `Hay varios canales habilitados ('a', 'b', ...); pasar canal_slug ...`      | Hay más de un canal habilitado y el body no eligió.                                                        |
| 400    | `El numero +<tel> no tiene cuenta de Telegram (o esta oculto).`             | Telegram devolvió `contacts.resolvedPeer` vacío para ese número.                                           |
| 400    | `No se pudo resolver el destinatario +<tel>: <detalle>`                     | Falla en `contacts.resolvePhone` (rate limit, número mal formado, etc.).                                   |
| 401    | `Bearer token ausente` / `API key desconocida` / `Aplicacion deshabilitada` | Auth (ver arriba).                                                                                         |
| 500    | `Faltan TELEGRAM_API_ID / TELEGRAM_API_HASH en el .env`                     | El servidor no tiene las credenciales de la App de Telegram cargadas.                                      |
| 500    | `Canal '<slug>' no tiene 'telefono' cargado; no puedo ubicar la sesion.`    | Fila de `telegram_canales` sin `telefono`. Actualizar antes de reintentar.                                 |
| 500    | `Sesion del canal '<slug>' (+<tel>) no inicializada (falta session_<tel>/)...` | No hay sesión en disco para ese canal. Ver "Alta de un canal nuevo" abajo.                              |
| 502    | `Telegram rechazo el envio: <detalle>`                                      | `messages.sendMessage` tiró excepción (peer inválido, flood, cuenta bloqueada, etc.).                      |

---

## Alta de un canal nuevo

Flujo obligatorio (login **siempre en desarrollo**, prod solo consume):

1. **Alta en DB** — vía el Migrador DB o directamente:
   ```sql
   INSERT INTO telegram_canales (slug, nombre, telefono, habilitado, actualizado)
   VALUES ('operativa', 'Cuenta operativa', '5491100000000', '1', NOW());
   ```

2. **Login CLI en dev**:
   ```bash
   docker exec -it databox-apache php /var/www/api/v4/telegram/login.php --canal=operativa
   ```
   El script pide:
   - Número (ej. `+5491100000000`).
   - Código que llega por Telegram al mismo número.
   - Password 2FA (si la cuenta la tiene activada).

   Al terminar queda `api/v4/telegram/session_5491100000000/` persistido
   (el nombre del directorio viene del `telefono` de la fila).

3. **Deploy a prod**:
   ```bash
   bash scripts/deploy.sh
   ```
   El `deploy.sh` empaqueta con `tar` desde el filesystem local (no desde
   git), así que la carpeta `canales/<telefono>/session.madeline/` viaja aunque esté en
   `.gitignore`. El `entrypoint.sh` del contenedor ajusta permisos a
   `www-data` en el arranque.

4. **Test**:
   ```bash
   curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
     -H "Authorization: Bearer $APIKEY" \
     -H "Content-Type: application/json" \
     -d '{"canal_slug":"operativa","destinatario":"+549...","mensaje":"prueba"}'
   ```

### Re-login (sesión rota / expirada)

```bash
docker exec -it databox-apache php /var/www/api/v4/telegram/login.php --canal=<slug> --force
```

`--force` borra la sesión existente antes de re-loguear. Después: `deploy.sh`.

### Restricciones

- `login.php` se niega a correr si `APP_ENV=production` — es decisión de
  producto que **prod nunca inicie sesión** (la mano en el celular está en
  dev; prod solo recibe la sesión ya lista).
- No hay flujo HTTP para login (aunque MadelineProto lo permite via QR):
  querés que la mano en el celular sea deliberada.

---

## Ejemplos

### curl — enviar con canal explícito

```bash
curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "canal_slug":   "javier",
    "destinatario": "+542644984568",
    "mensaje":      "PRUEBA"
  }'
```

### curl — enviar sin canal (auto-pick del único habilitado)

```bash
curl -X POST https://api.databox.net.ar/v4/telegram/mensajes \
  -H "Authorization: Bearer $APIKEY" \
  -H "Content-Type: application/json" \
  -d '{
    "destinatario": "+542644984568",
    "mensaje":      "PRUEBA"
  }'
```

### curl — respuesta feliz

```json
{
  "ok": true,
  "data": {
    "canal": {
      "id":       1,
      "slug":     "javier",
      "nombre":   "Javier Alvarez (personal)",
      "telefono": "+541163219578"
    },
    "destinatario": "+542644984568",
    "mensaje":      "PRUEBA",
    "message_id":   1234,
    "fecha":        "2026-07-30 19:45:12"
  }
}
```

---

## Fuera del alcance

Este microservicio es intencionalmente mínimo: **envío + registro**. No hay:

- Consulta de estado por GET — el envío es síncrono, el resultado vive en la
  respuesta del POST. Para ver el histórico está el ABM del panel
  (`telegram_mensajes`, ver [Historial](#historial-en-telegram_mensajes)).
- Plantillas (`plantilla_id`) — se registra tal cual el `mensaje` del body.
- Reintentos automáticos: un envío fallido queda en `error` y no se
  reprograma solo.
- Adjuntos (fotos, docs). Se agrega con `messages.sendMedia` cuando
  aparezca la necesidad.
- Recepción de mensajes / respuestas.

---

## Referencias

- MadelineProto docs: <https://docs.madelineproto.xyz/docs/CREATING_A_CLIENT.html>.
- Credenciales de la App (`TELEGRAM_API_ID` / `TELEGRAM_API_HASH`): se
  generan en <https://my.telegram.org> y viven en `.env.production`.
- Migración de la tabla: [cloud/sql/migrations/20260730_2300_crear_telegram_canales.sql](../../../cloud/sql/migrations/20260730_2300_crear_telegram_canales.sql).
- Helper de login CLI: [api/v4/telegram/login.php](login.php).
- ABM Telegram del panel (lista `telegram_mensajes`): [cloud/api/telegrammensajes.php](../../../cloud/api/telegrammensajes.php).
- Worker de la cola (cliente de este endpoint, pasa `mensaje_id`): [cloud/api/lib/telegram_mensajes_enviar.php](../../../cloud/api/lib/telegram_mensajes_enviar.php).
- Encolador compartido (`encolarTelegramMensaje`): [cloud/api/lib/telegram_mensajes.php](../../../cloud/api/lib/telegram_mensajes.php).
