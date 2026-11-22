# syntax=docker/dockerfile:1

ARG PHP_IMAGE=dunglas/frankenphp:1.13.5-php8.4.27-trixie@sha256:08af88488ce218936d530b80f6f55dc2afe4d0d91f13f9e6ae350a9e7752128e
ARG NODE_IMAGE=node:22.23.4-trixie-slim@sha256:be1e2a3dc2c4fd68cdfadad2a696a280e0d2749098bee509230174d72fb768c6

FROM ${NODE_IMAGE} AS node

FROM ${PHP_IMAGE} AS base

WORKDIR /var/www/html

RUN install-php-extensions \
        pdo_pgsql \
        redis \
        intl \
        zip \
        pcntl \
        opcache \
        gd

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates \
 && apt-get install -y --no-install-recommends --only-upgrade linux-libc-dev \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN printf '%s\n' \
        'memory_limit=256M' \
        'expose_php=Off' \
        'opcache.enable=1' \
        'opcache.memory_consumption=128' \
        'opcache.interned_strings_buffer=16' \
        'opcache.max_accelerated_files=20000' \
        'opcache.validate_timestamps=0' \
        > "$PHP_INI_DIR/conf.d/99-fastgrid.ini"

FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

FROM vendor AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ARG VITE_APP_NAME=FastGrid
ENV VITE_APP_NAME=${VITE_APP_NAME}

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY . .

RUN npm run build:ssr

FROM ${NODE_IMAGE} AS social

WORKDIR /app

COPY resources/social/package.json resources/social/package-lock.json .npmrc ./
RUN npm ci --omit=dev

FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/tmp/caddy \
    XDG_DATA_HOME=/tmp/caddy

COPY --from=node /usr/local/bin/node /usr/local/bin/node

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /var/www/html/bootstrap/ssr ./bootstrap/ssr
COPY --from=social --chown=www-data:www-data /app/node_modules ./resources/social/node_modules

COPY --chown=root:root --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

RUN setcap -r /usr/local/bin/frankenphp \
 && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && ln -s ../storage/app/public public/storage \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2", "--max-requests=500"]
