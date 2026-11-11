#!/bin/sh
# DEMO ONLY: restore the saved baseline into the demo database atomically.
#
# Everything (dropping the old schema and creating the saved objects) runs in
# ONE transaction, so a failure leaves the previous data untouched instead of
# an empty database.
#
# The archive can be written by a newer pg_dump than the server it is restored
# to (for example an Alpine PostgreSQL 17 client against postgres:16). Newer
# clients emit SET commands that older servers reject ("unrecognized
# configuration parameter"), so those lines are removed from the script.
#
# Usage: demo-restore-db.sh /path/to/baseline.dump   (PG* variables must be set)
set -eu

SNAPSHOT="${1:?usage: demo-restore-db.sh SNAPSHOT}"
# The CASCADE drop is expected; do not print one NOTICE line per table.
export PGOPTIONS="${PGOPTIONS:-} -c client_min_messages=warning"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

pg_restore --no-owner --no-acl --file="${WORK}/restore.sql" "${SNAPSHOT}"

{
    echo 'BEGIN;'
    echo 'DROP SCHEMA IF EXISTS public CASCADE;'
    echo 'CREATE SCHEMA public;'
    # Settings introduced after PostgreSQL 16 that a 16 server does not know.
    grep -v -E '^SET (transaction_timeout) = ' "${WORK}/restore.sql"
    echo 'COMMIT;'
} > "${WORK}/restore.filtered.sql"

psql -X -q -v ON_ERROR_STOP=1 --file="${WORK}/restore.filtered.sql" > /dev/null
