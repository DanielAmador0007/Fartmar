#!/usr/bin/env bash
# Despliegue de un commit (imágenes inmutables por SHA) en un host con Docker
# Compose. Lo usan los jobs deploy-staging / deploy-production de CI.
#
#   DRY_RUN=1 scripts/deploy.sh <entorno> <imagen-api> <imagen-web>   (por defecto: solo imprime)
#   DRY_RUN=0 scripts/deploy.sh production ghcr.io/o/fartmar-api:<sha> ghcr.io/o/fartmar-web:<sha>
#
# Pasos (docs/DEPLOY.md):
#   1. validar la configuración (docker compose config) con las imágenes del SHA
#   2. respaldo previo (scripts/backup.sh)
#   3. pull de las imágenes del SHA (nunca :latest en un despliegue)
#   4. migraciones "expand" (compatibles con la versión anterior) con el
#      servicio one-shot migrate, antes de cambiar la API
#   5. recrear api, scheduler y web con las imágenes nuevas y esperar healthy
#   6. smoke test; si falla, rollback = volver a correr este script con el SHA anterior
#
# En "production" aborta si la configuración es de demo (SEED_DEMO_DATA=true,
# ALLOW_GENERATED_KEYS=true) o faltan APP_KEY / PATIENT_HASH_KEY, y el smoke
# test corre sin escrituras demo (SMOKE_DEMO_CHECKS=false) con SMOKE_EMAIL y
# SMOKE_PASSWORD, que son secretos del environment.
set -euo pipefail

cd "$(dirname "$0")/.."

environment="${1:?Uso: scripts/deploy.sh <entorno> <imagen-api> <imagen-web>}"
api_image="${2:?Falta la imagen de la API (con tag = SHA)}"
web_image="${3:?Falta la imagen web (con tag = SHA)}"
DRY_RUN="${DRY_RUN:-1}"
BASE_URL="${BASE_URL:-http://localhost:${WEB_HOST_PORT:-8080}}"

case "$api_image$web_image" in
    *:latest*) echo "[deploy] Use el tag del SHA, no :latest (despliegue reproducible y con rollback)" >&2; exit 1 ;;
esac

export API_IMAGE="$api_image" WEB_IMAGE="$web_image"

run() {
    if [ "$DRY_RUN" = "1" ]; then
        printf '[deploy][simulado] %s\n' "$*"
    else
        printf '[deploy] %s\n' "$*"
        "$@"
    fi
}

echo "[deploy] Entorno: ${environment}"
echo "[deploy] API: ${API_IMAGE}"
echo "[deploy] Web: ${WEB_IMAGE}"

# 1. Siempre se ejecuta (también en simulado): falla si falta una variable obligatoria.
rendered="$(docker compose config)"
echo "[deploy] docker compose config OK"

smoke_demo="true"
if [ "$environment" = "production" ]; then
    problems=""
    for flag in SEED_DEMO_DATA ALLOW_GENERATED_KEYS; do
        if grep -Eq "^ +${flag}: \"?true\"?$" <<< "$rendered"; then
            problems="${problems} ${flag}=true"
        fi
    done
    for key in APP_KEY PATIENT_HASH_KEY; do
        if grep -Eq "^ +${key}: \"?\"?$" <<< "$rendered"; then
            problems="${problems} ${key}-vacía"
        fi
    done
    if [ -n "$problems" ]; then
        echo "[deploy] ABORTADO: configuración de demo en producción:${problems}" >&2
        exit 1
    fi
    smoke_demo="false"
    echo "[deploy] Configuración de producción OK (sin semilla demo ni llaves generadas)"
fi

run bash scripts/backup.sh
run docker compose pull migrate api scheduler web
run docker compose run --rm --no-deps migrate
run docker compose up -d --no-build --no-deps --wait api scheduler web
run env BASE_URL="$BASE_URL" SMOKE_DEMO_CHECKS="$smoke_demo" bash scripts/smoke-test.sh

echo "[deploy] Despliegue de ${environment} terminado (DRY_RUN=${DRY_RUN})"
echo "[deploy] Rollback: DRY_RUN=0 scripts/deploy.sh ${environment} <imagen-api:sha-anterior> <imagen-web:sha-anterior>"
