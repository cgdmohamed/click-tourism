#!/bin/sh
# Rehearsal 1/3: fresh install on an empty PostgreSQL database.
#
#   DB_USERNAME=erp DB_PASSWORD=... sh scripts/release/rehearse-fresh-install.sh
#
# Proves: migrations apply cleanly; demo seeding is refused in production mode;
# `erp:install` fails closed without an admin password, installs exactly one
# administrator, runs once only, and leaves no demo accounts, no known
# passwords and no customer-specific data; the (empty) ledger verifies.
set -eu
. "$(dirname -- "$0")/lib.sh"

DB=$(new_db fresh) || die "cannot create scratch database (does $DB_USERNAME have CREATEDB?)"
echo "Scratch database: $DB"

check "migrate --force on empty database" artisan "$DB" migrate --force

if [ "$(pending_migrations "$DB")" = "0" ]; then
    record "no pending migrations after migrate" PASS ""
else
    record "no pending migrations after migrate" FAIL "migrate:status still lists pending migrations"
fi

check_fails "demo seeding (db:seed) is refused in production mode" artisan "$DB" db:seed --force
check "no user exists after the refused seed" sh -c "[ \"\$(psql -X -At -d $DB -c 'select count(*) from users')\" = 0 ]"

check_fails "erp:install without an admin password fails closed" \
    artisan "$DB" erp:install --no-interaction --company-name="Rehearsal Travel Co" \
    --base-currency=EGP --admin-email=owner@rehearsal.invalid
check "still no user and no chart of accounts after the refused install" \
    sh -c "[ \"\$(psql -X -At -d $DB -c 'select (select count(*) from users) + (select count(*) from accounts)')\" = 0 ]"

check "erp:install with a random one-time password" install_company "$DB"
check_fails "second erp:install is refused" install_company "$DB"

check "erp:verify-credentials (no known/default passwords, no demo accounts)" artisan "$DB" erp:verify-credentials
check "erp:verify-ledger on the new installation" artisan "$DB" erp:verify-ledger

check "exactly one user, an active admin, none at @example.com" sh -c "
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from users\")\" = 1 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from users where email like '%@example.com'\")\" = 0 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from model_has_roles m join roles r on r.id = m.role_id where r.name = 'admin'\")\" = 1 ]"

check "reference data present (accounts, one branch, open fiscal period, base currency)" sh -c "
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from accounts\")\" -gt 20 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from branches\")\" = 1 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select count(*) from fiscal_periods where status = 'open'\")\" -ge 12 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select value from settings where \\\"group\\\" = 'general' and key = 'base_currency'\")\" = EGP ]"

check "no customer-specific data (no 'Safe Way' text, no sample customers/bookings)" sh -c "
    [ \"\$(pg_dump --data-only --inserts $DB | grep -ciE 'safe[ _-]?way')\" = 0 ] &&
    [ \"\$(psql -X -At -d $DB -c \"select (select count(*) from customers) + (select count(*) from suppliers) + (select count(*) from bookings)\")\" = 0 ]"

check "accounting policy is NOT auto-approved on a new installation" sh -c "
    [ \"\$(psql -X -At -d $DB -c \"select value from settings where \\\"group\\\" = 'accounting' and key = 'accounting_policy_approved'\")\" = false ]"

# HTTP smoke test with PHP's built-in server: the admin can log in, defaults cannot.
PORT=${REHEARSAL_PORT:-18099}
if command -v curl >/dev/null 2>&1; then
    (
        cd "$BACKEND" &&
            env DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$DB" DB_USERNAME="$DB_USERNAME" \
                DB_PASSWORD="$DB_PASSWORD" APP_ENV=production APP_DEBUG=false APP_KEY="$APP_KEY" CACHE_STORE=array \
                SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr \
                php -S "127.0.0.1:$PORT" -t public >"$WORK/server.log" 2>&1 &
        echo $! >"$WORK/server.pid"
    )
    sleep 2
    login_status() { # login_status <email> <password> -> HTTP status
        curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H 'Content-Type: application/json' \
            -d "{\"email\":\"$1\",\"password\":\"$2\"}" "http://127.0.0.1:$PORT/api/v1/auth/login"
    }
    check "HTTP: /api/v1/ready answers 200" sh -c "[ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PORT/api/v1/ready)\" = 200 ]"
    if [ "$(login_status owner@rehearsal.invalid "$(cat "$WORK/admin.pw")")" = "200" ]; then
        record "HTTP: administrator can log in with the install-time password" PASS ""
    else
        record "HTTP: administrator can log in with the install-time password" FAIL "login did not return 200"
    fi
    for pair in "owner@rehearsal.invalid:password" "owner@rehearsal.invalid:change-me" "admin@example.com:password"; do
        if [ "$(login_status "${pair%%:*}" "${pair#*:}")" != "200" ]; then
            record "HTTP: login refused for ${pair%%:*} with '${pair#*:}'" PASS ""
        else
            record "HTTP: login refused for ${pair%%:*} with '${pair#*:}'" FAIL "a default credential works"
        fi
    done
    kill "$(cat "$WORK/server.pid")" 2>/dev/null || true
else
    record "HTTP login smoke test" UNVERIFIED "curl is not installed on this machine"
fi

summary "Fresh install rehearsal"
