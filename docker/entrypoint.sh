#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set."
    echo "Generate one with: docker compose run --rm app php artisan key:generate --show"
    echo "then add it to your .env.docker file before starting the app."
    exit 1
fi

db_host="${DB_HOST:-db}"
db_port="${DB_PORT:-5432}"
db_user="${DB_USERNAME:-postgres}"

echo "Waiting for database at ${db_host}:${db_port}..."
until pg_isready -h "$db_host" -p "$db_port" -U "$db_user" >/dev/null 2>&1; do
    sleep 1
done
echo "Database is up."

# The named volume mounted over storage/app/public starts out root-owned;
# reclaim it for the php-fpm worker user on every boot.
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link || true
php artisan migrate --force

php artisan optimize:clear
php artisan optimize

exec "$@"
