# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Runtime image: PHP-FPM on Alpine with the extensions the application needs.
# Composer runs inside this image so platform requirements match production.
# ---------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS runtime

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && apk add --no-cache icu-libs libzip libpq oniguruma postgresql-client

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html

# Dependencies first: this layer is reused until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Public storage link is created at build time; the runtime user cannot write
# into the root-owned public/ directory.
RUN php artisan storage:link >/dev/null 2>&1 || true

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/demo-reset-loop.sh /usr/local/bin/demo-reset-loop
RUN chmod 755 /usr/local/bin/entrypoint /usr/local/bin/demo-reset-loop

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
