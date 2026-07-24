#!/bin/bash
# ============================================================
# aprovisionar_server.sh - Setup interno del server databox.
#
# Este script NO se corre a mano: lo invoca scripts/aprovisionar.sh
# despues de transferir los archivos del proyecto via SSH. Si necesitas
# re-correr el setup en el server (idempotente), podes ejecutarlo
# directamente:
#   bash /opt/app/databox/scripts/aprovisionar_server.sh
#
# Sistema esperado: Amazon Linux 2023.
#
# Este proyecto es el DUENIO del reverse proxy publico del stack databox:
#   - cloud.databox.net.ar  -> 127.0.0.1:8091  (contenedor cloud)
#   - www.databox.net.ar    -> 127.0.0.1:8113  (contenedor www)
#   - admin.databox.net.ar  -> 127.0.0.1:8300  (contenedor legacy databox-admin)
#   - api.databox.net.ar:
#       /v1|v2|v3|robot     -> 127.0.0.1:8301  (contenedor legacy databox-api)
#       /* (default)        -> 127.0.0.1:8114  (contenedor cloud, vhost api nuevo)
#
# Los contenedores del proyecto databox_legacy (:8300 y :8301) los levanta
# aquel repo en su propio aprovisionar_server.sh; aca solo hacemos proxy
# por loopback. Los certificados SSL de los 4 dominios los emite este
# script (unica fuente de verdad).
#
# Variables que recibe (opcionales, con default):
#   DOMAIN          - default cloud.databox.net.ar
#   WWW_DOMAIN      - default www.databox.net.ar
#   ADMIN_DOMAIN    - default admin.databox.net.ar
#   API_DOMAIN      - default api.databox.net.ar
#   CERTBOT_EMAIL   - default javieralvarez@databox.net.ar
# ============================================================

set -eo pipefail

APP_DIR="/opt/app/databox"
APP_PORT=8091
WWW_PORT=8113
API_PORT=8114
# Puertos del contenedor legacy (databox_legacy-apache). Los binds los
# gestiona aquel repo; aca solo hacemos proxy_pass por loopback.
LEGACY_ADMIN_PORT=8300
LEGACY_API_PORT=8301

DOMAIN="${DOMAIN:-cloud.databox.net.ar}"
WWW_DOMAIN="${WWW_DOMAIN:-www.databox.net.ar}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-admin.databox.net.ar}"
API_DOMAIN="${API_DOMAIN:-api.databox.net.ar}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-javieralvarez@databox.net.ar}"
COMPOSE_FILE="docker-compose.prod.yml"

echo ""
echo "============================================================"
echo "  Setup remoto databox (Amazon Linux 2023)"
echo "  Dominios: ${DOMAIN}, ${WWW_DOMAIN}, ${ADMIN_DOMAIN}, ${API_DOMAIN}"
echo "  App dir: ${APP_DIR}"
echo "============================================================"
echo ""

# ---- 1. Actualizar sistema ----
echo "[ 1/9 ] Actualizando sistema..."
sudo dnf update -y -q
echo "        OK"

# ---- 2. Instalar Docker, Git, Nginx, bind-utils, python3 ----
echo "[ 2/9 ] Instalando Docker, Nginx, bind-utils, python3..."
sudo dnf install -y -q docker git nginx bind-utils python3 python3-pip augeas-libs
sudo systemctl enable docker nginx
sudo systemctl start docker
sudo usermod -aG docker ec2-user
echo "        OK -- $(sudo docker --version)"

# ---- 3. Instalar Docker Compose v2 + buildx ----
echo "[ 3/9 ] Instalando Docker Compose y buildx..."
sudo mkdir -p /usr/local/lib/docker/cli-plugins

COMPOSE_VERSION="v2.32.4"
sudo curl -fsSL \
    "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
    -o /usr/local/lib/docker/cli-plugins/docker-compose
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

BUILDX_VERSION="v0.20.0"
sudo curl -fsSL \
    "https://github.com/docker/buildx/releases/download/${BUILDX_VERSION}/buildx-${BUILDX_VERSION}.linux-amd64" \
    -o /usr/local/lib/docker/cli-plugins/docker-buildx
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-buildx

echo "        OK -- Compose $(sudo docker compose version --short) / buildx $(sudo docker buildx version | awk '{print $2}')"

# ---- 4. Verificar artefactos transferidos ----
echo "[ 4/9 ] Verificando archivos del proyecto..."
for f in cloud api docker/Dockerfile env.php .env.production; do
    if [ ! -e "$APP_DIR/$f" ]; then
        echo "        ERROR: falta $APP_DIR/$f"
        echo "        Re-correr scripts/aprovisionar.sh desde la maquina local."
        exit 1
    fi
done
# Override de compose es solo para dev local: si llego, lo borramos.
rm -f "$APP_DIR/docker-compose.override.yml"
echo "        OK"

# ---- 5. Generar docker-compose.prod.yml ----
# Difiere del docker-compose.yml del repo:
#   - Bind solo a 127.0.0.1 (Nginx hace el frente publico).
#   - Sin extra_hosts host.docker.internal (en prod la BD es RDS).
# Mismos puertos que dev, igual interno y externo.
echo "[ 5/9 ] Generando $COMPOSE_FILE..."
cat > "$APP_DIR/$COMPOSE_FILE" << EOF
# Generado por scripts/aprovisionar_server.sh - no editar a mano.
# Produccion: BD en AWS RDS (ver .env.production).
services:
  databox:
    container_name: databox-apache
    build:
      # context = raiz del repo (igual que dev). El Dockerfile hace
      # COPY docker/entrypoint.sh, asi que necesita ver la carpeta docker/.
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "127.0.0.1:${APP_PORT}:${APP_PORT}"
      - "127.0.0.1:${WWW_PORT}:${WWW_PORT}"
      - "127.0.0.1:${API_PORT}:${API_PORT}"
    volumes:
      - ./cloud:/var/www/html
      - ./www:/var/www/www
      - ./robot:/var/www/robot
      - ./api:/var/www/api
      - ./env.php:/var/www/env.php:ro
      - ./.env.production:/var/www/.env.production:ro
      # Crontab del worker Robot (tareas programadas contra /var/www/robot/*.php).
      # Editable desde el "Editor de cron" del panel. El entrypoint le ajusta
      # permisos al arrancar para que cron lo acepte y para que www-data pueda
      # re-escribirlo desde el endpoint del panel.
      - ./robot/crontab:/etc/cron.d/databox
      # Crontab del "Programador de tareas" del panel cloud (scheduler minutal
      # + cleanup + rotacion). Archivo estatico versionado en el repo -- las
      # tareas concretas viven en la tabla \`tareas\` y se administran desde
      # el back office. Se monta en una ruta neutra y el entrypoint lo COPIA
      # a /etc/cron.d/databox-cloud como root:root 644 -- Docker no permite
      # chown sobre un bind mount desde adentro del contenedor, y cron termina
      # rechazando archivos con ownership no-root (incidente prod 2026-07-23).
      - ./cloud/jobs/crontab:/opt/databox/crontab_cloud_source:ro
      # Certificados mTLS para API Kite (Movistar). Carpeta gitignored pero
      # subida por aprovisionar.sh / deploy.sh desde ./certs local (deben estar
      # el .pfx + los PEM ya extraidos con openssl -legacy, ver STACK / .env).
      - ./certs:/var/www/certs
    env_file:
      - .env.production
    restart: unless-stopped
EOF
echo "        OK"

# ---- 6. Configurar Nginx ----
# Server blocks en databox.conf:
#   1. ${DOMAIN} (cloud):     proxy a 127.0.0.1:${APP_PORT}
#   2. ${WWW_DOMAIN} (www):   proxy a 127.0.0.1:${WWW_PORT}
#   3. ${ADMIN_DOMAIN}:       proxy a 127.0.0.1:${LEGACY_ADMIN_PORT} (contenedor legacy)
#   4. ${API_DOMAIN}:         /v1|v2|v3|robot -> 127.0.0.1:${LEGACY_API_PORT} (legacy)
#                             /*              -> 127.0.0.1:${API_PORT}         (nuevo)
#   5. default_server :80  -> 404
# El default_server :443 (fallback HTTPS -> 404 reusando el cert de cloud) se
# escribe en un archivo aparte (databox-default-ssl.conf) despues del certbot,
# porque `listen 443 ssl` sin cert rompe `nginx -t`.
#
# Antes de escribir, aborta si algun otro .conf de /etc/nginx/ (ajeno a este
# proyecto y a databox_legacy.conf, que ya fue migrado y borrado) declara
# alguno de los 4 dominios. Evita colisiones silenciosas de server_name.
echo "[ 6/9 ] Configurando Nginx como reverse proxy..."

OUR_CONF="/etc/nginx/conf.d/databox.conf"
LEGACY_CONF="/etc/nginx/conf.d/databox_legacy.conf"

for dom in "$DOMAIN" "$WWW_DOMAIN" "$ADMIN_DOMAIN" "$API_DOMAIN"; do
    COLLISION=$(sudo grep -rl -E "server_name[[:space:]]+[^;]*\b${dom//./\\.}\b" \
                    /etc/nginx/conf.d /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null \
                | grep -v "^${OUR_CONF}\$" \
                | grep -v "^${LEGACY_CONF}\$" || true)
    if [ -n "$COLLISION" ]; then
        echo "        ERROR: ya hay vhost(s) para $dom en:"
        echo "$COLLISION" | sed 's/^/          /'
        echo "        Resolver el conflicto a mano antes de re-correr."
        exit 1
    fi
done

sudo tee "$OUR_CONF" > /dev/null << NGX
# Reverse proxy databox -- generado por aprovisionar_server.sh
# NO editar a mano: se regenera en cada corrida.

# --- ${DOMAIN} (cloud) ---
server {
    listen 80;
    server_name ${DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${APP_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# --- ${WWW_DOMAIN} (www) ---
server {
    listen 80;
    server_name ${WWW_DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${WWW_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# --- ${ADMIN_DOMAIN} (admin legacy, contenedor databox_legacy-apache) ---
server {
    listen 80;
    server_name ${ADMIN_DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${LEGACY_ADMIN_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        # Backend Apache detras del proxy corre en :8300; sin este redirect
        # cualquier Location absoluto que emita (p.ej. mod_dir DirectorySlash)
        # se filtra a "http://admin.databox.net.ar:8300/...". Lo convertimos
        # a relativo y nginx lo re-expande contra la URL publica.
        proxy_redirect     ~^https?://[^/]+(/.*)\$ \$1;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# --- ${API_DOMAIN} (split por path: v1|v2|v3 -> legacy, resto -> nuevo) ---
server {
    listen 80;
    server_name ${API_DOMAIN};

    # v1, v2, v3 y robot -> contenedor legacy databox_legacy-apache (:${LEGACY_API_PORT}).
    # El regex matchea "/v1", "/v1/...", "/v2", "/robot", "/robot/foo", etc. y NO
    # matchea "/v10" ni "/v4" ni "/robotics".
    location ~ ^/(v1|v2|v3|robot)(/|\$) {
        proxy_pass         http://127.0.0.1:${LEGACY_API_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        proxy_redirect     ~^https?://[^/]+(/.*)\$ \$1;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }

    # Todo lo demas (incluida raiz "/", v4, v5, y futuras versiones -- excepto
    # v1/v2/v3/robot que ya matchearon arriba) -> api nuevo en el contenedor
    # databox-apache (:${API_PORT}).
    location / {
        proxy_pass         http://127.0.0.1:${API_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# --- default_server: hostname desconocido -> HTTP 404 ---
server {
    listen 80 default_server;
    server_name _;
    return 404;
}
NGX

sudo rm -f /etc/nginx/conf.d/default.conf
# databox_legacy.conf ya no existe (el proyecto legacy lo borra en su
# aprovisionar_server.sh), pero si sobrevivio a algun rollback lo limpiamos
# aca tambien para no dejar server blocks fantasma.
sudo rm -f "$LEGACY_CONF"
sudo nginx -t
sudo systemctl restart nginx
echo "        OK"

# ---- 7. Construir imagen y levantar contenedor ----
echo "[ 7/9 ] Construyendo imagen Docker y levantando contenedor..."
cd "$APP_DIR"
sudo docker compose -f "$COMPOSE_FILE" build
sudo docker compose -f "$COMPOSE_FILE" up -d --force-recreate
sleep 3
sudo docker compose -f "$COMPOSE_FILE" ps
echo "        OK"

# ---- 8. Emitir certificado SSL ----
# Sin pre-chequeo de DNS: certbot tiene sus propias verificaciones y reporta
# errores claros. El pre-chequeo viejo (`dig vs IMDS public-ipv4`) saltaba SSL
# en setups con CDN/proxy o IPs efimeras, dejando HTTPS sin configurar.
echo "[ 8/9 ] Configurando SSL con certbot..."

if [ ! -x /opt/certbot/bin/certbot ]; then
    echo "        Instalando certbot en /opt/certbot..."
    sudo python3 -m venv /opt/certbot
    sudo /opt/certbot/bin/pip install --quiet --upgrade pip
    sudo /opt/certbot/bin/pip install --quiet certbot certbot-nginx
    sudo ln -sf /opt/certbot/bin/certbot /usr/bin/certbot
fi
echo "        certbot $(/usr/bin/certbot --version 2>&1 | awk '{print $2}')"

echo "        Emitiendo/renovando certificado para $DOMAIN, $WWW_DOMAIN, $ADMIN_DOMAIN y $API_DOMAIN..."
if ! sudo certbot --nginx \
        --non-interactive \
        --agree-tos \
        --email "$CERTBOT_EMAIL" \
        --redirect \
        --keep-until-expiring \
        --expand \
        -d "$DOMAIN" \
        -d "$WWW_DOMAIN" \
        -d "$ADMIN_DOMAIN" \
        -d "$API_DOMAIN"; then
    echo ""
    echo "        ERROR: certbot fallo. Ultimas lineas del log:"
    echo "        --------------------------------------------"
    sudo tail -40 /var/log/letsencrypt/letsencrypt.log 2>/dev/null | sed 's/^/        /'
    echo "        --------------------------------------------"
    echo ""
    echo "        Causas comunes:"
    echo "          - Alguno de los dominios no apunta a la IP publica de este server."
    echo "          - El Security Group del EC2 no tiene abierto el puerto 80"
    echo "            (HTTP-01 challenge entra por 80, no por 443)."
    echo "          - Limite de rate de Let's Encrypt (5 fallos/hora por dominio)."
    exit 1
fi
echo "        OK -- certificado emitido/renovado para los 4 dominios."

if [ ! -f /etc/cron.d/certbot ]; then
    echo "0 0,12 * * * root /opt/certbot/bin/python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew -q" \
        | sudo tee /etc/cron.d/certbot > /dev/null
    echo "        Cron de renovacion creado en /etc/cron.d/certbot"
fi

# ---- 9. default_server :443 -> 404 ----
# Fallback HTTPS: cualquier hostname desconocido sobre :443 devuelve 404.
# Reusa el cert de $DOMAIN (unico que tenemos); un SNI que no matchee $DOMAIN
# va a hacer que el navegador muestre warning de cert antes del 404.
# Se escribe recien despues del certbot porque `listen 443 ssl` sin cert
# rompe `nginx -t`.
echo "[ 9/9 ] Configurando default_server :443 -> 404..."
# El cert que cubre $DOMAIN puede vivir en /etc/letsencrypt/live/otro-dominio/
# cuando certbot expande un lineage existente en vez de crear uno nuevo (p.ej.
# si un cert previo ya tenia certname `admin.databox.net.ar`, `--expand` sigue
# guardando ahi aunque ahora tambien cubra cloud/www/api). Preguntamos a
# certbot por el path real en vez de asumir el directorio por nombre.
DOMAIN_CERT=$(sudo certbot certificates --domain "$DOMAIN" 2>/dev/null \
              | awk '/Certificate Path:/ {print $3; exit}')
DOMAIN_KEY=$(sudo certbot certificates --domain "$DOMAIN" 2>/dev/null \
             | awk '/Private Key Path:/ {print $4; exit}')
if [ -n "$DOMAIN_CERT" ] && [ -n "$DOMAIN_KEY" ] && sudo test -f "$DOMAIN_CERT" && sudo test -f "$DOMAIN_KEY"; then
    sudo tee /etc/nginx/conf.d/databox-default-ssl.conf > /dev/null << NGX443
# default_server :443: hostname desconocido sobre HTTPS -> 404.
# Reusa el cert de ${DOMAIN}; el navegador puede mostrar warning por SNI
# mismatch antes de aceptar. Escrito por aprovisionar_server.sh despues del
# certbot para que \`nginx -t\` no falle por falta de cert.
server {
    listen 443 ssl default_server;
    server_name _;
    ssl_certificate     ${DOMAIN_CERT};
    ssl_certificate_key ${DOMAIN_KEY};
    return 404;
}
NGX443
    sudo nginx -t && sudo systemctl reload nginx
    echo "        OK -- HTTPS a hostname desconocido devuelve 404."
else
    echo "        AVISO: no hay cert para $DOMAIN -- HTTPS a hostname desconocido"
    echo "        cae en el primer server SSL (deberia haber emitido en el paso previo)."
    sudo rm -f /etc/nginx/conf.d/databox-default-ssl.conf
fi

# Verificacion: Nginx debe haber quedado con un socket bindeado en :443.
# Usamos `ss` en vez de `nginx -T | grep listen` -- este ultimo se comporta
# raro bajo `set -eo pipefail` cuando corre por SSH: `2>/dev/null` sobre `sudo`
# puede tirar exit != 0 antes del grep y dispara el ERROR aun cuando la config
# tiene decenas de `listen 443 ssl`. `ss` es directo: mira el kernel, no la config.
if ! sudo ss -tln 2>/dev/null | grep -q ':443\b'; then
    echo "        ERROR: Nginx no quedo escuchando en 443 despues de certbot."
    echo "        Revisar /etc/nginx/conf.d/databox.conf"
    exit 1
fi

# Smoke test interno (loopback): forzamos que $DOMAIN resuelva a 127.0.0.1
# para que SNI matche el cert. Si esto falla, Nginx local esta mal.
local_code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 \
    --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || echo "000")
echo "        Smoke test interno: https://$DOMAIN/ -> $local_code"
if [ "$local_code" = "000" ]; then
    echo "        ERROR: Nginx no responde por 443 ni en loopback."
    exit 1
fi

echo ""
echo "============================================================"
echo "  Setup remoto completo."
echo ""
echo "  App:        https://${DOMAIN}/         (proxy a 127.0.0.1:${APP_PORT})"
echo "  Www:        https://${WWW_DOMAIN}/         (proxy a 127.0.0.1:${WWW_PORT})"
echo "  Admin:      https://${ADMIN_DOMAIN}/   (proxy a 127.0.0.1:${LEGACY_ADMIN_PORT}, contenedor legacy)"
echo "  Api:        https://${API_DOMAIN}/"
echo "                /v1|v2|v3|robot  -> 127.0.0.1:${LEGACY_API_PORT}  (contenedor legacy)"
echo "                /*               -> 127.0.0.1:${API_PORT}  (contenedor cloud, vhost api nuevo)"
echo "  Fallback:   hostname desconocido (:80 y :443) -> HTTP 404"
echo "  Repo:       $APP_DIR"
echo "  Compose:    docker compose -f $APP_DIR/$COMPOSE_FILE <cmd>"
echo "  Logs:       sudo docker logs -f databox-apache"
echo "  Restart:    cd $APP_DIR && sudo docker compose -f $COMPOSE_FILE restart"
echo "  Ver SSL:    sudo certbot certificates"
echo "============================================================"
echo ""
