#!/bin/sh
# Healthcheck del contenedor de la API: pide /ready directamente a PHP-FPM
# por FastCGI (sin pasar por Nginx). Sano = PHP-FPM responde, la BD responde y
# no hay migraciones pendientes (HealthController::ready).
set -eu

response=$(
    SCRIPT_NAME=/index.php \
    SCRIPT_FILENAME=/app/public/index.php \
    DOCUMENT_ROOT=/app/public \
    DOCUMENT_URI=/index.php \
    REQUEST_URI=/ready \
    QUERY_STRING= \
    REQUEST_METHOD=GET \
    SERVER_NAME=localhost \
    SERVER_PORT=9000 \
    REMOTE_ADDR=127.0.0.1 \
    HTTP_ACCEPT=application/json \
    cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null
) || exit 1

printf '%s' "$response" | grep -q '"status":"ok"'
