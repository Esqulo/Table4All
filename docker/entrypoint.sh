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

php artisan storage:link || true
php artisan migrate --force

php artisan optimize:clear
php artisan optimize

# Last, after the artisan commands above: they run as root and write caches, compiled
# views and logs. php-fpm runs as www-data and must be able to write there too. This also
# reclaims the named volume mounted over storage/app/public, which starts out root-owned.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
