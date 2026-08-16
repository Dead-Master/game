FROM node:22-alpine AS frontend

WORKDIR /frontend

COPY package.json ./
RUN npm install --ignore-scripts --no-audit

COPY resources ./resources
COPY vite.config.js ./
RUN npm run build


FROM php:8.5-fpm-bookworm AS app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && php -r '$required = ["bcmath", "curl", "dom", "intl", "mbstring", "pcntl", "pdo_mysql", "pdo_sqlite", "redis", "xml", "xmlwriter", "zip"]; $loaded = array_map("strtolower", get_loaded_extensions()); $missing = array_diff($required, $loaded); if ($missing) { fwrite(STDERR, "Missing PHP extensions: ".implode(", ", $missing).PHP_EOL); exit(1); }' \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
COPY --from=frontend /frontend/public/build ./public/build
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]


FROM nginx:1.28-alpine AS web

WORKDIR /var/www/html

COPY --from=app /var/www/html/public ./public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
