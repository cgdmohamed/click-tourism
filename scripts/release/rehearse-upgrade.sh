#!/bin/sh
# Rehearsal 3/3: N-1 -> N upgrade simulation and rollback (restore) procedure.
#
#   DB_USERNAME=erp DB_PASSWORD=... sh scripts/release/rehearse-upgrade.sh
#
# 1. Builds the PREVIOUS schema in a scratch database. Two ways to define "N-1":
#      PREV_REF=<git tag/commit>   migrations as of that ref (the real previous
#                                  release; needs the ref in the local clone), or
#      UPGRADE_WITHHOLD=<K> (3)    the current migrations minus the last K files,
#                                  a portable stand-in when no tag exists yet.
# 2. Loads reference data and a balanced ledger, records the ledger fingerprint
#    and takes the PRE-UPGRADE BACKUP.
# 3. Upgrades with the current code: `php artisan migrate --force` (forward only).
# 4. Requires: no pending migrations, ledger valid, fingerprint unchanged,
#    a second migrate is a no-op.
# 5. Rollback rehearsal: restores the pre-upgrade backup into a NEW database and
#    requires the fingerprint to equal the pre-upgrade one and the schema to be
#    N-1 again (pending migrations present). `migrate:rollback` is never used:
#    down() migrations are not a data-safe rollback path for a ledger.
set -eu
. "$(dirname -- "$0")/lib.sh"

K=${UPGRADE_WITHHOLD:-3}
PREV_DIR="$WORK/previous-migrations"
mkdir -p "$PREV_DIR"

if [ -n "${PREV_REF:-}" ]; then
    require_cmd git
    (cd "$ROOT" && git archive "$PREV_REF" backend/database/migrations) | tar -x -C "$WORK" -f -
    cp "$WORK"/backend/database/migrations/*.php "$PREV_DIR"/
    MODE="PREV_REF=$PREV_REF"
else
    TOTAL=$(ls "$BACKEND"/database/migrations/*.php | wc -l)
    KEEP=$((TOTAL - K))
    [ "$KEEP" -ge 1 ] || die "UPGRADE_WITHHOLD=$K is not smaller than the $TOTAL migrations"
    ls "$BACKEND"/database/migrations/*.php | sort | head -n "$KEEP" | while read -r f; do cp "$f" "$PREV_DIR"/; done
    MODE="current migrations minus the last $K"
fi
echo "N-1 schema defined as: $MODE ($(ls "$PREV_DIR" | wc -l) migrations)"

DB=$(new_db upgrade) || die "cannot create scratch database"
RB=$(new_db rollback) || die "cannot create scratch database"
echo "Upgrade database: $DB   Rollback target: $RB"

check "N-1: migrate previous-release migrations" artisan "$DB" migrate --force --path="$PREV_DIR" --realpath
check "N-1: load reference data" sql_file "$DB" "$FIXTURES/reference-data.sql"
check "N-1: load balanced ledger fixture" sql_file "$DB" "$FIXTURES/ledger-entries.sql"
check "N-1: erp:verify-ledger (current code on previous schema)" ledger_report "$DB" "$WORK/before.json"

BACKUP="$WORK/pre-upgrade.sql.gz"
pre_upgrade_backup() {
    pg_dump --no-owner --no-acl "$DB" | gzip -c >"$BACKUP" && gzip -t "$BACKUP" && [ -s "$BACKUP" ]
}
check "PRE-UPGRADE BACKUP taken and verified (gzip -t)" pre_upgrade_backup

PENDING=$(pending_migrations "$DB")
if [ "$PENDING" -gt 0 ]; then
    record "upgrade is exercised (pending migrations before upgrade: $PENDING)" PASS ""
else
    record "upgrade is exercised (pending migrations before upgrade: $PENDING)" FAIL "nothing to migrate; N-1 equals N"
fi

check "UPGRADE: migrate --force with the current release" artisan "$DB" migrate --force
if [ "$(pending_migrations "$DB")" = "0" ]; then
    record "no pending migrations after upgrade" PASS ""
else
    record "no pending migrations after upgrade" FAIL "pending migrations remain"
fi
second_migrate_is_noop() {
    artisan "$DB" migrate --force 2>&1 | grep -qi 'nothing to migrate'
}
check "second migrate --force reports 'Nothing to migrate'" second_migrate_is_noop
check "erp:verify-ledger after upgrade" ledger_report "$DB" "$WORK/after.json"
check "ledger fingerprint unchanged by the upgrade" same_fingerprint "$WORK/before.json" "$WORK/after.json"
check "roles:sync after upgrade (release step)" artisan "$DB" roles:sync

# Rollback rehearsal = restore the pre-upgrade backup into a clean database.
rollback_restore() {
    gunzip -c "$BACKUP" | psql -X -q -v ON_ERROR_STOP=1 -d "$RB" -o /dev/null
}
check "ROLLBACK: restore pre-upgrade backup into a new database" rollback_restore
check "ROLLBACK: erp:verify-ledger on the restored database" ledger_report "$RB" "$WORK/rollback.json"
check "ROLLBACK: ledger fingerprint equals the pre-upgrade one" same_fingerprint "$WORK/before.json" "$WORK/rollback.json"
if [ "$(pending_migrations "$RB")" = "$PENDING" ]; then
    record "ROLLBACK: restored schema is N-1 again (pending=$PENDING), previous image can run" PASS ""
else
    record "ROLLBACK: restored schema is N-1 again (pending=$PENDING), previous image can run" FAIL "unexpected pending count"
fi

summary "Upgrade and rollback rehearsal"
