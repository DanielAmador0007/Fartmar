#!/usr/bin/env bash
# Restauración de un respaldo de scripts/backup.sh.
#
#   scripts/restore.sh backups/fartmar-XXXX.dump              PRUEBA de restauración
#       Restaura en una BD temporal del mismo servidor, verifica y la borra.
#       No toca la BD real. Es la que se programa (p. ej. semanal) para saber
#       que los respaldos sirven.  --keep conserva la BD temporal.
#
#   scripts/restore.sh backups/fartmar-XXXX.dump --into-live  RESTAURACIÓN real
#       Detiene web/api/scheduler, reemplaza la BD, y vuelve a levantar el stack
#       (migrate reaplica el rol de la app y sus permisos). Pide confirmación
#       (--yes para scripts).
#
# Verificaciones: checksum (.sha256), pg_restore --exit-on-error, tabla de
# migraciones, conteos y que el saldo del kardex cuadre con las existencias.
#
# Las variables entre comillas simples se expanden dentro del contenedor db.
# shellcheck disable=SC2016
set -euo pipefail

export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."
REMOTE_FILE="/tmp/fartmar-restore.dump"
DEV_COMPOSE="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

file="${1:-}"
shift || true
mode="check"
keep="false"
assume_yes="false"
for arg in "$@"; do
    case "$arg" in
        --into-live) mode="live" ;;
        --keep) keep="true" ;;
        --yes) assume_yes="true" ;;
        *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
    esac
done

if [ -z "$file" ] || [ ! -f "$file" ]; then
    echo "Uso: scripts/restore.sh <archivo.dump> [--into-live [--yes]] [--keep]" >&2
    exit 2
fi

if [ -f "${file}.sha256" ]; then
    (cd "$(dirname "$file")" && sha256sum -c "$(basename "$file").sha256" > /dev/null) \
        || { echo "[restore] El checksum no coincide: archivo dañado" >&2; exit 1; }
    echo "[restore] checksum OK"
fi

# COMPOSE: el mismo conjunto de archivos con el que se levantó el stack. Si no
# viene, se detecta por la imagen de la API en ejecución (desarrollo o
# producción). --into-live exige saberlo: el `up` final recrea los servicios
# con esa configuración.
if [ -z "${COMPOSE:-}" ]; then
    api_id=$(docker compose ps -q api 2>/dev/null || true)
    api_image=$( [ -n "$api_id" ] && docker inspect -f '{{.Config.Image}}' "$api_id" 2>/dev/null || true)
    case "$api_image" in
        fartmar-api-dev:*) COMPOSE="$DEV_COMPOSE" ;;
        "")
            if [ "$mode" = "live" ]; then
                echo "[restore] No hay API en ejecución para detectar el entorno. Indique COMPOSE explícito, p. ej.:" >&2
                echo "  COMPOSE=\"docker compose\" scripts/restore.sh $file --into-live" >&2
                exit 2
            fi
            COMPOSE="docker compose" ;;
        *) COMPOSE="docker compose" ;;
    esac
fi
echo "[restore] Entorno: ${COMPOSE}"

db_sh() { $COMPOSE exec -T "$@"; }

# Limpieza siempre (también si la verificación falla o se interrumpe): el
# archivo copiado y, en la prueba, la BD temporal salvo --keep.
temp_db=""
cleanup() {
    if [ -n "$temp_db" ] && [ "$keep" != "true" ]; then
        db_sh -e TARGET="$temp_db" db sh -c 'dropdb -U "$POSTGRES_USER" --if-exists "$TARGET"' > /dev/null 2>&1             && echo "[restore] BD temporal ${temp_db} borrada"
    fi
    db_sh db rm -f "$REMOTE_FILE" > /dev/null 2>&1 || true
}
trap cleanup EXIT

$COMPOSE cp "$file" "db:${REMOTE_FILE}"

# Comprobaciones sobre la BD $TARGET (dentro del contenedor db).
VERIFY_SQL="
select 'migraciones aplicadas', count(*) from migrations
union all select 'usuarios', count(*) from users
union all select 'existencias (filas)', count(*) from stocks
union all select 'movimientos de kardex', count(*) from kardex_movements
union all select 'dispensaciones', count(*) from dispensations
union all select 'traslados', count(*) from transfers
union all select 'existencias que NO cuadran con el kardex', count(*) from stocks s
    where s.quantity <> coalesce((select sum(k.direction * k.quantity) from kardex_movements k
                                  where k.warehouse_id = s.warehouse_id and k.lot_id = s.lot_id), 0);
"

verify() {
    local target="$1"
    echo "[restore] Verificando ${target}"
    db_sh -e TARGET="$target" -e VERIFY_SQL="$VERIFY_SQL" db sh -c \
        'psql -U "$POSTGRES_USER" -d "$TARGET" -v ON_ERROR_STOP=1 -At -F " = " -c "$VERIFY_SQL"' | tee /dev/stderr \
        | grep -q '^existencias que NO cuadran con el kardex = 0$' \
        || { echo "[restore] Verificación fallida en ${target}" >&2; return 1; }
    echo "[restore] Verificación OK"
}

if [ "$mode" = "check" ]; then
    target="fartmar_restore_check_$(date -u +%Y%m%d%H%M%S)"
    temp_db="$target"
    echo "[restore] Prueba de restauración en la BD temporal ${target}"
    db_sh -e TARGET="$target" -e DUMP="$REMOTE_FILE" db sh -c '
        createdb -U "$POSTGRES_USER" "$TARGET" \
        && pg_restore -U "$POSTGRES_USER" -d "$TARGET" --no-owner --no-acl --exit-on-error "$DUMP"'
    status=0
    verify "$target" || status=1
    if [ "$keep" = "true" ]; then
        echo "[restore] BD temporal conservada: ${target} (bórrela con dropdb)"
    fi
    exit "$status"
fi

# --- Restauración real ---------------------------------------------------------
live_db=$($COMPOSE exec -T db sh -c 'printf %s "$POSTGRES_DB"')
if [ "$assume_yes" != "true" ]; then
    echo "Esto REEMPLAZA la BD '${live_db}' con ${file}. Los datos posteriores al respaldo se pierden."
    read -r -p "Escriba el nombre de la BD para confirmar: " answer
    [ "$answer" = "$live_db" ] || { echo "Cancelado." >&2; exit 1; }
fi

echo "[restore] Deteniendo web, api y scheduler"
$COMPOSE stop web api scheduler

echo "[restore] Reemplazando ${live_db}"
db_sh -e DUMP="$REMOTE_FILE" db sh -c '
    dropdb -U "$POSTGRES_USER" --if-exists --force "$POSTGRES_DB" \
    && createdb -U "$POSTGRES_USER" "$POSTGRES_DB" \
    && pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-acl --exit-on-error "$DUMP"'

verify "$live_db"

echo "[restore] Levantando el stack (migrate reaplica el rol de la app y sus permisos)"
$COMPOSE up -d --wait
echo "[restore] Restauración completa"
