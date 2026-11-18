-- Dev only (mounted by docker-compose.override.yml): a separate database for
-- `composer test:pgsql`, so the PostgreSQL test run never touches the dev data.
-- Runs once, when the db volume is created. On an existing volume, create it with:
--   docker compose exec db createdb -U table4all table4all_testing
CREATE DATABASE table4all_testing OWNER table4all;
