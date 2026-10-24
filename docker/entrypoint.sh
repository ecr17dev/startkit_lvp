#!/bin/sh
# Entrypoint shared by the app, queue, scheduler and migrate services.
set -e

cd /srv/apps/platform

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Cache configuration with the runtime environment (never at build time).
if [ "${STARTER_CACHE_CONFIG:-true}" = "true" ]; then
    php artisan config:cache --no-interaction >/dev/null
    php artisan route:cache --no-interaction >/dev/null
    php artisan view:cache --no-interaction >/dev/null
    php artisan event:cache --no-interaction >/dev/null
fi

exec "$@"
