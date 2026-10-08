# Imagen de DESARROLLO de la API (Fase 0).
# PHP 8.4 CLI + extensiones necesarias + Composer. Sirve con el servidor embebido de PHP.
# El Dockerfile multi-stage de producción (php-fpm + nginx, usuario no root,
# opcache) vive en backend/Dockerfile y se construye en la fase de DevOps.
FROM php:8.4-cli-alpine

RUN apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# memoria suficiente para phpstan
RUN echo "memory_limit=1G" > /usr/local/etc/php/conf.d/zz-dev.ini

COPY entrypoint.dev.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

WORKDIR /app

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
# `php -S` directo (no `artisan serve`): artisan serve descarta las variables
# de entorno que también existen en backend/.env, y en Docker la fuente de verdad
# es el entorno del contenedor.
# server.php de Laravel usa getcwd() como directorio público.
CMD ["sh", "-c", "cd /app/public && exec php -S 0.0.0.0:8080 /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
