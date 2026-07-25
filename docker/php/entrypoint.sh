#!/usr/bin/env sh
set -eu

cd /var/www/html

php artisan package:discover --ansi

mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"