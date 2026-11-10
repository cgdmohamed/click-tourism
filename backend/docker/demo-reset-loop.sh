#!/bin/sh
# DEMO ONLY. Resets isolated PostgreSQL and temporary uploads every 600 seconds.
# Never run against a production database.
set -eu
if [ "${DEMO_MODE:-false}" != "true" ] || [ "${APP_ENV:-}" != "demo" ] ||
   [ "${DB_HOST:-}" != "demo-db" ] || [ "${DB_DATABASE:-}" != "demo_erp" ]; then
    echo "FATAL: refusing demo reset outside demo-db/demo_erp." >&2
    exit 1
fi
if [ "${DEMO_RESET_SECONDS:-600}" != "600" ]; then
    echo "FATAL: demo reset interval is fixed to 600 seconds." >&2
    exit 1
fi
cd /var/www/html
# Always fail closed while data is incomplete, but keep retrying the reset
# automatically rather than abandoning the worker forever on the first error.
# The protected target is the demo-db/demo_erp installation only.
while :; do
    sleep 600
    echo "Demo: starting scheduled ephemeral data reset"
    if ! php artisan down --retry=30; then
        echo "ERROR: unable to enter demo maintenance mode; retrying in 30s" >&2
        sleep 30
        continue
    fi

    until php artisan migrate:fresh --seed --force &&
          php artisan demo:ensure-users &&
          find storage/app -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + &&
          mkdir -p storage/app/public &&
          php artisan cache:clear &&
          php artisan demo:window --seconds=600 &&
          php artisan up; do
        echo "ERROR: demo reset failed; staying in maintenance and retrying in 30s" >&2
        sleep 30
    done

    echo "Demo: fresh seeded dataset available"
done
