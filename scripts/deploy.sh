#!/bin/bash
# ============================================================
# deploy.sh - Sincroniza la app al servidor databox
# Host objetivo:  manchester.databox.net.ar
# URL servida:    https://cloud.databox.net.ar
#
# Uso:
#   bash deploy.sh           # sync + recreate
#   bash deploy.sh --rebuild # ademas reconstruye la imagen Docker
#                            # (necesario si cambio docker/Dockerfile)
# ============================================================

set -e

HOST="manchester.databox.net.ar"
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
for f in .env.production env.php docker/Dockerfile cloud; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

# ---- 3. Subir cloud/, docker/, db/, env.php, .env.production, certs/ ----
# NO subimos docker-compose.yml: en el servidor vive docker-compose.prod.yml,
# generado por aprovisionar_server.sh. Ni dev ni prod corren MySQL en Docker:
# en prod la BD es RDS, en dev es el MySQL del host.
# .env.production se sube en cada deploy para mantener prod en sync.
# env.php es el loader de variables (define APP_KEY_CLOUD y demas como constantes).
# certs/ contiene el material mTLS de Kite (movistar.pfx + PEM extraidos). Los
# .pem/.key SOLO se aceptan dentro de certs/: la carpeta esta gitignored y su
# contenido es material sensible que la app necesita en /var/www/certs
# (bind-monteado por el docker-compose.prod.yml). Si falta localmente, se
# avisa y se sigue: el deploy funciona sin certs (solo el modulo de SIMs
# Movistar queda fuera de linea).
echo "  Subiendo cloud/, docker/, db/, env.php, .env.production, certs/..."
cd "$BASE_LOCAL"

# db/ se incluye porque CLAUDE.md lo declara como schema de referencia.
# Si no existe (proyecto recien clonado en otra maquina), se omite.
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
    -czf - cloud robot docker $INCLUDE_DB env.php .env.production $INCLUDE_CERTS | \
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
