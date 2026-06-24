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
| Lenguaje        | PHP 8.2 (`pdo_mysql`)                               |
| Base de datos   | MySQL 8.0 (contenedor compartido `herramientas-mysql` en desarrollo, RDS en producción) |
| Frontend        | HTML + CSS + **JavaScript vanilla** (sin framework) |
| Iconografía     | Emojis + FontAwesome 6 (CDN)                        |
| Estilos         | CSS plano con variables — ver `DESIGN.md`           |
| Runtime         | Docker + docker-compose                             |
| Reverse proxy   | Nginx + certbot/Let's Encrypt (solo en producción)  |

**No usar:** Composer, Node/npm, bundlers (Vite/Webpack), frameworks JS
(React/Vue/Angular), frameworks CSS (Bootstrap/Tailwind), ORMs.
La regla es mantenerlo plano y sin build step: lo que está en disco es
exactamente lo que se sirve.

## 2. URLs y puertos

| Entorno      | URL                              | Puerto |
|--------------|----------------------------------|--------|
| Desarrollo   | http://localhost:8091            | 8091 fijo (igual interno y externo) |
| Producción   | https://cloud.databox.net.ar     | 8091 interno del contenedor, expuesto en 127.0.0.1:8091 y proxyado por Nginx |

**Regla de puerto:** databox usa SIEMPRE el `8091`. Igual interno
(Apache escucha `Listen 8091` dentro del contenedor) y externo
(host:contenedor mapean `8091:8091`). Igual en dev y en prod. No hay
selección dinámica: si el puerto está ocupado por otra cosa,
`instalar.sh` falla y hay que liberarlo.

La BD MySQL no la levanta este proyecto: se usa el contenedor compartido
`herramientas-mysql` (mysql:8.0 publicado en `3306` del host, levantado
por el repo `herramientas/`). Desde el contenedor PHP de databox se la
alcanza via `host.docker.internal` (mapeado a `host-gateway` en
`docker-compose.yml`); desde scripts del host se usa `docker exec
herramientas-mysql ...`.

En producción, el contenedor publica únicamente en `127.0.0.1:8091` y
Nginx (instalado y configurado por `scripts/aprovisionar_server.sh`)
hace de frente público en `cloud.databox.net.ar` con SSL emitido por
certbot. El servidor es `manchester.databox.net.ar` (Amazon Linux 2023,
usuario `ec2-user`).

## 3. Estructura de carpetas

```
databox/                        ← raíz del repositorio
├── .env.development            ← creds de la BD local (NO commitear)
├── .env.production             ← creds de RDS y APIs externas (NO commitear)
├── docker-compose.yml          ← stack de desarrollo (solo app; BD compartida en herramientas-mysql)
├── docker/
│   └── Dockerfile              ← php:8.2-apache + pdo_mysql + mod_rewrite
├── db/
│   └── schema.sql              ← fuente de verdad del esquema (ver ../CLAUDE.md)
├── scripts/                    ← operatoria (instalar, aprovisionar, deploy)
└── cloud/                      ← ESTA carpeta — DocumentRoot de Apache
    ├── index.php               ← SPA shell: layout + contenedor de vistas
    ├── api/                    ← endpoints PHP (un archivo por recurso)
    │   └── dashboard.php
    ├── assets/
    │   ├── css/style.css       ← un único CSS para toda la aplicación
    │   ├── js/app.js           ← un único JS para toda la aplicación
    │   └── img/
    ├── sql/                    ← (opcional) migraciones incrementales — ver §7
    ├── CLAUDE.md               ← instrucciones para Claude en esta carpeta
    ├── DESIGN.md               ← sistema de diseño visual
    └── STACK.md                ← este archivo
```

**Convenciones de carpetas:**

| Carpeta            | Qué va adentro                                                       |
|--------------------|----------------------------------------------------------------------|
| `cloud/api/`       | Un archivo PHP por recurso. Devuelve JSON. Maneja GET/POST/PUT/DELETE. |
| `cloud/assets/`    | CSS, JS, imágenes estáticas. Servidos directamente por Apache.       |
| `cloud/sql/`       | (Opcional) migraciones incrementales aplicadas por `instalar.sh`.    |
| `db/`              | `schema.sql` — fuente de verdad del esquema, cargado por MySQL al inicializar. |

## 4. Patrón SPA con un solo archivo

Cloud es una **Single Page Application** sin router pesado, sin
framework y sin build step:

- `index.php` contiene **el layout completo** (sidebar + topbar +
  contenedor de vistas) y delega el render del contenido a
  `assets/js/app.js`.

- En `assets/js/app.js` se intercepta la navegación del sidebar y se
  reemplaza el contenido del contenedor `#view` según la ruta pedida
  (hash routing: `#/dashboard`, etc.).

- Cada vista carga sus datos vía `fetch('api/<recurso>.php')` cuando
  se la muestra por primera vez (lazy load).

- No hay rutas server-side. El navegador siempre está en `index.php`.

**Ventajas:** deploy = copiar archivos, sin Node ni build, recarga
inmediata en desarrollo (volumen bind de Docker).
**Trade-off:** un `index.php` y un `app.js` que crecen. Está bien
mientras se respete la disciplina de una vista = un módulo claro
dentro de `app.js`.

Las URLs de assets en `index.php` incluyen `?v=<?= filemtime(...) ?>`
para forzar refresh del navegador cuando cambia el archivo en disco.

## 5. Autenticación

**Estado:** sin implementar todavía. El placeholder en el topbar
(`<button class="topbar-username">admin</button>`) y el item "Cerrar
sesión" del dropdown están deshabilitados a la espera del módulo de
auth. Hasta entonces, los endpoints en `api/` son públicos.

Cuando se implemente, el patrón esperado (alineado con otros proyectos
del grupo) será:

- JWT firmado, persistido en cookie `databox_token` (HttpOnly en prod).
- Cada endpoint protegido empieza con un `require_once` de un helper
  común que devuelve **401 JSON** si la petición pide JSON, o redirige
  a login si pide HTML.
- Bootstrap admin vía script único que crea el primer usuario y se
  borra después de usarlo.

No agregar autenticación sin coordinar el patrón antes — cambiarlo
después en todos los endpoints es caro.

## 6. Variables de entorno

Los `.env.*` viven en la raíz del repositorio (`databox/.env*`), no
dentro de `cloud/`:

| Archivo              | Para qué                                                    |
|----------------------|-------------------------------------------------------------|
| `.env.development`   | Credenciales del contenedor MySQL compartido `herramientas-mysql` (`host.docker.internal:3306`, `databox_dev`, root/root). |
| `.env.production`    | Credenciales de RDS y APIs externas (Causam, etc.).         |

**Cómo las consume el contenedor:**

- En **desarrollo**, `docker-compose.yml` setea `APP_ENV=development`
  como variable de entorno y bind-montea `./.env.development` en
  `/var/www/.env.development:ro` (para que PHP la pueda leer si en el
  futuro se agrega un loader).
- En **producción**, `docker-compose.prod.yml` (generado por
  `aprovisionar_server.sh`) usa `env_file: - .env.production`, así que
  Docker expone cada `KEY=VALUE` como variable de entorno del proceso
  PHP. `getenv('DB_HOST')` funciona directamente.

Constantes habituales:
`APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`API_CAUSAM_URL`, `API_CAUSAM_KEY`, `API_CAUSAM_SECRET`.

**Reglas:**
- Los `.env*` están en `.gitignore` y nunca se commitean.
- Ningún archivo PHP debe imprimir, loguear o devolver credenciales en
  respuestas, ni siquiera para debug.

## 7. Base de datos

- **Desarrollo:** MySQL 8.0 en el contenedor compartido
  **`herramientas-mysql`** (mysql:8.0, publicado en `3306` del host,
  levantado por el repo `herramientas/`). Desde el contenedor PHP de
  databox se la alcanza vía `host.docker.internal:3306` (gracias a
  `extra_hosts: host.docker.internal:host-gateway` en `docker-compose.yml`);
  desde scripts del host, vía `docker exec herramientas-mysql mysql ...`.
  Base `databox_dev`, usuario `root`, password `root` (ver `.env.development`).
- **Producción:** RDS MySQL externo
  (`oxford.c6q5xu8xpxfu.us-east-1.rds.amazonaws.com`), base `databox`,
  usuario `admin`. El `docker-compose.prod.yml` solo levanta el servicio
  de la app.
- **Esquema:** la fuente de verdad es `db/schema.sql` (en la raíz del
  repo). El `CLAUDE.md` raíz lo declara explícitamente como referencia
  obligatoria antes de escribir queries o tocar modelos.
- **Carga inicial del esquema:** se carga manualmente contra el contenedor
  compartido la primera vez que se monta el entorno de desarrollo:
  `docker exec -i herramientas-mysql mysql -uroot -proot databox_dev < db/schema.sql`.
- **Migraciones incrementales:** los archivos
  `cloud/sql/migrations/*.sql` se aplican por orden alfabético al final
  de `instalar.sh`, vía `docker exec herramientas-mysql ...` contra
  `databox_dev`. Deben ser **idempotentes** (chequear
  `information_schema` antes de cada `ALTER`), porque corren en cada
  rebuild. En prod, las migraciones se aplican manualmente contra RDS
  (ver `scripts/deploy.sh`).
- **No editar el esquema en prod a mano.** Cambios al schema: editar
  `db/schema.sql` (para entornos nuevos) **y** agregar un archivo
  numerado en `cloud/sql/migrations/` (para los ya inicializados).

## 8. Docker

Cloud se sirve dentro de la imagen `databox` (definida en
`docker/Dockerfile`: `php:8.2-apache` + `pdo_mysql` + `mod_rewrite`).

- En desarrollo (`docker-compose.yml`):
  - Solo el servicio `databox` con `DocumentRoot` por defecto del
    contenedor (`/var/www/html`), al que se bind-montea `./cloud/`.
  - `extra_hosts: host.docker.internal:host-gateway` para que el PHP
    del contenedor pueda llegar al MySQL compartido del host.
  - Puerto fijo `8091:8091`.
- En producción (`docker-compose.prod.yml`, generado en el servidor):
  - Solo el servicio `databox`, expuesto en `127.0.0.1:8091:8091`.
  - La BD es RDS (apuntada desde `.env.production`).
  - `env_file: .env.production`.
- El `Dockerfile` parchea `/etc/apache2/ports.conf` y el VirtualHost
  default para que Apache escuche en `8091` (no en 80). Esto garantiza
  que el puerto sea idéntico interno y externo.
- En producción además corre **Nginx** en el host como reverse proxy
  (no en Docker), terminando TLS con certbot. La configuración la
  genera `scripts/aprovisionar_server.sh`.

## 9. Deploy y operatoria

Cloud no tiene scripts propios — usa los scripts compartidos en
`scripts/` de la raíz del repositorio:

| Script                            | Para qué                                                                                   |
|-----------------------------------|--------------------------------------------------------------------------------------------|
| `scripts/instalar.sh`             | Rebuild idempotente del stack local. Elige puertos libres, recrea contenedores, aplica migraciones de `cloud/sql/migrations/`. |
| `scripts/aprovisionar.sh`         | Provisiona el servidor remoto desde cero (transfiere archivos + invoca aprovisionar_server.sh). |
| `scripts/aprovisionar_server.sh`  | Setup interno del server (Docker, Nginx, certbot, `docker-compose.prod.yml`). Lo dispara `aprovisionar.sh`. |
| `scripts/deploy.sh [--rebuild]`   | Despliegue incremental: sincroniza `cloud/`, `docker/`, `db/` y `.env.production` vía tar+SSH a `manchester.databox.net.ar` y recrea el contenedor. |

**Flujo de deploy de cloud (`scripts/deploy.sh`):**
1. Escribe `1.0.<timestamp>` en `cloud/version.txt` (placeholder — no
   hay banner consumiendo este valor todavía).
2. Tar de `cloud/`, `docker/`, `db/` y `.env.production` (excluyendo
   `.git`, `node_modules`, `vendor`, `*.log`, `*.pem`, `*.key`) →
   `ssh` a `manchester.databox.net.ar` → extrae en `/opt/app/databox/`.
3. `docker compose -f docker-compose.prod.yml up -d --force-recreate`
   en el servidor. `--force-recreate` es obligatorio: Docker bind-montea
   `.env.production` por inodo, así que al reemplazar el archivo hay
   que recrear el contenedor para que PHP lea el nuevo.
4. Las migraciones SQL contra RDS se aplican **manualmente** (el
   contenedor PHP no trae cliente mysql). Ver el comentario al final
   de `scripts/deploy.sh`.

## 10. Convenciones de código

- **PHP:** sin namespaces, sin Composer. Archivos sueltos en `cloud/api/`
  con `require_once` explícito si comparten helpers.
- **Respuestas API:** siempre JSON con la forma `{ok: true, data: …}`
  o `{ok: false, error: '…'}`.
- **Métodos HTTP:** GET para listar/leer, POST para crear, PUT para
  actualizar, DELETE para borrar.
- **Tiempo:** zona horaria `America/Argentina/Buenos_Aires` en PHP y
  `SET time_zone = '-03:00'` en la conexión MySQL.
- **Encoding:** UTF-8 en todo. Endpoints PHP usan
  `header('Content-Type: application/json; charset=utf-8')` y
  `json_encode($data, JSON_UNESCAPED_UNICODE)`.

## 11. Criterios de aceptación (qué tiene que ser cierto siempre)

1. **Sin build step.** No hay `node_modules`, no hay `vendor/`, no hay archivos generados.
2. **Un solo CSS y un solo JS** (`cloud/assets/css/style.css` y `cloud/assets/js/app.js`).
3. **`.env*` nunca se commitea, ni se loguea, ni se imprime.**
4. **El esquema vive en `db/schema.sql`** (fuente de verdad declarada en `../CLAUDE.md`). Cambios al schema en bases ya inicializadas se hacen vía `cloud/sql/migrations/*.sql` idempotentes.
5. **Cloud se sirve en https://cloud.databox.net.ar** (puerto interno 8091, proxyado por Nginx en `manchester.databox.net.ar`). El puerto `8091` es fijo y debe coincidir en dev, prod, interno y externo. Si en algún momento cambia el dominio, host o puerto, actualizar este archivo.
