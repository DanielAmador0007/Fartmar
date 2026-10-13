#!/bin/sh
# Entrypoint de DESARROLLO de la API (target `dev` de backend/Dockerfile, con
# el código montado desde ./backend). Lo usa docker-compose.dev.yml.
# Deja el contenedor listo desde un clon limpio: dependencias, backend/.env y
# APP_KEY. Idempotente: se puede reiniciar sin efectos.
set -eu

cd /app

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
    echo "[entrypoint] Creando backend/.env desde .env.example"
    cp .env.example .env
fi

# En desarrollo las llaves viven en backend/.env (montado), así las ven
# también los `docker compose exec api php artisan ...`. Una variable vacía
# que llega desde compose taparía la de backend/.env: se elimina.
for name in APP_KEY PATIENT_HASH_KEY; do
    eval "current=\${$name:-}"
    if [ -z "$current" ]; then
        unset "$name"
    fi
done

# APP_KEY: si no viene por entorno ni está en .env, se genera (SOLO DEMO; en
# producción la clave se inyecta como secreto y nunca se regenera).
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
    echo "[entrypoint] Generando APP_KEY en backend/.env (solo desarrollo)"
    php artisan key:generate --force --no-interaction
fi

if [ "${1:-}" = "fartmar-migrate" ]; then
    exec /usr/local/bin/fartmar-migrate
fi

exec "$@"
