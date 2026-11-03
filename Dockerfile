# syntax=docker/dockerfile:1
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer

FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76 AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git gosu unzip libonig-dev libzip-dev libicu-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pcntl pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

FROM php-base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && composer check-platform-reqs

FROM node:24-bookworm-slim@sha256:c86ecead9e55a5210ac1727de38a4ca840bf03dcaf34dc9749addbf383b1920c AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts --no-audit
COPY --from=dependencies /var/www/html /app
ENV VITE_APP_NAME=FTP
RUN npm run build

FROM php-base AS development
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /app/public /var/www/html/public
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/ftp-entrypoint
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8000
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/ftp-entrypoint"]
