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

# The demo upload volume is mounted at storage/app. A stale
# storage/app/public -> /var/www/html/storage/app/public symlink loops back
# into itself. Repair that link BEFORE invoking Artisan or starting PHP-FPM.
# Only touch the isolated demo mount; never alter production storage.
if [ "${DEMO_MODE:-false}" = "true" ]; then
    if [ -L storage/app/public ]; then
        echo "Repairing invalid demo upload symlink..."
        rm -- storage/app/public
    elif [ -e storage/app/public ] && [ ! -d storage/app/public ]; then
        echo "FATAL: demo upload path is not a directory." >&2
        exit 1
    fi
    mkdir -p storage/app/public
    if [ ! -d storage/app/public ] || [ -L storage/app/public ]; then
        echo "FATAL: demo upload directory is invalid." >&2
        exit 1
    fi
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

    if [ "${DEMO_MODE:-false}" = "true" ]; then
        # Always construct the immutable baseline from clean demo schema.
        # Never bootstrap a snapshot from users' previous edits.
        [ "${SEED_DEMO:-false}" = "true" ] && [ "${DEMO_SEED:-false}" = "true" ] || {
            echo "FATAL: demo baseline requires both SEED_DEMO and DEMO_SEED." >&2
            exit 1
        }
        echo "Building isolated demo baseline..."
        php artisan migrate:fresh --seed --force
    else
        echo "Running production migrations..."
        php artisan migrate --force
        if [ "${SEED_DEMO:-false}" = "true" ]; then
            echo "FATAL: SEED_DEMO is not permitted in production." >&2
            exit 1
        fi
    fi
fi

# Demo credentials are independently reconciled and verified after seeding.
# A partially seeded database must never be reported ready.
if [ "${DEMO_MODE:-false}" = "true" ]; then
    php artisan demo:ensure-users
    echo "Saving verified immutable demo baseline..."
    export PGHOST="${DB_HOST}" PGPORT="${DB_PORT:-5432}" PGDATABASE="${DB_DATABASE}" PGUSER="${DB_USERNAME}" PGPASSWORD="${DB_PASSWORD}"
    SNAPSHOT=/var/www/html/storage/framework/demo-baseline.dump
    rm -f "${SNAPSHOT}"
    pg_dump --format=custom --no-owner --no-acl --file="${SNAPSHOT}.tmp"
    pg_restore --list "${SNAPSHOT}.tmp" >/dev/null
    test -s "${SNAPSHOT}.tmp"
    mv "${SNAPSHOT}.tmp" "${SNAPSHOT}"
    # Prove the 10-minute reset works before the demo goes live; a restore that
    # only fails at the first scheduled reset would take the demo offline.
    echo "Verifying baseline restore (client $(pg_dump --version), server $(psql -X -Atc 'show server_version'))..."
    /bin/sh /usr/local/bin/demo-restore-db "${SNAPSHOT}"
    php artisan demo:ensure-users --verify-only
    echo "Verified demo snapshot is ready."
    php artisan up
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
