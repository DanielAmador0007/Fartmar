#!/bin/sh
# Entrypoint de DESARROLLO de la API.
# Deja el contenedor listo desde un clon limpio: dependencias, .env, APP_KEY,
# espera a la BD y aplica migraciones. Idempotente: se puede reiniciar sin efectos.
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

# APP_KEY: si no viene por entorno ni está en .env, se genera (SOLO DEMO; en
# producción la clave se inyecta como secreto y nunca se regenera).
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
    echo "[entrypoint] Generando APP_KEY (solo demo)"
    php artisan key:generate --force --no-interaction
fi

echo "[entrypoint] Esperando a PostgreSQL en ${DB_HOST}:${DB_PORT}..."
attempts=0
until php -r '
    try {
        new PDO(
            sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD"),
        );
    } catch (Throwable $e) {
        exit(1);
    }
'; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
        echo "[entrypoint] PostgreSQL no respondió tras 30 intentos" >&2
        exit 1
    fi
    sleep 1
done

echo "[entrypoint] Aplicando migraciones"
php artisan migrate --force --no-interaction

exec "$@"
