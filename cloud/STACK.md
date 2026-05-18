# Stack técnico — Cloud

Este archivo describe el stack y la arquitectura de la aplicación
**cloud** (panel de administración de Databox, plataforma de servicios
digitales: correo masivo, WhatsApp masivo, etc.). Aplica solo a esta
carpeta; no documenta otras aplicaciones del repositorio.

---

## 1. Tecnologías

| Capa            | Tecnología                                          |
|-----------------|-----------------------------------------------------|
| Servidor web    | Apache 2.4 (mod_rewrite habilitado)                 |
| Lenguaje        | PHP 8.2 (`pdo_mysql`, `gd` con jpeg/webp/freetype)  |
| Base de datos   | MySQL 8.0 (Docker en desarrollo, RDS en producción) |
| Frontend        | HTML + CSS + **JavaScript vanilla** (sin framework) |
| Iconografía     | Emojis + FontAwesome 6 (CDN)                        |
| Estilos         | CSS plano con variables — ver `DESIGN.md`           |
| Runtime         | Docker + docker-compose                             |
| Mapas           | Google Maps JS API (key en tabla `configuracion`)   |

**No usar:** Composer, Node/npm, bundlers (Vite/Webpack), frameworks JS
(React/Vue/Angular), frameworks CSS (Bootstrap/Tailwind), ORMs.
La regla es mantenerlo plano y sin build step: lo que está en disco es
exactamente lo que se sirve.

## 2. URLs y puertos

| Entorno      | URL                              | Puerto |
|--------------|----------------------------------|--------|
| Desarrollo   | http://localhost:8086            | 8086   |
| Producción   | https://cloud.databox.net.ar     | 8086 interno (HTTPS terminado por reverse proxy) |

El virtual host de cloud está definido en `docker/apache.conf` con
`DocumentRoot /opt/app/databox/cloud` y escucha en el puerto **8086**.
El servidor de producción es `seattle.databox.net.ar` (Amazon Linux 2023,
usuario `ec2-user`).

## 3. Estructura de carpetas

```
cloud/
├── index.php          ← SPA shell: layout + contenedor de vistas
├── api/               ← endpoints PHP propios de cloud
│   ├── bootstrap.php  ← arranque común (config, conexión, helpers)
│   └── … (un archivo PHP por recurso)
├── assets/
│   ├── css/style.css  ← un único CSS para toda la aplicación
│   ├── js/app.js      ← un único JS para toda la aplicación
│   └── img/
├── sql/               ← scripts SQL del esquema (migraciones)
├── CLAUDE.md          ← instrucciones para Claude en esta carpeta
├── DESIGN.md          ← sistema de diseño visual
├── STACK.md           ← este archivo
└── README.md
```

**Convenciones de carpetas:**

| Carpeta   | Qué va adentro                                                          |
|-----------|-------------------------------------------------------------------------|
| `api/`    | Un archivo PHP por recurso. Devuelve JSON. Maneja GET/POST/PUT/DELETE.   |
| `assets/` | CSS, JS, imágenes estáticas. Servidos directamente por Apache.           |
| `sql/`    | Scripts SQL del esquema y migraciones.                                   |

## 4. Patrón SPA con un solo archivo

Cloud es una **Single Page Application** sin router pesado, sin
framework y sin build step:

- `index.php` contiene **el layout completo** (sidebar + topbar +
  contenedor de vistas) y delega el render del contenido a
  `assets/js/app.js`.

- En `assets/js/app.js` se intercepta la navegación del sidebar y se
  reemplaza el contenido del contenedor `#view` según la ruta pedida
  (hash routing: `#/dashboard`, `#/devices`, etc.).

- Cada vista carga sus datos vía `fetch('api/<recurso>.php')` cuando
  se la muestra por primera vez (lazy load).

- No hay rutas server-side. El navegador siempre está en `index.php`.

**Ventajas:** deploy = copiar archivos, sin Node ni build, recarga
inmediata en desarrollo (volumen bind de Docker).
**Trade-off:** un `index.php` y un `app.js` que crecen. Está bien
mientras se respete la disciplina de una vista = un módulo claro
dentro de `app.js`.

## 5. Autenticación

- JWT firmado en `lib/jwt.php`.
- Token persistido en la cookie `databox_token` (path `/`, HttpOnly en prod).
- Cada endpoint protegido empieza con:
  ```php
  require_once __DIR__ . '/../lib/auth_check.php';
  requireAuth();
  ```
- `requireAuth()` devuelve **401 JSON** si la petición pide JSON;
  redirige a `login.php` si pide HTML.
- `authUser()` devuelve el payload del token o `null`.
- `setup.php` crea el primer usuario admin y **debe borrarse después
  de usarlo** (verifica que la tabla `usuarios` esté vacía).

## 6. Variables de entorno

Cloud **no** lee variables de entorno por su cuenta. Las consume a
través de `api/config/secrets.php` (en la raíz del repositorio padre),
que carga `.env.development` o `.env.production` según `APP_ENV`.

`APP_ENV` lo setea el contenedor Docker:
- En desarrollo: `APP_ENV=development` → lee `.env.development` (MySQL local en Docker, base `databox_dev`).
- En producción: `APP_ENV=production` → lee `.env.production` (RDS, base `databox`).

Constantes que cloud usa habitualmente (definidas por `secrets.php`):
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`,
`MEDIA_BASE_URL`, `S3_BUCKET`.

**Reglas:**
- Los `.env.*` están en `.gitignore` y nunca se commitean.
- Cualquier archivo que necesite credenciales hace `require_once` del
  loader, nunca lee el `.env` directo.

## 7. Base de datos

- **Desarrollo:** MySQL 8.0 en un contenedor llamado `databox-mysql` (lo
  levanta `docker-compose.override.yml`). Host `mysql` desde el
  contenedor PHP, `localhost:3306` desde Windows. Base `databox_dev`,
  usuario `root`, password `123`.
- **Producción:** RDS MySQL externo
  (`california.ccfymgq888f0.us-east-1.rds.amazonaws.com`), base `databox`.
- **Esquema:** los `CREATE TABLE` y `ALTER` están centralizados en
  `scripts/migrate.php` (en la raíz del repo). Es idempotente: cada
  statement está en try/catch, así correrlo dos veces es seguro.
- **Configuración runtime:** valores que cambian sin redeploy (claves
  de APIs, parámetros editables) viven en la tabla `configuracion` y
  se leen con `getConfigValue('clave')` desde `api/config/db.php`.

## 8. Docker

Cloud se sirve dentro de la imagen `php-apache` (definida en el
`Dockerfile` de la raíz: `php:8.2-apache` + `pdo_mysql` + `gd` + `mod_rewrite`).

- El virtual host de cloud está en `docker/apache.conf`:
  `<VirtualHost *:8086> DocumentRoot /opt/app/databox/cloud </VirtualHost>`.
- El puerto 8086 está abierto en `docker/ports.conf` (`Listen 8086`).
- En `docker-compose.yml` la carpeta `cloud/` se monta como volumen
  bind: los cambios en el código se ven al instante, sin rebuild.

## 9. Deploy y operatoria

Cloud no tiene scripts propios — usa los scripts compartidos de la
raíz del repositorio:

| Script                          | Para qué                                                              |
|---------------------------------|------------------------------------------------------------------------|
| `scripts/deploy.sh [--rebuild]` | Sube cloud (y resto) al servidor de producción vía tar + SSH.          |
| `scripts/rebuild_local.sh`      | Recrea el contenedor local (al cambiar Dockerfile o compose).          |
| `scripts/migrate.php`           | Aplica el esquema de la BD. Idempotente. Lo corre el deploy al final.  |

**Flujo de deploy de cloud:**
1. `scripts/deploy.sh` escribe `1.0.<timestamp>` en `cloud/version.txt`.
2. Tar de `cloud/` (excluyendo `.git`, `.vscode`, `node_modules`, `*.log`, `*.pem`, `*.key`) → SSH a `seattle.databox.net.ar` → extrae en `/opt/app/databox/cloud/`.
3. `docker compose up -d --force-recreate` en el servidor. `--force-recreate` es obligatorio: Docker bind-montea `.env.production` por inodo, y al reemplazarlo hay que recrear el contenedor para que PHP lea el nuevo.
4. `docker compose exec -T php-apache php /opt/app/databox/scripts/migrate.php` aplica migraciones.

**Banner de nueva versión:** el frontend pollea `api/version` y
muestra `.version-banner` si el `version.txt` del servidor cambió
respecto al que cargó al iniciar. El `style.css` se incluye con
`?v=<?= time() ?>` para evitar caché.

## 10. Convenciones de código

- **PHP:** sin namespaces, sin Composer. Archivos sueltos en `api/` y `lib/` con `require_once` explícito.
- **Respuestas API:** siempre JSON con la forma `{ok: true, data: …}` o `{ok: false, error: '…'}`.
- **Métodos HTTP:** GET para listar/leer, POST para crear, PUT para actualizar, DELETE para borrar.
- **Tiempo:** zona horaria fijada en `America/Argentina/Buenos_Aires` (en `db.php`) y `SET time_zone = '-03:00'` en la conexión.
- **Imágenes:** lo grueso (subida, recorte, búsqueda EAN) está en `lib/imagen.php` y endpoints `api/upload*.php`, `api/buscar_imagenes.php`, `api/recortar_imagen.php`.

## 11. Criterios de aceptación (qué tiene que ser cierto siempre)

1. **Sin build step.** No hay `node_modules`, no hay `vendor/`, no hay archivos generados.
2. **Un solo CSS y un solo JS** (`assets/css/style.css` y `assets/js/app.js`).
3. **`.env.*` nunca se commitea, ni se loguea, ni se imprime.**
4. **Cada endpoint en `api/` valida con `requireAuth()`** salvo los explícitamente públicos (login, version).
5. **El esquema se modifica solo agregando a `scripts/migrate.php`** — nada de SQL manual en producción.
6. **`version.txt` lo actualiza el deploy**, nadie lo edita a mano.
7. **Cloud se sirve en https://cloud.databox.net.ar** (puerto interno 8086). Si en algún momento cambia el dominio o el puerto, actualizar este archivo.
