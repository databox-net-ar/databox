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
#   3c. api/v4/telegram/canales/ y api/v4/telegram/session_*/ se excluyen del
#       --delete: contienen sesiones MadelineProto generadas por el proceso PHP
#       del contenedor (owner distinto, archivos lockeados). Si el --delete
#       las alcanza, rsync aborta con "Permission denied" -- son runtime state
#       del server, no artefactos del repo.
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

# 3a. Preparar staging limpio + fotografiar el env que hay HOY en prod.
# El hash se toma ANTES de subir nada: es la unica forma de saber despues si
# env.php / .env.production cambiaron realmente de contenido (ver paso 4).
ENV_HASH_LOCAL=$(md5sum "$BASE_LOCAL/env.php" "$BASE_LOCAL/.env.production" | awk '{print $1}' | tr '\n' ' ')
ENV_HASH_REMOTO=$(ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" bash <<EOF
rm -rf '$STAGING_REMOTE' && mkdir -p '$STAGING_REMOTE'
md5sum '$BASE_REMOTE/env.php' '$BASE_REMOTE/.env.production' 2>/dev/null | awk '{print \$1}' | tr '\n' ' '
EOF
)

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
    --exclude='api/v4/telegram/session_*' \
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
        # --no-o --no-g --no-p: no intentar preservar owner/group/perms.
        # El origen viene de Windows (IDs Unix ficticios). Ademas, algunos
        # archivos del destino tienen owner/group/perms modificados por
        # el contenedor a traves del bind mount (p.ej. robot/crontab pasa
        # a root:root 0644 porque el entrypoint del cron lo chown-ea).
        # Si rsync intenta chgrp/chmod de vuelta a ec2-user, falla con
        # "Operation not permitted" y aborta el deploy con exit 23
        # (incidente prod 2026-08-02).
        rsync -a --no-o --no-g --no-p --delete \
              --exclude='v4/telegram/canales/' \
              --exclude='v4/telegram/session_*/' \
              "$STAGING_REMOTE/\$d/" "$BASE_REMOTE/\$d/"
    fi
done
[ -f "$STAGING_REMOTE/env.php" ] && cp -f "$STAGING_REMOTE/env.php" "$BASE_REMOTE/env.php"
[ -f "$STAGING_REMOTE/.env.production" ] && cp -f "$STAGING_REMOTE/.env.production" "$BASE_REMOTE/.env.production"
rm -rf "$STAGING_REMOTE"
EOF
echo "  OK"
echo ""

# ---- 3d. Reponer el owner de las sesiones de MadelineProto ----
# api/v4/telegram/canales/<telefono>/ es runtime state que Apache (www-data)
# tiene que poder ESCRIBIR: el `madeline-<v>.phar.lock` que MadelineProto toma
# con flock(), el MadelineProto.log y todo session.madeline/. Cualquier copia
# hecha desde el server (scp/cp como ec2-user, docker cp como root) deja esos
# archivos con otro owner y todo envio de Telegram pasa a fallar con un
# "flock(): Argument #1 must be of type resource, bool given" — incidente prod
# 2026-08-23.
#
# docker/entrypoint.sh ya hace este chown, pero SOLO al arrancar el contenedor,
# y el deploy normal no lo recrea (el PHP entra por bind mount). Por eso se
# re-aplica aca, en cada subida. Es idempotente y no interrumpe el servicio.
echo "  Reponiendo owner de las sesiones de Telegram (MadelineProto)..."
ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
    "docker exec databox-apache sh -c 'test -d /var/www/api/v4/telegram/canales && chown -R www-data:www-data /var/www/api/v4/telegram/canales || true'"
echo "  OK"
echo ""

# ---- 4. Rebuild (opcional) / restart selectivo del contenedor ----
# Por defecto NO se recrea el contenedor: el codigo PHP entra por bind mount
# (./cloud, ./api, ./robot -> /var/www/...) y se sirve fresco en cada request,
# asi que un deploy que solo toca PHP no necesita interrumpir el servicio.
#
# Casos que SI requieren recrear el contenedor:
#   a) --rebuild explicito (cambio de Dockerfile / dependencias del sistema).
#   b) env.php o .env.production cambiaron de CONTENIDO. El motivo real es
#      `env_file: - .env.production` en docker-compose.prod.yml: Docker inyecta
#      esas variables como entorno al CREAR el contenedor, y env.php le da
#      precedencia al entorno por sobre el archivo (`if (getenv($k) !== false)
#      continue;`). O sea que los valores vigentes quedan congelados desde el
#      ultimo `up`: cambiar el archivo no alcanza, hay que recrear.
#
#      Antes esto se detectaba comparando el inodo del archivo en el host
#      contra el del contenedor, asumiendo que la subida creaba inodo nuevo.
#      Es falso: estos dos archivos no viajan por el rsync del paso 3c sino
#      por el `cp -f` de mas abajo, y `cp -f` sobrescribe in place -- mismo
#      inodo, chequeo siempre negativo. Resultado: cambiarle el VALOR a una
#      clave existente de .env.production no llegaba nunca a produccion, en
#      silencio y con el deploy reportando OK. (Agregar una clave NUEVA si
#      funcionaba: al no estar en el entorno inyectado, no aplica la
#      precedencia y el valor sale del archivo.)
#
#      Ahora se compara el md5 del par (env.php, .env.production) local contra
#      el que habia en prod ANTES de subir. Si difiere, recreate.
if [ "$REBUILD" = true ]; then
    echo "  Reconstruyendo imagen Docker y recreando contenedor..."
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
        "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE build && docker compose -f $COMPOSE_FILE up -d --force-recreate"
    echo "  OK -- imagen reconstruida y contenedor levantado"
else
    # Comparacion por contenido contra la foto tomada en el paso 3a. Si prod
    # no tenia los archivos (primer deploy), el hash remoto viene vacio o
    # incompleto y tambien difiere -> recreate, que es lo correcto.
    if [ "$ENV_HASH_LOCAL" != "$ENV_HASH_REMOTO" ]; then
        echo "  env.php / .env.production cambiaron -- recreando contenedor..."
        ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
            "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE up -d --force-recreate"
        echo "  OK -- contenedor recreado con env nuevo"
    else
        echo "  Sin cambios de env ni imagen -- contenedor sigue arriba (sin interrupcion)"
    fi
fi
echo ""

# ---- 5. Migraciones SQL ----
# Las migraciones viven en cloud/sql/migrations/ y son idempotentes.
# NO se aplican desde este script: se corren manualmente desde la herramienta
# "Migrador DB" del panel (https://cloud.databox.net.ar > Administracion >
# Herramientas > Migrador DB), que registra cada corrida en la tabla
# `migraciones` y muestra pendientes/aplicadas con hash.
echo "  Migraciones SQL: aplicar desde Panel > Administracion > Herramientas > Migrador DB."
