#!/bin/sh
# Shared helpers for the release rehearsals. Source this file; do not run it.
#
# Required environment (PostgreSQL account that may CREATE/DROP DATABASE):
#   DB_USERNAME, DB_PASSWORD   and optionally DB_HOST (127.0.0.1), DB_PORT (5432)
# Optional:
#   REHEARSAL_KEEP=1   keep the scratch databases for inspection
#   APP_KEY            otherwise an ephemeral key is generated for the run
#
# Safety: scratch databases are always named erp_rehearsal_<run>_<role>. Nothing
# else is ever created, dropped or restored over. Never point DB_* at production
# credentials; the account needs CREATEDB and nothing else.

REL_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$REL_DIR/../.." && pwd)
BACKEND="$ROOT/backend"
FIXTURES="$REL_DIR/fixtures"

die() {
    echo "FATAL: $*" >&2
    exit 2
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

for _c in php psql pg_dump gzip; do
    require_cmd "$_c"
done

[ -n "${DB_USERNAME:-}" ] || die "set DB_USERNAME (a PostgreSQL role with CREATEDB)"
[ -n "${DB_PASSWORD:-}" ] || die "set DB_PASSWORD"
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-5432}
export PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USERNAME" PGPASSWORD="$DB_PASSWORD"

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
fi

RUN_ID="$(date +%s)$$"
WORK=$(mktemp -d "${TMPDIR:-/tmp}/erp-rehearsal.XXXXXX")
DB_LIST="$WORK/databases"
RESULTS="$WORK/results"
: >"$DB_LIST"
: >"$RESULTS"
FAILED=0

cleanup() {
    if [ "${REHEARSAL_KEEP:-0}" != "1" ]; then
        while read -r _db; do
            [ -n "$_db" ] && psql -X -q -d postgres -c "DROP DATABASE IF EXISTS \"$_db\" WITH (FORCE)" >/dev/null 2>&1
        done <"$DB_LIST"
        rm -rf "$WORK"
    else
        echo "REHEARSAL_KEEP=1: databases listed in $DB_LIST and files in $WORK were kept." >&2
    fi
}
trap cleanup EXIT INT TERM

# new_db <role>  -> prints the scratch database name (and creates it)
new_db() {
    _name="erp_rehearsal_${RUN_ID}_$1"
    psql -X -q -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE \"$_name\"" || return 1
    echo "$_name" >>"$DB_LIST"
    echo "$_name"
}

# artisan <database> <artisan arguments...>  (production-like environment)
artisan() {
    _db=$1
    shift
    (
        cd "$BACKEND" &&
            env DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$_db" \
                DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
                APP_ENV=production APP_DEBUG=false APP_KEY="$APP_KEY" \
                CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
                LOG_CHANNEL=stderr LOG_LEVEL=error DEMO_MODE=false \
                php artisan "$@"
    )
}

# install_company <database> : erp:install with a random one-time password. It is never
# printed; it is kept only in $WORK/admin.pw (mode 0600, inside the private temp dir that
# cleanup removes) so the login smoke test can use it.
install_company() {
    _pw="Rh$(head -c 24 /dev/urandom | base64 | tr -d '/+=\n')-9x"
    # Only the first (successful) install owns admin.pw; a refused repeat must not replace it.
    [ -f "$WORK/admin.pw" ] || (umask 077 && printf '%s' "$_pw" >"$WORK/admin.pw")
    [ -f "$WORK/admin.pw" ] && _pw=$(cat "$WORK/admin.pw")
    (
        ERP_ADMIN_PASSWORD="$_pw"
        export ERP_ADMIN_PASSWORD
        artisan "$1" erp:install --no-interaction \
            --company-name="Rehearsal Travel Co" --base-currency=EGP --fiscal-start=01-01 \
            --timezone=UTC --locale=en --admin-email=owner@rehearsal.invalid --admin-name="Rehearsal Owner"
    )
}

# sql <database> <sql text> : prints a single trimmed value
sql() {
    psql -X -At -v ON_ERROR_STOP=1 -d "$1" -c "$2"
}

# sql_file <database> <file>
sql_file() {
    psql -X -q -v ON_ERROR_STOP=1 -d "$1" -f "$2"
}

# ledger_report <database> <output file> : erp:verify-ledger --json; non-zero when the ledger fails
ledger_report() {
    artisan "$1" erp:verify-ledger --json >"$2"
}

# same_fingerprint <report a> <report b> : both reports passed and their fingerprints are identical
same_fingerprint() {
    php -r '
        $a = json_decode(file_get_contents($argv[1]), true);
        $b = json_decode(file_get_contents($argv[2]), true);
        $ok = ($a["passed"] ?? false) === true && ($b["passed"] ?? false) === true
            && isset($a["fingerprint"]) && ($a["fingerprint"] === ($b["fingerprint"] ?? null));
        fwrite(STDERR, $ok ? "fingerprints identical\n" : "FINGERPRINT MISMATCH\n" . json_encode([$a["fingerprint"] ?? null, $b["fingerprint"] ?? null]) . "\n");
        exit($ok ? 0 : 1);
    ' "$1" "$2"
}

# pending_migrations <database> : prints the number of pending migrations
pending_migrations() {
    artisan "$1" migrate:status --pending --no-ansi 2>/dev/null | grep -c 'Pending' || true
}

# record <step> <PASS|FAIL|UNVERIFIED> <detail>
record() {
    printf '%s|%s|%s\n' "$1" "$2" "$3" >>"$RESULTS"
    if [ "$2" = "FAIL" ]; then
        FAILED=1
    fi
}

# check <step> <command...> : PASS when the command succeeds, FAIL (with its output) otherwise
check() {
    _step=$1
    shift
    if "$@" >"$WORK/step.log" 2>&1; then
        record "$_step" PASS ""
    else
        record "$_step" FAIL "see output above"
        sed 's/^/    | /' "$WORK/step.log" >&2
    fi
}

# check_fails <step> <command...> : PASS when the command is REFUSED (non-zero exit)
check_fails() {
    _step=$1
    shift
    if "$@" >"$WORK/step.log" 2>&1; then
        record "$_step" FAIL "command succeeded but must be refused"
        sed 's/^/    | /' "$WORK/step.log" >&2
    else
        record "$_step" PASS ""
    fi
}

summary() {
    _title=$1
    echo
    echo "=== $_title ==="
    echo "| Step | Result | Detail |"
    echo "|------|--------|--------|"
    while IFS='|' read -r _s _r _d; do
        echo "| $_s | $_r | $_d |"
    done <"$RESULTS"
    echo
    if [ "$FAILED" -eq 0 ]; then
        echo "$_title: PASS"
    else
        echo "$_title: FAIL"
    fi
    return "$FAILED"
}
