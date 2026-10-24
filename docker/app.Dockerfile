# syntax=docker/dockerfile:1.7
#
# One image for the web application (PHP-FPM), the queue worker and the
# scheduler; only the command changes. The "web" target is an Nginx image
# with the compiled public assets.
#
# Pin versions/digests for production builds (see docs/docker.md).

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.29

FROM node:${NODE_VERSION}-bookworm-slim AS node

# ----------------------------------------------------------------------
# PHP base: extensions required by Laravel 13 and the starter
# ----------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        unzip git ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath pcntl opcache gd exif \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-starter.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-starter.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /srv

# ----------------------------------------------------------------------
# Composer dependencies (internal packages are path repositories)
# ----------------------------------------------------------------------
FROM php-base AS vendor

COPY packages/php packages/php
# Path repository of starter-console (validator of starter.config.json)...
COPY tooling/create-starter tooling/create-starter
COPY apps/platform/composer.json apps/platform/composer.lock apps/platform/
RUN composer install --working-dir=apps/platform --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY apps/platform apps/platform
RUN composer dump-autoload --working-dir=apps/platform --optimize --no-dev --no-scripts

# ----------------------------------------------------------------------
# Front-end assets (Vite + pnpm). Wayfinder needs PHP, so Node is added
# to the PHP image for this stage only.
# ----------------------------------------------------------------------
FROM vendor AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack \
    && corepack enable

COPY package.json pnpm-workspace.yaml pnpm-lock.yaml .npmrc ./
COPY packages/js packages/js
RUN pnpm install --frozen-lockfile

RUN cp apps/platform/.env.example apps/platform/.env \
    && php apps/platform/artisan key:generate --force \
    && pnpm --filter @starter/platform build \
    && rm apps/platform/.env

# ----------------------------------------------------------------------
# Runtime: app, queue and scheduler
# ----------------------------------------------------------------------
FROM php-base AS app

ENV APP_ENV=production \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=vendor --chown=www-data:www-data /srv/packages/php /srv/packages/php
COPY --from=vendor --chown=www-data:www-data /srv/tooling/create-starter /srv/tooling/create-starter
COPY --from=vendor --chown=www-data:www-data /srv/apps/platform /srv/apps/platform
COPY --from=assets --chown=www-data:www-data /srv/apps/platform/public/build /srv/apps/platform/public/build
COPY docker/entrypoint.sh /usr/local/bin/starter-entrypoint

RUN chmod +x /usr/local/bin/starter-entrypoint \
    && rm -f /srv/apps/platform/.env \
    && mkdir -p /srv/apps/platform/storage/app/private /srv/apps/platform/storage/framework/{cache,sessions,views} /srv/apps/platform/storage/logs \
    && chown -R www-data:www-data /srv/apps/platform/storage /srv/apps/platform/bootstrap/cache

WORKDIR /srv/apps/platform
USER www-data

ENTRYPOINT ["starter-entrypoint"]
CMD ["php-fpm"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php artisan starter:health --live || exit 1

# ----------------------------------------------------------------------
# Web: Nginx serving public/ and proxying PHP to the app service
# ----------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS web

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /srv/apps/platform/public /srv/apps/platform/public
COPY --from=assets /srv/apps/platform/public/build /srv/apps/platform/public/build
