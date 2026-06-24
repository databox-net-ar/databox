#!/bin/bash
# ============================================================
# instalar.sh - Rebuild idempotente del stack local Docker (Databox).
#
# Que hace:
#   - Verifica que Docker este disponible.
#   - Verifica que el puerto fijo 8091 este libre.
#   - Recrea el contenedor de la app desde cero (down + up -d --build).
#   - Aplica las migraciones de cloud/sql/migrations/*.sql contra el
#     contenedor MySQL compartido `herramientas-mysql` (via docker exec).
#   - Imprime la URL donde se sirve la app.
#
# Puertos: databox usa SIEMPRE el 8091, igual interno y externo, igual
# en dev y en prod. No hay seleccion dinamica de puerto. Si 8091 esta
# ocupado por otra cosa, instalar.sh falla y hay que liberarlo.
#
# La BD MySQL ya NO la levanta este proyecto: se usa el contenedor
# compartido `herramientas-mysql` (mysql:8.0 publicado en 3306 del host).
# Desde el contenedor PHP de databox se la alcanza via host.docker.internal
# (mapeado a host-gateway en docker-compose.yml). Desde scripts del host
# se interactua via `docker exec herramientas-mysql ...`.
#
# Pensado para VSCode > Tasks > "instalar" o manualmente desde
# Git Bash:
#   bash ./scripts/instalar.sh
# ============================================================

set -e

# --- Repo root --------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# --- Colores ----------------------------------------------------------------
RED='\033[1;31m'
GREEN='\033[1;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# --- Constantes -------------------------------------------------------------
APP_PORT=8091
MYSQL_CONTAINER="herramientas-mysql"

echo ""
echo -e "${RED}==> Databox :: instalar (rebuild local)${NC}"
echo "    repo: $REPO_ROOT"
echo "    port: $APP_PORT (fijo)"
echo ""

# --- Pre-flight: docker -----------------------------------------------------
if ! docker version --format '{{.Server.Version}}' > /dev/null 2>&1; then
    echo -e "${RED}ERROR: Docker no esta corriendo o no es accesible. Inicia Docker Desktop e intenta de nuevo.${NC}"
    exit 1
fi

# --- Cargar credenciales de la BD -------------------------------------------
if [ ! -f "$REPO_ROOT/.env.development" ]; then
    echo -e "${RED}ERROR: falta .env.development en la raiz del repo.${NC}"
    exit 1
fi
# shellcheck disable=SC1090
set -a
. "$REPO_ROOT/.env.development"
set +a

# --- Verificar que el puerto este libre -------------------------------------
# El builtin /dev/tcp/ de bash devuelve 0 si algo escucha en el puerto.
# Si esta ocupado, listamos contenedores Docker que lo publican para que
# el usuario sepa que liberar.
port_in_use() {
    (echo > "/dev/tcp/127.0.0.1/$1") > /dev/null 2>&1
}

# Si el contenedor databox-apache esta arriba con ese puerto, no es un
# conflicto: lo vamos a recrear nosotros mismos.
databox_owns_port=false
if docker ps --format '{{.Names}} {{.Ports}}' | grep -qE "^databox-apache .*[: ]${APP_PORT}->"; then
    databox_owns_port=true
fi

if ! $databox_owns_port && port_in_use "$APP_PORT"; then
    echo -e "${RED}ERROR: el puerto $APP_PORT esta ocupado por otra cosa.${NC}"
    echo "       Contenedores Docker que lo publican:"
    docker ps --format '       {{.Names}}  {{.Ports}}' | grep -E "[: ]${APP_PORT}->" || echo "       (ninguno -- algun proceso fuera de Docker)"
    echo ""
    echo "       Liberalo antes de re-correr instalar.sh."
    exit 1
fi

# --- Verificar contenedor MySQL compartido ----------------------------------
if ! docker ps --format '{{.Names}}' | grep -qx "$MYSQL_CONTAINER"; then
    echo -e "${RED}ERROR: el contenedor '$MYSQL_CONTAINER' no esta corriendo.${NC}"
    echo "       Levantalo desde el repo herramientas/ antes de correr instalar.sh."
    exit 1
fi

if ! docker exec "$MYSQL_CONTAINER" mysql -u"${DB_USER}" -p"${DB_PASS}" -e "SELECT 1" "${DB_NAME}" > /dev/null 2>&1; then
    echo -e "${RED}ERROR: no se puede conectar a MySQL en $MYSQL_CONTAINER (db: ${DB_NAME}).${NC}"
    echo "       Verifica las credenciales de .env.development y que la base exista."
    exit 1
fi

# --- Limpiar contenedor previo ----------------------------------------------
# `docker compose down --remove-orphans` SIEMPRE primero. `docker rm -f` como
# fallback por si quedo el contenedor con ese nombre pero sin label de compose.
echo -e "${RED}==> Limpiando contenedor previo...${NC}"

docker compose -p databox down --remove-orphans > /dev/null 2>&1 || true

if docker ps -a --format '{{.Names}}' | grep -qx "databox-apache"; then
    echo "    removiendo databox-apache (huerfano sin label compose)"
    docker rm -f databox-apache > /dev/null
fi

# --- Build & up -------------------------------------------------------------
echo ""
echo -e "${RED}==> docker compose up -d --build${NC}"
if ! docker compose -p databox up -d --build; then
    echo ""
    echo -e "${RED}ERROR: docker compose fallo.${NC}"
    echo -e "${YELLOW}--- docker ps -a (databox) ---${NC}"
    docker ps -a --filter "name=databox" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    echo ""
    echo -e "${YELLOW}--- docker logs databox-apache (ultimas 50 lineas) ---${NC}"
    docker logs --tail 50 databox-apache 2>&1 || true
    exit 1
fi

# --- Esperar que la app responda --------------------------------------------
echo ""
echo -e "${RED}==> Esperando a que la app responda en http://localhost:$APP_PORT ...${NC}"
deadline=$(( $(date +%s) + 60 ))
ok=false
while [ "$(date +%s)" -lt "$deadline" ]; do
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 2 "http://localhost:$APP_PORT/" 2>/dev/null || echo "000")
    if [ "$code" = "200" ]; then
        ok=true
        break
    fi
    sleep 1
done

# --- Aplicar migraciones ----------------------------------------------------
# Las migraciones de cloud/sql/migrations/ se aplican contra el contenedor
# compartido `herramientas-mysql` via docker exec. Estan escritas como
# idempotentes (chequean information_schema antes de ALTER), asi que
# reaplicarlas es no-op.
migrations_dir="$REPO_ROOT/cloud/sql/migrations"
if [ -d "$migrations_dir" ]; then
    echo ""
    echo -e "${RED}==> Aplicando migraciones de cloud/sql/migrations/ ...${NC}"
    for m in "$migrations_dir"/*.sql; do
        [ -f "$m" ] || continue
        name=$(basename "$m")
        echo "    $name"
        if ! docker exec -i "$MYSQL_CONTAINER" mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$m"; then
            echo -e "${RED}ERROR aplicando $name${NC}"
            exit 1
        fi
    done
fi

# --- Resumen ----------------------------------------------------------------
echo ""
if $ok; then
    echo -e "${GREEN}==> Listo.${NC}"
else
    echo -e "${YELLOW}==> Stack arriba, pero la app aun no responde. Revisa logs con: docker logs databox-apache${NC}"
fi

echo ""
echo -e "${GREEN}  Cloud   : http://localhost:$APP_PORT${NC}"
echo "  MySQL   : $MYSQL_CONTAINER  (db: ${DB_NAME}, user: ${DB_USER})"
echo ""
echo "  Logs    : docker logs -f databox-apache"
echo "  Down    : docker compose -p databox down"
echo ""
