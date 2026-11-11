#!/bin/sh
# Regression test for the demo reset (run in CI against PostgreSQL 16):
#  1. the baseline restores into a modified database,
#  2. it still restores when the archive was written by a newer pg_dump
#     (adds "SET transaction_timeout", unknown to PostgreSQL 16),
#  3. a failing restore keeps the existing data (single transaction).
# Needs PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE and a migrated database.
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

q() { psql -X -Atq -c "$1"; }
tables() { q "select count(*) from information_schema.tables where table_schema='public'"; }

pg_dump --format=custom --no-owner --no-acl --file="${WORK}/base.dump"
BASE="$(tables)"
test "${BASE}" -gt 10

# 1. restore over a modified database
q "create table demo_scratch(a int)"
sh "${HERE}/demo-restore-db.sh" "${WORK}/base.dump"
test "$(tables)" = "${BASE}"
test "$(q "select to_regclass('public.demo_scratch') is null")" = "t"
echo "ok: restore over a modified database"

# 2. archive from a newer pg_dump
REAL="$(command -v pg_restore)"
mkdir -p "${WORK}/newer"
cat > "${WORK}/newer/pg_restore" <<SHIM
#!/bin/sh
"${REAL}" "\$@"; rc=\$?
for a in "\$@"; do case "\$a" in --file=*) sed -i '1i SET transaction_timeout = 0;' "\${a#--file=}";; esac; done
exit \$rc
SHIM
chmod +x "${WORK}/newer/pg_restore"
PATH="${WORK}/newer:${PATH}" sh "${HERE}/demo-restore-db.sh" "${WORK}/base.dump"
test "$(tables)" = "${BASE}"
echo "ok: restore of an archive written by a newer pg_dump"

# 3. a failing restore must not leave the database empty
mkdir -p "${WORK}/broken"
cat > "${WORK}/broken/pg_restore" <<SHIM
#!/bin/sh
"${REAL}" "\$@"; rc=\$?
for a in "\$@"; do case "\$a" in --file=*) echo 'SELECT 1/0;' >> "\${a#--file=}";; esac; done
exit \$rc
SHIM
chmod +x "${WORK}/broken/pg_restore"
if PATH="${WORK}/broken:${PATH}" sh "${HERE}/demo-restore-db.sh" "${WORK}/base.dump" 2>/dev/null; then
    echo "FAIL: a broken restore reported success" >&2
    exit 1
fi
test "$(tables)" = "${BASE}"
echo "ok: a failing restore keeps the existing data"
