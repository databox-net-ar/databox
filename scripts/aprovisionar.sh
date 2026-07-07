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

HOST="manchester.databox.net.ar"
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
for f in .env.production env.php docker/Dockerfile cloud scripts/aprovisionar_server.sh; do
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
# certs/ tambien es opcional: si esta local se sube (contiene los certificados
# mTLS de Kite: movistar.pfx + PEM extraidos). Los .pem/.key SOLO se aceptan
# dentro de certs/: la carpeta esta gitignored y su contenido es material
# sensible que la app necesita en /var/www/certs (bind-monteado por el
# docker-compose.prod.yml). Si falta localmente, se avisa y se sigue: el
# resto del aprovisionamiento no depende de estos certs.
echo "  Subiendo cloud/, docker/, db/, scripts/, env.php, .env.production, certs/..."
cd "$BASE_LOCAL"

INCLUDE_DB=""
if [ -d "$BASE_LOCAL/db" ]; then
    INCLUDE_DB="db"
fi

INCLUDE_CERTS=""
if [ -d "$BASE_LOCAL/certs" ]; then
    INCLUDE_CERTS="certs"
    for f in movistar.pfx movistar.cer movistar.key; do
        if [ ! -f "$BASE_LOCAL/certs/$f" ]; then
            echo "  AVISO: falta $BASE_LOCAL/certs/$f -- Kite Platform no va a funcionar en prod."
        fi
    done
else
    echo "  AVISO: no existe $BASE_LOCAL/certs/ -- se omite; Kite Platform no va a funcionar en prod."
fi

tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='*.log' \
    -czf - cloud docker $INCLUDE_DB scripts env.php .env.production $INCLUDE_CERTS | \
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

# ---- 5. Verificar conectividad real HTTP / HTTPS desde local ----
# Si el server reporto OK pero HTTPS no responde desde afuera, casi siempre
# es porque el Security Group del EC2 no tiene abierto el puerto 443.
echo "  Verificando conectividad desde local..."

http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "http://${DOMAIN}/" || echo "000")
https_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "https://${DOMAIN}/" || echo "000")

echo "    http://${DOMAIN}/  -> ${http_code}"
echo "    https://${DOMAIN}/ -> ${https_code}"
echo ""

problemas=0
if [ "$http_code" != "200" ] && [ "$http_code" != "301" ] && [ "$http_code" != "302" ]; then
    echo "  AVISO: HTTP no responde como se esperaba."
    problemas=1
fi
if [ "$https_code" = "000" ]; then
    echo "  AVISO: HTTPS no responde (puerto 443 inalcanzable desde internet)."
    echo "    El cert SSL puede estar bien, pero el Security Group del EC2"
    echo "    bloquea 443. En la consola de AWS:"
    echo "      EC2 -> Security Groups -> SG del server -> Inbound rules"
    echo "      Agregar: HTTPS / TCP / 443 / 0.0.0.0/0"
    problemas=1
elif [ "$https_code" != "200" ] && [ "$https_code" != "301" ] && [ "$https_code" != "302" ]; then
    echo "  AVISO: HTTPS respondio con code=${https_code}, revisar Nginx."
    problemas=1
fi

echo "============================================================"
if [ "$problemas" -eq 0 ]; then
    echo "  Aprovisionamiento completo -- https://${DOMAIN}/"
else
    echo "  Aprovisionamiento finalizado con AVISOS -- revisar arriba."
fi
echo "============================================================"
echo ""
