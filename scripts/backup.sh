#!/usr/bin/env bash
# Respaldo lógico de PostgreSQL del stack fartmar (pg_dump formato custom).
#
#   scripts/backup.sh [directorio]          (por defecto ./backups, en .gitignore)
#   RETENTION_DAYS=30 scripts/backup.sh
#   COMPOSE="docker compose -f docker-compose.yml -f docker-compose.dev.yml" scripts/backup.sh
#
# 1. pg_dump -Fc DENTRO del contenedor db (mismas versiones de cliente y servidor)
# 2. pg_restore --list sobre el archivo: el dump se puede leer
# 3. copia al host con `docker compose cp` (sin pasar binarios por la terminal)
#    y deja un .sha256 al lado
# 4. borra respaldos con más de RETENTION_DAYS días
# Programado (cron/systemd timer/CronJob), p. ej. cada 6 h: ver docs/DEPLOY.md.
# Restaurar o probar la restauración: scripts/restore.sh.
set -euo pipefail

# Git Bash en Windows reescribe rutas que empiezan con "/" (db:/tmp/...).
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."
COMPOSE="${COMPOSE:-docker compose}"
OUT_DIR="${1:-backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
REMOTE_FILE="/tmp/fartmar-backup.dump"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="${OUT_DIR}/fartmar-${stamp}.dump"
mkdir -p "$OUT_DIR"

echo "[backup] pg_dump -Fc de la BD del servicio db"
$COMPOSE exec -T db sh -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -Fc -Z 6 -f $REMOTE_FILE \
    && pg_restore --list $REMOTE_FILE > /dev/null"

$COMPOSE cp "db:${REMOTE_FILE}" "$file"
$COMPOSE exec -T db rm -f "$REMOTE_FILE"

(cd "$OUT_DIR" && sha256sum "$(basename "$file")" > "$(basename "$file").sha256")
echo "[backup] ${file} ($(wc -c < "$file") bytes) + .sha256"

# Retención: solo archivos de este script.
deleted=$(find "$OUT_DIR" -maxdepth 1 -name 'fartmar-*.dump*' -mtime +"$RETENTION_DAYS" -print -delete | wc -l)
echo "[backup] retención ${RETENTION_DAYS} días: ${deleted} archivo(s) antiguo(s) borrado(s)"
