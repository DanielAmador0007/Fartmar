# syntax=docker/dockerfile:1.7
# Imagen de la API (Laravel sobre PHP-FPM 8.4). Multi-stage:
#   base        PHP-FPM + extensiones (pdo_pgsql, intl, pcntl; opcache ya viene)
#   vendor      composer install --no-dev optimizado (no llega a la imagen final)
#   dev         herramientas de desarrollo; el código se monta desde ./backend
#   production  (por defecto) código + vendor, opcache de producción, usuario
#               no root (www-data), HEALTHCHECK contra /ready
#
# El contexto es ./backend y los archivos de configuración vienen del contexto
# adicional "infra" (= ./docker):
#   docker build --build-context infra=docker -t fartmar-api backend
# docker-compose.yml y CI ya lo pasan (additional_contexts / build-contexts).

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-alpine AS base

# fcgi = cgi-fcgi para el healthcheck por FastCGI (sin Nginx).
RUN apk add --no-cache icu-libs libpq fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl \
    && apk del .build-deps

COPY --from=infra php/conf.d/zz-fartmar.ini /usr/local/etc/php/conf.d/zz-fartmar.ini
COPY --from=infra php/php-fpm.d/zz-fartmar.conf /usr/local/etc/php-fpm.d/zz-fartmar.conf
COPY --from=infra php/healthcheck.sh /usr/local/bin/fartmar-healthcheck
COPY --from=infra php/migrate.sh /usr/local/bin/fartmar-migrate
# Por si el repositorio se clonó en Windows con CRLF.
RUN sed -i 's/\r$//' /usr/local/bin/fartmar-healthcheck /usr/local/bin/fartmar-migrate \
    && chmod 755 /usr/local/bin/fartmar-healthcheck /usr/local/bin/fartmar-migrate

WORKDIR /app
EXPOSE 9000
STOPSIGNAL SIGQUIT

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip

# Primero solo los manifiestos: la capa de dependencias se reutiliza mientras
# composer.lock no cambie.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction \
    && php artisan package:discover --no-interaction

# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip \
    && echo "memory_limit=1G" > /usr/local/etc/php/conf.d/zz-zdev.ini

COPY --from=infra php/entrypoint.dev.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod 755 /usr/local/bin/entrypoint

# Solo desarrollo: el maestro de FPM corre como root y los workers como
# www-data, para poder escribir vendor/ y storage/ en el bind mount. La
# imagen de producción (abajo) no tiene root en ningún proceso.
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
HEALTHCHECK --interval=10s --timeout=5s --start-period=180s --retries=5 CMD ["fartmar-healthcheck"]
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json

COPY --from=infra php/conf.d/zz-opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini
COPY --from=infra php/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod 755 /usr/local/bin/entrypoint \
    # FPM corre entero como www-data: las directivas user/group solo aplican si
    # el maestro es root y aquí solo producirían un aviso en cada arranque.
    && sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf

# El código queda de root y solo lectura para www-data: un proceso
# comprometido no puede modificar la aplicación. Solo storage/,
# bootstrap/cache/ (cachés de optimize) y el directorio de llaves demo son
# escribibles.
COPY --from=vendor /app /app
RUN chown -R www-data:www-data storage bootstrap/cache \
    && install -d -o www-data -g www-data -m 700 /var/lib/fartmar

USER www-data

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=5 CMD ["fartmar-healthcheck"]
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
