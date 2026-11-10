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
while :; do
    sleep 600
    echo "Demo: starting scheduled ephemeral data reset"
    php artisan down --retry=30 || exit 1
    # Fail closed: if reset or seeding fails, maintenance mode stays active.
    php artisan migrate:fresh --seed --force
    find storage/app -mindepth 1 -maxdepth 1 -exec rm -rf -- {} \;
    # Guard against circular symlinks copied into the shared demo tmpfs.
    if [ -L storage/app/public ]; then
        rm storage/app/public
    fi
    mkdir -p storage/app/public
    php artisan cache:clear
    php artisan demo:window --seconds=600
    php artisan up
    echo "Demo: fresh seeded dataset available"
done
