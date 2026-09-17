#!/bin/sh
set -e

cd /var/www/html

# APP_KEY, APP_ENV, DB_* etc. are injected from .env.docker.dev by
# docker-compose.override.yml and take precedence over any .env file.
# A .env file still needs to exist for Laravel; create a throwaway one if the
# bind-mounted working tree doesn't already have it.
if [ ! -f .env ]; then
    echo "No .env found - creating one from .env.example"
    cp .env.example .env
fi

# --- dependencies ------------------------------------------------------------
# vendor/ and node_modules/ live in named volumes, not the bind mount. Re-sync
# them when a lockfile changes. `npm ci` (not `install`) so the committed
# package-lock.json is never rewritten by the container.
echo "Syncing PHP dependencies..."
composer install --no-interaction --no-progress

lock_hash="$(md5sum package-lock.json | cut -d' ' -f1)"
if [ ! -f node_modules/.lock-hash ] || [ "$(cat node_modules/.lock-hash)" != "$lock_hash" ]; then
    echo "Syncing JS dependencies (npm ci)..."
    npm ci --no-audit --no-fund
    echo "$lock_hash" > node_modules/.lock-hash
else
    echo "JS dependencies already in sync."
fi

# --- database --------------------------------------------------------------
db_host="${DB_HOST:-db}"
db_port="${DB_PORT:-5432}"
db_user="${DB_USERNAME:-table4all}"

echo "Waiting for database at ${db_host}:${db_port}..."
until pg_isready -h "$db_host" -p "$db_port" -U "$db_user" >/dev/null 2>&1; do
    sleep 1
done
echo "Database is up."

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

[ -e public/storage ] || php artisan storage:link
php artisan migrate --force

# Dev: never cache config/routes/views - clear anything left from a prior run.
php artisan optimize:clear

exec "$@"
