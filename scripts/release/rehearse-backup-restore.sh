#!/bin/sh
# Rehearsal 2/3: logical backup and restore into a SEPARATE database.
#
#   DB_USERNAME=erp DB_PASSWORD=... sh scripts/release/rehearse-backup-restore.sh
#
# Builds a fresh installation with a three-entry ledger, takes a plain-SQL
# gzip backup exactly like docs/architecture/OPERATIONS.md (optionally
# GPG-encrypted when gpg is available), restores it into a different database
# and requires the ledger fingerprint (entry/line counts, totals, per-account
# digest, per-entry digest) to be identical. A negative control proves the
# checker really fails on an unbalanced ledger. Never touches an existing DB.
set -eu
. "$(dirname -- "$0")/lib.sh"

SRC=$(new_db source) || die "cannot create scratch database"
DST=$(new_db restored) || die "cannot create scratch database"
BACKUP="$WORK/backup.sql.gz"
echo "Source: $SRC   Restore target: $DST"

check "migrate --force" artisan "$SRC" migrate --force
check "erp:install" install_company "$SRC"
check "load balanced ledger fixture (3 entries)" sql_file "$SRC" "$FIXTURES/ledger-entries.sql"
check "erp:verify-ledger before backup" ledger_report "$SRC" "$WORK/before.json"
check "ledger is non-empty (3 entries, 6 lines)" sh -c "php -r '
    \$r = json_decode(file_get_contents(\$argv[1]), true);
    exit(\$r[\"fingerprint\"][\"entries\"] === 3 && \$r[\"fingerprint\"][\"lines\"] === 6 ? 0 : 1);' '$WORK/before.json'"

backup_plain() {
    pg_dump --no-owner --no-acl "$SRC" | gzip -c >"$BACKUP" && gzip -t "$BACKUP" && [ -s "$BACKUP" ]
}
check "pg_dump + gzip backup, archive integrity (gzip -t)" backup_plain

if command -v gpg >/dev/null 2>&1; then
    export GNUPGHOME="$WORK/gnupg"
    mkdir -m 700 "$GNUPGHOME"
    head -c 32 /dev/urandom | base64 >"$WORK/pass"
    chmod 600 "$WORK/pass"
    encrypt_roundtrip() {
        gpg --batch --yes --pinentry-mode loopback --passphrase-file "$WORK/pass" --symmetric --cipher-algo AES256 \
            -o "$BACKUP.gpg" "$BACKUP" &&
            gpg --batch --pinentry-mode loopback --passphrase-file "$WORK/pass" --decrypt "$BACKUP.gpg" 2>/dev/null | gzip -t &&
            gpg --batch --pinentry-mode loopback --passphrase-file "$WORK/pass" --decrypt "$BACKUP.gpg" 2>/dev/null >"$BACKUP.plain" &&
            cmp "$BACKUP" "$BACKUP.plain"
    }
    check "GPG AES256 encrypt/decrypt round trip is lossless" encrypt_roundtrip
else
    record "GPG AES256 encrypt/decrypt round trip is lossless" UNVERIFIED "gpg is not installed on this machine"
fi

restore_plain() {
    gunzip -c "$BACKUP" | psql -X -q -v ON_ERROR_STOP=1 -d "$DST" -o /dev/null
}
check "restore into a separate empty database (psql ON_ERROR_STOP)" restore_plain
check "erp:verify-ledger on the restored database" ledger_report "$DST" "$WORK/after.json"
check "ledger fingerprint identical before and after restore" same_fingerprint "$WORK/before.json" "$WORK/after.json"
if [ "$(pending_migrations "$DST")" = "0" ]; then
    record "restored database has no pending migrations" PASS ""
else
    record "restored database has no pending migrations" FAIL "migrate:status lists pending migrations"
fi
check "row counts equal for users/accounts/journal_entries/journal_lines/settings" sh -c "
    for t in users accounts journal_entries journal_lines settings; do
        a=\$(psql -X -At -d $SRC -c \"select count(*) from \$t\"); b=\$(psql -X -At -d $DST -c \"select count(*) from \$t\");
        [ \"\$a\" = \"\$b\" ] || { echo \"\$t: \$a != \$b\"; exit 1; }
    done"
check "erp:verify-credentials on the restored database" artisan "$DST" erp:verify-credentials

# Negative control: the checker must catch a corrupted ledger.
sql "$DST" "insert into journal_lines (journal_entry_id, account_id, branch_id, debit_minor, credit_minor, base_debit_minor, base_credit_minor, currency_code, created_at, updated_at)
    select e.id, a.id, e.branch_id, 100, 0, 100, 0, 'EGP', now(), now() from journal_entries e, accounts a where e.number = 'RH-0001' and a.code = '1110' limit 1" >/dev/null
check_fails "negative control: verify-ledger rejects an unbalanced ledger" artisan "$DST" erp:verify-ledger

summary "Backup and restore rehearsal"
