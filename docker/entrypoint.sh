#!/usr/bin/env sh
set -eu

mkdir -p /data/books /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

chown -R www-data:www-data /data /var/www/html/storage /var/www/html/bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY must be set." >&2
    exit 1
fi

# Migrations run unattended on every boot. Snapshot first so a bad one is recoverable.
# ponytail: snapshots are never pruned; add retention if /data ever fills.
if [ -s /data/database.sqlite ]; then
    cp /data/database.sqlite "/data/database.sqlite.bak-$(date +%Y%m%d%H%M%S)"
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan view:cache --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
