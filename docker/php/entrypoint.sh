#!/bin/sh
# Entrypoint de la imagen de PRODUCCIÓN de la API (backend/Dockerfile).
# La misma imagen corre tres servicios (docker-compose.yml):
#   migrate    -> `fartmar-migrate`: migraciones + rol de la app + semilla demo, y termina
#   api        -> `php-fpm`
#   scheduler  -> `php artisan schedule:work`
#
# Llaves (APP_KEY, PATIENT_HASH_KEY): en producción real vienen del gestor de
# secretos como variables de entorno. Para la demo, si faltan y
# ALLOW_GENERATED_KEYS=true, el servicio migrate las genera UNA vez en el
# volumen app_keys y los demás servicios las leen de ahí (todas las réplicas
# deben usar la misma llave o no podrían descifrar los documentos). Ver S-102.
set -eu

KEYS_FILE="${APP_KEYS_FILE:-/var/lib/fartmar/app-keys.env}"
KEY_NAMES="APP_KEY PATIENT_HASH_KEY"

key_from_file() {
    if [ -f "$KEYS_FILE" ]; then
        sed -n "s/^$1=//p" "$KEYS_FILE" | head -n 1
    fi
}

generate_missing_keys() {
    umask 077
    for name in $KEY_NAMES; do
        eval "current=\${$name:-}"
        if [ -z "$current" ] && [ -z "$(key_from_file "$name")" ]; then
            echo "[entrypoint] ATENCIÓN: generando $name en $KEYS_FILE (SOLO DEMO; en producción inyéctela como secreto)" >&2
            value=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
            printf '%s=%s\n' "$name" "$value" >> "$KEYS_FILE"
        fi
    done
}

load_keys() {
    for name in $KEY_NAMES; do
        eval "current=\${$name:-}"
        if [ -z "$current" ]; then
            value=$(key_from_file "$name")
            if [ -z "$value" ]; then
                echo "[entrypoint] Falta $name. Defínala en .env (ver .env.example) o use ALLOW_GENERATED_KEYS=true solo para la demo." >&2
                exit 1
            fi
            export "$name=$value"
        fi
    done
}

cd /app

if [ "${1:-}" = "fartmar-migrate" ]; then
    if [ "${ALLOW_GENERATED_KEYS:-false}" = "true" ]; then
        generate_missing_keys
    fi
    load_keys
    exec /usr/local/bin/fartmar-migrate
fi

load_keys

# Cachés de configuración, rutas y eventos con el entorno de ESTE contenedor
# (no `optimize`: también cachea vistas y la API no tiene resources/views).
# Desde aquí la app no lee .env; `env()` solo se usa dentro de config/.
php artisan config:cache --no-interaction > /dev/null
php artisan route:cache --no-interaction > /dev/null
php artisan event:cache --no-interaction > /dev/null

exec "$@"
