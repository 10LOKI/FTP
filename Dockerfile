# syntax=docker/dockerfile:1
FROM mysql:8.4@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902 AS mysql
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer

FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76 AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git gosu unzip libaio1 libncurses6 libnuma1 libonig-dev libzip-dev libicu-dev libsqlite3-dev \
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
# Keep MySQL's original binary, private libraries, plugins and message catalog
# together. Debian supplies the system libraries for the native target platform.
COPY --from=mysql /usr/sbin/mysqld /usr/sbin/mysqld
COPY --from=mysql /usr/bin/mysql /usr/bin/mysqladmin /usr/bin/
COPY --from=mysql /usr/lib64/mysql /usr/lib64/mysql
COPY --from=mysql /usr/share/mysql-8.4 /usr/share/mysql-8.4
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /app/public /var/www/html/public
COPY docker/mysql.cnf /etc/my.cnf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/ftp-entrypoint
RUN groupadd --system mysql \
    && useradd --system --gid mysql --home-dir /var/lib/mysql --no-create-home mysql \
    && mkdir -p /var/lib/mysql /var/lib/mysql-files /run/mysqld \
       storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R mysql:mysql /var/lib/mysql /var/lib/mysql-files /run/mysqld \
    && chown -R www-data:www-data storage bootstrap/cache \
    && mysqld --version \
    && mysql --version
EXPOSE 8000
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/ftp-entrypoint"]
