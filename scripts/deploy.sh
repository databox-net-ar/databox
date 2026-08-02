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
#
# IMPORTANTE: este deploy NO tiene nada que ver con git. No mira ramas,
# no mira staging, no mira commits, no mira working tree vs HEAD. Toma
# la carpeta del proyecto TAL COMO ESTA en disco y hace que prod refleje
# exactamente ese estado -- incluidos los archivos renombrados o borrados
# en dev, que tambien tienen que desaparecer en prod. Si un archivo existe
# localmente pero no esta commiteado, se sube igual. Si un archivo fue
# borrado localmente pero sigue commiteado, se borra en prod igual.
# ============================================================

set -e
set -o pipefail   # que el error del `tar | ssh` no se coma el exit code

# Imprime la hora de finalizacion siempre, tanto si el deploy termina OK como
# si `set -e` corta el script a mitad (p.ej. tar fallando por permisos).
START_TS=$(date +%s)
finish() {
    RC=$?
    END_TS=$(date +%s)
    DUR=$((END_TS - START_TS))
    echo ""
    echo "================================================"
    if [ $RC -eq 0 ]; then
        echo "  Deploy completo -- https://cloud.databox.net.ar"
    else
        echo "  Deploy FALLIDO (exit code: $RC)"
    fi
    echo "  Finalizado: $(date '+%Y-%m-%d %H:%M:%S')  (duracion: ${DUR}s)"
    echo "================================================"
    echo ""
}
trap finish EXIT

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
for f in .env.production env.php docker/Dockerfile cloud api; do
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
#
# Estrategia:
#   3a. tar+ssh el contenido a un staging remoto (Git Bash en Windows no
#       trae rsync; tar si). El staging esta fuera de $BASE_REMOTE para no
#       interferir con archivos generados por el server (docker-compose.prod.yml).
#   3b. Sobre el server, rsync --delete de cada subdir manejado
#       (staging/cloud -> $BASE_REMOTE/cloud, staging/api -> ...) para que
#       los archivos borrados en dev tambien desaparezcan en prod. Antes
#       usabamos "tar -xzf -" directo sobre $BASE_REMOTE, que solo agregaba
#       o sobrescribia: los borrados nunca se propagaban (p.ej. jobs
#       renombrados a snake_case dejaban el nombre viejo vivo en prod).
#   3c. api/v4/telegram/canales/ se excluye del --delete: contiene sesiones
#       MadelineProto generadas en el server que no deben tocarse.
echo "  Subiendo cloud/, api/, robot/, docker/, db/, env.php, .env.production, certs/..."
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

STAGING_REMOTE="/tmp/databox-deploy-staging"

# 3a. Preparar staging limpio
ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
    "rm -rf '$STAGING_REMOTE' && mkdir -p '$STAGING_REMOTE'"

# 3b. Subir tarball al staging
tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='./api/.git' \
    --exclude='./api/node_modules' \
    --exclude='./api/vendor' \
    --exclude='*.log' \
    --exclude='api/v4/telegram/canales' \
    -czf - cloud api robot docker $INCLUDE_DB env.php .env.production $INCLUDE_CERTS | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" \
    "tar --no-overwrite-dir -xzf - -C '$STAGING_REMOTE/'"

# 3c. Server-side rsync --delete por subdir. Iteramos por subdir y no hacemos
# --delete sobre $BASE_REMOTE porque a nivel raiz vive docker-compose.prod.yml
# generado por aprovisionar_server.sh, que no debe tocarse.
ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" bash <<EOF
set -e
for d in cloud api robot docker db certs; do
    if [ -d "$STAGING_REMOTE/\$d" ]; then
        mkdir -p "$BASE_REMOTE/\$d"
        rsync -a --delete --exclude='v4/telegram/canales/' "$STAGING_REMOTE/\$d/" "$BASE_REMOTE/\$d/"
    fi
done
[ -f "$STAGING_REMOTE/env.php" ] && cp -f "$STAGING_REMOTE/env.php" "$BASE_REMOTE/env.php"
[ -f "$STAGING_REMOTE/.env.production" ] && cp -f "$STAGING_REMOTE/.env.production" "$BASE_REMOTE/.env.production"
rm -rf "$STAGING_REMOTE"
EOF
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
# NO se aplican desde este script: se corren manualmente desde la herramienta
# "Migrador DB" del panel (https://cloud.databox.net.ar > Administracion >
# Herramientas > Migrador DB), que registra cada corrida en la tabla
# `migraciones` y muestra pendientes/aplicadas con hash.
echo "  Migraciones SQL: aplicar desde Panel > Administracion > Herramientas > Migrador DB."
