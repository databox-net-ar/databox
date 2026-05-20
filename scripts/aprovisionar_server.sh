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
# Variables que recibe (opcionales, con default):
#   DOMAIN          - default cloud.databox.net.ar
#   CERTBOT_EMAIL   - default javieralvarez@databox.net.ar
# ============================================================

set -eo pipefail

APP_DIR="/opt/app/databox"
APP_PORT_HOST=8086
DOMAIN="${DOMAIN:-cloud.databox.net.ar}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-javieralvarez@databox.net.ar}"
COMPOSE_FILE="docker-compose.prod.yml"

echo ""
echo "============================================================"
echo "  Setup remoto databox (Amazon Linux 2023)"
echo "  Dominio: ${DOMAIN}"
echo "  App dir: ${APP_DIR}"
echo "============================================================"
echo ""

# ---- 1. Actualizar sistema ----
echo "[ 1/8 ] Actualizando sistema..."
sudo dnf update -y -q
echo "        OK"

# ---- 2. Instalar Docker, Git, Nginx, bind-utils, python3 ----
echo "[ 2/8 ] Instalando Docker, Nginx, bind-utils, python3..."
sudo dnf install -y -q docker git nginx bind-utils python3 python3-pip augeas-libs
sudo systemctl enable docker nginx
sudo systemctl start docker
sudo usermod -aG docker ec2-user
echo "        OK -- $(sudo docker --version)"

# ---- 3. Instalar Docker Compose v2 + buildx ----
echo "[ 3/8 ] Instalando Docker Compose y buildx..."
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
echo "[ 4/8 ] Verificando archivos del proyecto..."
for f in cloud docker/Dockerfile .env.production; do
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
#   - No incluye el servicio databox-db (en prod la BD es AWS RDS).
#   - Bind solo a 127.0.0.1 (Nginx hace el frente publico).
echo "[ 5/8 ] Generando $COMPOSE_FILE..."
cat > "$APP_DIR/$COMPOSE_FILE" << EOF
# Generado por scripts/aprovisionar_server.sh - no editar a mano.
# Produccion: sin servicio databox-db (BD en AWS RDS, ver .env.production).
services:
  databox:
    container_name: databox
    build:
      context: ./docker
      dockerfile: Dockerfile
    ports:
      - "127.0.0.1:${APP_PORT_HOST}:80"
    volumes:
      - ./cloud:/var/www/html
    env_file:
      - .env.production
    restart: unless-stopped
EOF
echo "        OK"

# ---- 6. Configurar Nginx ----
echo "[ 6/8 ] Configurando Nginx como reverse proxy..."
sudo tee /etc/nginx/conf.d/databox.conf > /dev/null << NGX
# Reverse proxy databox -- generado por aprovisionar_server.sh
server {
    listen 80;
    server_name ${DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${APP_PORT_HOST};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}
NGX

sudo rm -f /etc/nginx/conf.d/default.conf
sudo nginx -t
sudo systemctl restart nginx
echo "        OK"

# ---- 7. Construir imagen y levantar contenedor ----
echo "[ 7/8 ] Construyendo imagen Docker y levantando contenedor..."
cd "$APP_DIR"
sudo docker compose -f "$COMPOSE_FILE" build
sudo docker compose -f "$COMPOSE_FILE" up -d --force-recreate
sleep 3
sudo docker compose -f "$COMPOSE_FILE" ps
echo "        OK"

# ---- 8. Emitir certificado SSL ----
echo "[ 8/8 ] Verificando DNS para SSL..."

IMDS_TOKEN=$(curl -s -X PUT "http://169.254.169.254/latest/api/token" \
    -H "X-aws-ec2-metadata-token-ttl-seconds: 60" --max-time 3 || echo "")
PUBLIC_IP=$(curl -s -H "X-aws-ec2-metadata-token: $IMDS_TOKEN" \
    --max-time 3 http://169.254.169.254/latest/meta-data/public-ipv4 || echo "")

if [ -z "$PUBLIC_IP" ]; then
    echo "        AVISO: no se pudo detectar la IP publica -- saltando SSL."
else
    echo "        IP publica del servidor: $PUBLIC_IP"

    RESOLVED=$(dig +short A "$DOMAIN" @8.8.8.8 | tail -n1)
    if [ "$RESOLVED" != "$PUBLIC_IP" ]; then
        echo "        DNS aun no apunta al servidor:"
        echo "          $DOMAIN -> ${RESOLVED:-(no resuelve)} (esperado $PUBLIC_IP)"
        echo ""
        echo "        Configurar DNS y volver a correr este script para SSL."
    else
        echo "        DNS OK. Verificando certbot..."

        if [ ! -x /opt/certbot/bin/certbot ]; then
            echo "        Instalando certbot en /opt/certbot..."
            sudo python3 -m venv /opt/certbot
            sudo /opt/certbot/bin/pip install --quiet --upgrade pip
            sudo /opt/certbot/bin/pip install --quiet certbot certbot-nginx
            sudo ln -sf /opt/certbot/bin/certbot /usr/bin/certbot
        fi
        echo "        certbot $(/usr/bin/certbot --version 2>&1 | awk '{print $2}')"

        echo "        Emitiendo/verificando certificado para $DOMAIN..."
        if sudo certbot --nginx \
                --non-interactive \
                --agree-tos \
                --email "$CERTBOT_EMAIL" \
                --redirect \
                --keep-until-expiring \
                -d "$DOMAIN"; then
            echo "        OK -- SSL configurado."
        else
            echo "        AVISO: certbot fallo. Revisar /var/log/letsencrypt/letsencrypt.log"
        fi

        if [ ! -f /etc/cron.d/certbot ]; then
            echo "0 0,12 * * * root /opt/certbot/bin/python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew -q" \
                | sudo tee /etc/cron.d/certbot > /dev/null
            echo "        Cron de renovacion creado en /etc/cron.d/certbot"
        fi
    fi
fi

echo ""
echo "============================================================"
echo "  Setup remoto completo."
echo ""
echo "  App:        https://${DOMAIN}/   (proxy a 127.0.0.1:${APP_PORT_HOST})"
echo "  Repo:       $APP_DIR"
echo "  Compose:    docker compose -f $APP_DIR/$COMPOSE_FILE <cmd>"
echo "  Logs:       sudo docker logs -f databox"
echo "  Restart:    cd $APP_DIR && sudo docker compose -f $COMPOSE_FILE restart"
echo "  Ver SSL:    sudo certbot certificates"
echo "============================================================"
echo ""
