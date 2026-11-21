# syntax=docker/dockerfile:1.7
# Doc 01 §11.2: konteks build adalah ROOT repo (docker/compose.yaml: context .., dockerfile apps/api/Dockerfile) —
# sama seperti kedua app Next, sehingga satu jalur rilis berlaku untuk ketiganya. Hanya jalur yang dibaca runtime
# yang disalin, tidak ada COPY . , sehingga .env, kredensial, dan test tidak pernah masuk image.

FROM php:8.4-fpm AS php-base

RUN sed -i 's#http://deb.debian.org#https://deb.debian.org#g' /etc/apt/sources.list.d/debian.sources \
 && apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl bcmath zip gd opcache pcntl \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

FROM php-base AS composer-tools

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /build
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*

FROM composer-tools AS vendor

# composer.lock di-commit dan dibangkitkan di container yang sama (scripts/gen-lockfiles.sh) - build tanpa lock gagal, itu disengaja.
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY apps/api/app ./app
COPY apps/api/bootstrap ./bootstrap
COPY apps/api/config ./config
COPY apps/api/database ./database
COPY apps/api/lang ./lang
COPY apps/api/public ./public
COPY apps/api/routes ./routes
COPY apps/api/artisan ./

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && composer dump-autoload --no-dev --optimize --no-interaction

FROM php-base AS final

COPY --from=vendor --chown=www-data:www-data /build /app
WORKDIR /app

RUN mkdir -p /usr/local/var/run && chown -R www-data:www-data /usr/local/var/run
# non-root (Doc 01 §11.1); fpm master tidak bisa setuid, php-fpm hanya menolak opsi user= dengan peringatan.
USER www-data

EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=3s --retries=5 --start-period=40s \
    CMD php -r '$c = @fsockopen("127.0.0.1", 9000); exit($c ? 0 : 1);'

# CMD default php-fpm untuk service api. Service worker (`php artisan horizon`) dan scheduler
# (`php artisan schedule:work`) memakai image yang sama dengan command di-override oleh compose (Doc 01 §10, §11.1).
