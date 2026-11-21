#!/bin/sh
# Daily database dump on the VPS (cron, see README "Deploy to a VPS"). Keeps 30 days:
# a restored backup brings back deleted accounts, so old dumps must expire (docs/privacy.md).
# Copy the dumps off the server too; a dump that lives only on the VPS dies with it.
set -eu

cd "$(dirname "$0")/.."
mkdir -p backups
chmod 700 backups

file="backups/table4all-$(date -u +%Y%m%d-%H%M%S).dump"
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$file.tmp"
mv "$file.tmp" "$file"
chmod 600 "$file"

find backups -name 'table4all-*.dump' -mtime +30 -delete
