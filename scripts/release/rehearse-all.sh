#!/bin/sh
# Runs the three release rehearsals in order and prints one verdict.
#
#   DB_USERNAME=erp DB_PASSWORD=... sh scripts/release/rehearse-all.sh
#
# Exit code 0 only when every rehearsal passed. Needs a PostgreSQL role with
# CREATEDB; every scratch database is dropped on exit (REHEARSAL_KEEP=1 keeps them).
set -u
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
status=0
for script in rehearse-fresh-install.sh rehearse-backup-restore.sh rehearse-upgrade.sh; do
    echo
    echo "##### $script"
    if ! sh "$DIR/$script"; then
        status=1
    fi
done
echo
if [ "$status" -eq 0 ]; then
    echo "ALL REHEARSALS: PASS"
else
    echo "ALL REHEARSALS: FAIL"
fi
exit "$status"
