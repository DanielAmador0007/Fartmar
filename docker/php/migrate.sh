#!/bin/sh
# Paso one-shot del servicio "migrate" (docker-compose.yml). Lo usan la imagen
# de producción y la de desarrollo. Idempotente: se puede repetir sin efectos.
#   1. espera a PostgreSQL
#   2. aplica migraciones (usuario dueño de las tablas)
#   3. crea/actualiza el rol de la app (DB_APP_USERNAME), sin TRUNCATE ni DDL
#   4. siembra datos demo SINTÉTICOS si SEED_DEMO_DATA=true (seeders idempotentes)
#
# El código PHP entre comillas simples lee el entorno con getenv().
# shellcheck disable=SC2016
set -eu

cd /app

echo "[migrate] Esperando a PostgreSQL en ${DB_HOST}:${DB_PORT}..."
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
    if [ "$attempts" -ge 60 ]; then
        echo "[migrate] PostgreSQL no respondió tras 60 intentos" >&2
        exit 1
    fi
    sleep 1
done

echo "[migrate] Aplicando migraciones"
php artisan migrate --force --no-interaction

php artisan db:provision-app-role --no-interaction

if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    echo "[migrate] Sembrando datos demo sintéticos (SEED_DEMO_DATA=true)"
    php artisan db:seed --force --no-interaction
else
    echo "[migrate] SEED_DEMO_DATA distinto de true: sin datos demo"
fi

echo "[migrate] Listo"
