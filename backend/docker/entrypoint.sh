#!/bin/sh
#
# Container entrypoint for the Tourism ERP API.
#
# Migrations run only when RUN_MIGRATIONS=true (default). Demo seeding is
# opt-in and intended for the first boot of a demo installation only.
set -eu

# Reject any attempt to run destructive demo reset outside the isolated demo DB.
if [ "${DEMO_MODE:-false}" = "true" ]; then
    if [ "${APP_ENV:-}" != "demo" ] || [ "${DB_HOST:-}" != "demo-db" ] || [ "${DB_DATABASE:-}" != "demo_erp" ]; then
        echo "FATAL: demo mode requires APP_ENV=demo, DB_HOST=demo-db and DB_DATABASE=demo_erp." >&2
        exit 1
    fi
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is empty." >&2
    echo "Generate one with: php artisan key:generate --show" >&2
    echo "Then set APP_KEY in the repository root .env (never commit it)." >&2
    exit 1
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Waiting for the database to accept connections..."
    attempt=0
    until php artisan db:show >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "FATAL: database is not reachable after 30 attempts." >&2
            exit 1
        fi
        sleep 2
    done

    echo "Running migrations..."
    php artisan migrate --force

    if [ "${SEED_DEMO:-false}" = "true" ]; then
        if [ "${DEMO_MODE:-false}" != "true" ]; then
            echo "FATAL: SEED_DEMO=true is permitted only in isolated demo mode." >&2
            exit 1
        fi
        echo "Seeding isolated demo dataset..."
        php artisan db:seed --force
    fi
fi

# public/storage is created in the Docker image. Re-creating it on
# every boot can introduce broken/cyclic links with the demo upload mount.
# In demo mode, repair only a symlink occupying the expected upload directory.
if [ "${DEMO_MODE:-false}" = "true" ]; then
    if [ -L storage/app/public ]; then
        echo "Repairing invalid demo upload symlink..."
        rm storage/app/public
    fi
    mkdir -p storage/app/public
fi

if [ "${APP_OPTIMIZE:-true}" = "true" ]; then
    echo "Caching configuration, routes, events and views..."
    php artisan optimize
fi

if [ "${DEMO_MODE:-false}" = "true" ]; then
    # This loop shares this container's ephemeral upload directory.
    php artisan demo:window --seconds="${DEMO_RESET_SECONDS:-600}"
    /bin/sh /usr/local/bin/demo-reset-loop &
fi

exec "$@"
