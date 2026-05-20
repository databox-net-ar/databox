#!/bin/bash
# ============================================================
# aprovisionar.sh - Aprovisionamiento del servidor databox.
#
# Corre desde la maquina local (Git Bash en Windows). Transfiere
# por SSH los archivos del proyecto al servidor y ejecuta alli el
# script aprovisionar_server.sh, que instala Docker/Nginx/certbot,
# configura el reverse proxy y levanta la app.
#
# Pensado para una instalacion limpia, pero es idempotente: se
# puede volver a correr sobre un server ya instalado.
#
# Uso:
#   bash scripts/aprovisionar.sh
# ============================================================

set -e

HOST="manchester.databox.com.ar"
USER="ec2-user"
KEY="/c/Users/Javier/OneDrive/Temp/Llaves/wescom/wescom.pem"
BASE_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
BASE_REMOTE="/opt/app/databox"
DOMAIN="cloud.databox.net.ar"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-javieralvarez@databox.net.ar}"

echo ""
echo "============================================================"
echo "  Aprovisionamiento servidor databox"
echo "  Host:   $HOST"
echo "  Dest:   $BASE_REMOTE"
echo "  URL:    https://${DOMAIN}/"
echo "============================================================"
echo ""

# ---- 1. Validar artefactos locales ----
for f in .env.production docker/Dockerfile cloud scripts/aprovisionar_server.sh; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

if [ ! -f "$KEY" ]; then
    echo "ERROR: no se encuentra la llave SSH en $KEY"
    exit 1
fi

# ---- 2. Asegurar destino remoto ----
echo "  Preparando $BASE_REMOTE en el servidor..."
ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
    "sudo mkdir -p '$BASE_REMOTE' && sudo chown -R $USER:$USER /opt/app"
echo "  OK"
echo ""

# ---- 3. Subir archivos via tar+ssh ----
# Se incluye scripts/ para que aprovisionar_server.sh quede disponible en el
# server. .env.production tambien (esta en .gitignore, no llega por otra via).
# db/ es opcional (schema de referencia).
echo "  Subiendo cloud/, docker/, db/, scripts/, .env.production..."
cd "$BASE_LOCAL"

INCLUDE_DB=""
if [ -d "$BASE_LOCAL/db" ]; then
    INCLUDE_DB="db"
fi

tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='*.log' \
    --exclude='*.pem' \
    --exclude='*.key' \
    -czf - cloud docker $INCLUDE_DB scripts .env.production | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" \
    "tar -xzf - -C '$BASE_REMOTE/'"
echo "  OK"
echo ""

# ---- 4. Ejecutar setup remoto ----
# -t fuerza pseudo-terminal: el server puede pedir password de sudo si
# la cuenta no esta en sudoers NOPASSWD (en ec2-user de AMZ Linux 2023
# suele estar sin password, pero no asumimos).
echo "  Ejecutando setup en el server..."
echo ""
ssh -i "$KEY" -o StrictHostKeyChecking=no -t \
    "$USER@$HOST" \
    "DOMAIN='$DOMAIN' CERTBOT_EMAIL='$CERTBOT_EMAIL' bash '$BASE_REMOTE/scripts/aprovisionar_server.sh'"

echo ""
echo "============================================================"
echo "  Aprovisionamiento completo -- https://${DOMAIN}/"
echo "============================================================"
echo ""
