#!/bin/sh
# DEMO ONLY: restore an immutable PostgreSQL snapshot every 600 seconds.
# No migrations, seeders, or new user passwords during scheduled resets.
set -eu
if [ "${DEMO_MODE:-false}" != "true" ] || [ "${APP_ENV:-}" != "demo" ] ||
   [ "${DB_HOST:-}" != "demo-db" ] || [ "${DB_DATABASE:-}" != "demo_erp" ] ||
   [ "${DB_CONNECTION:-}" != "pgsql" ]; then
    echo "FATAL: refusing demo reset outside isolated PostgreSQL demo." >&2
    exit 1
fi
if [ "${DEMO_RESET_SECONDS:-600}" != "600" ]; then
    echo "FATAL: demo reset interval must be 600." >&2
    exit 1
fi
export PGHOST="${DB_HOST}" PGPORT="${DB_PORT:-5432}" PGDATABASE="${DB_DATABASE}"
export PGUSER="${DB_USERNAME}" PGPASSWORD="${DB_PASSWORD}"
SNAPSHOT=/var/www/html/storage/framework/demo-baseline.dump
cd /var/www/html
test -s "${SNAPSHOT}" || { echo "FATAL: immutable demo snapshot missing." >&2; exit 1; }
pg_restore --list "${SNAPSHOT}" >/dev/null || exit 1

restore_baseline() {
    # Restore never mutates the saved archive. Fail closed until all stages pass.
    psql -X -v ON_ERROR_STOP=1 -c 'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;'
    pg_restore --exit-on-error --no-owner --no-acl --dbname="${PGDATABASE}" "${SNAPSHOT}"
    php artisan demo:ensure-users --verify-only
    find storage/app -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    mkdir -p storage/app/public
    php artisan cache:clear
    php artisan demo:window --seconds=600
    php artisan up
}

while :; do
    sleep 600
    echo "Demo: restoring baseline snapshot"
    if ! php artisan down --retry=30; then
        echo "ERROR: unable to enter maintenance mode; retrying" >&2
        sleep 30
        continue
    fi

    until restore_baseline; do
        echo "ERROR: snapshot restore failed; keeping maintenance mode and retrying in 30s" >&2
        sleep 30
    done
    echo "Demo: baseline snapshot restored and users verified"
done
