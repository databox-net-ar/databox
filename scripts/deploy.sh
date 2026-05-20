#!/bin/bash
# ============================================================
# deploy.sh - Sincroniza la app al servidor databox
# Host objetivo:  manchester.databox.com.ar
# URL servida:    https://cloud.databox.net.ar
#
# Uso:
#   bash deploy.sh           # sync + recreate
#   bash deploy.sh --rebuild # ademas reconstruye la imagen Docker
#                            # (necesario si cambio docker/Dockerfile)
# ============================================================

set -e

HOST="manchester.databox.com.ar"
USER="ec2-user"
KEY="/c/Users/Javier/OneDrive/Temp/Llaves/wescom/wescom.pem"
BASE_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
BASE_REMOTE="/opt/app/databox"
COMPOSE_FILE="docker-compose.prod.yml"   # generado por aprovisionar_server.sh

REBUILD=false
if [ "$1" == "--rebuild" ]; then
    REBUILD=true
fi

VERSION="1.0.$(date +%s)"

echo ""
echo "================================================"
echo "  Deploy databox -- version: $VERSION"
echo "  Host: $HOST"
echo "================================================"
echo ""

# ---- 1. version.txt en cloud/ ----
echo "$VERSION" > "$BASE_LOCAL/cloud/version.txt"
echo "  version.txt actualizado en cloud/"
echo ""

# ---- 2. Verificar artefactos requeridos ----
for f in .env.production docker/Dockerfile cloud; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

# ---- 3. Subir cloud/, docker/, db/, .env.production ----
# NO subimos docker-compose.yml: en el servidor vive docker-compose.prod.yml,
# generado por aprovisionar_server.sh (sin servicio databox-db).
# .env.production se sube en cada deploy para mantener prod en sync.
echo "  Subiendo cloud/, docker/, db/ y .env.production..."
cd "$BASE_LOCAL"

# db/ se incluye porque CLAUDE.md lo declara como schema de referencia.
# Si no existe (proyecto recien clonado en otra maquina), se omite.
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
    -czf - cloud docker $INCLUDE_DB .env.production | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" \
    "tar -xzf - -C '$BASE_REMOTE/'"
echo "  OK"
echo ""

# ---- 4. Rebuild (opcional) + force-recreate del contenedor ----
# force-recreate siempre: Docker bind-montea .env.production por inodo, no por
# path. El tar del paso 3 crea un inodo nuevo, asi que sin --force-recreate
# el contenedor sigue viendo el .env.production viejo. Es barato (~2s).
if [ "$REBUILD" = true ]; then
    echo "  Reconstruyendo imagen Docker y recreando contenedor..."
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
        "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE build && docker compose -f $COMPOSE_FILE up -d --force-recreate"
    echo "  OK -- imagen reconstruida y contenedor levantado"
else
    echo "  Recreando contenedor..."
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
        "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE up -d --force-recreate"
    echo "  OK -- contenedor actualizado"
fi
echo ""

# ---- 5. Migraciones SQL ----
# Las migraciones viven en cloud/sql/migrations/ y son idempotentes.
# Como el contenedor PHP no trae cliente mysql, se aplican manualmente
# desde un host con acceso a RDS, por ejemplo:
#   for f in cloud/sql/migrations/*.sql; do
#       mysql -h <RDS_HOST> -u <USER> -p<PASS> databox < "$f"
#   done
echo "  Migraciones SQL: aplicar manualmente contra RDS (ver comentario en deploy.sh)."
echo ""

echo "================================================"
echo "  Deploy completo -- https://cloud.databox.net.ar"
echo "================================================"
echo ""
