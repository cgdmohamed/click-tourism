# Release checklist and gate

One installation per customer, one codebase. A release is **not** ready because
CI is green: every row below needs a recorded result. Companion documents:
[`clean-install-acceptance.md`](clean-install-acceptance.md) (first install),
[`../architecture/OPERATIONS.md`](../architecture/OPERATIONS.md) (backup policy,
RPO/RTO targets, health checks), [`coolify.md`](coolify.md).

Status legend: **PASS** executed and verified, **FAIL** executed and failed,
**UNVERIFIED** not executed where this document was written (a sandbox without a
Docker daemon, without a registry and without a release tag), **PENDING**
needs a human decision. Results marked "2026-11-12" were produced on PostgreSQL
16.15, PHP 8.3.6.

Rules that never bend: no `migrate:fresh` on customer data, no `migrate:rollback`
as a rollback path, no direct edit of posted journal entries, no deployment
without the owner's explicit approval, no secret in the repository or an image.

## 1. Gate summary (record one row per release candidate)

| # | Gate | Command | Expected | Status (2026-11-12) |
| --- | --- | --- | --- | --- |
| 1 | Backend tests, SQLite | `cd backend && ./vendor/bin/phpunit` | `OK (701 tests, 4878 assertions)` | PASS |
| 2 | Backend tests, fresh PostgreSQL 16 | `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=<new empty db> DB_USERNAME=... DB_PASSWORD=... ./vendor/bin/phpunit` | all pass | FAIL: 3 errors, all in `DocumentNumberAuditTest` (its fixture writes a `public_id` longer than the `char(26)` column; PostgreSQL rejects it); pre-existing, not part of CI's PostgreSQL filter, not caused by this change. The other 698 results are 696 passes and 2 skips (SQLite-only orphan fixtures) |
| 3 | Code style | `cd backend && ./vendor/bin/pint --test` | `{"result":"passed"}` | PASS |
| 4 | Shell syntax | `for f in backend/docker/*.sh scripts/release/*.sh; do sh -n "$f"; done` | no output | PASS |
| 5 | Compose files render | `docker compose -f docker-compose.coolify.yml config --quiet` (and the demo file) with the CI variables | exit 0; root `docker-compose.yml` exits 1 without `POSTGRES_PASSWORD` | PASS |
| 6 | Release rehearsals | `sh scripts/release/rehearse-all.sh` | `ALL REHEARSALS: PASS` (about 25 s) | PASS |
| 7 | Secrets and default credentials | `sh scripts/release/scan-secrets.sh` | `SECRET SCAN: PASS` | PASS (repository and build contexts) |
| 8 | Built image inspected | `IMAGE=<backend image> sh scripts/release/scan-secrets.sh` | no finding | UNVERIFIED (no Docker daemon here; runs in CI) |
| 9 | Dependency audit | `cd backend && composer audit` / `cd web && npm audit --audit-level=moderate` | no advisories | composer PASS ("No security vulnerability advisories found"); npm UNVERIFIED (web untouched, runs in CI) |
| 10 | Frontend lint, tests, build | `cd web && npm run lint && npm test && npm run build` | green | UNVERIFIED (runs in CI) |
| 11 | CI on the release commit | GitHub Actions `CI` | all jobs green | UNVERIFIED until the pull request runs |
| 12 | Real staging restore drill from a production-like backup | section 3 on a staging server | fingerprint identical | UNVERIFIED |
| 13 | Upgrade between two tagged releases on staging | section 4 | fingerprint identical | UNVERIFIED (no tag exists; N-1 simulated, section 2) |
| 14 | Accountant sign-off of provisional accounting policy | Settings, decisions | `PENDING_ACCOUNTANT_APPROVAL` cleared by an accountant | PENDING |

## 2. Scripted rehearsals

Prerequisites: PostgreSQL 16 reachable, `php`, `psql`, `pg_dump`, `gzip`; optional
`gpg` and `curl`. The role needs `CREATEDB`. The scripts only create, restore into
and drop databases named `erp_rehearsal_<run>_<role>` and delete their temp
directory on exit (`REHEARSAL_KEEP=1` keeps them). Never point them at production.

```bash
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME=erp DB_PASSWORD=...   # scratch server only
sh scripts/release/rehearse-fresh-install.sh      # section 2.1
sh scripts/release/rehearse-backup-restore.sh     # section 2.2
sh scripts/release/rehearse-upgrade.sh            # section 2.3
sh scripts/release/rehearse-all.sh                # all three, one verdict
```

All three run in CI on every pull request (`postgres-integration` job).

### 2.1 Fresh install
Output and meaning: see [`clean-install-acceptance.md`](clean-install-acceptance.md) section 3. 19 steps, PASS.

### 2.2 Backup and restore, ledger unchanged
Builds an installed company, loads three balanced entries, takes
`pg_dump --no-owner --no-acl | gzip`, checks `gzip -t`, round-trips the archive
through GPG AES256 (like OPERATIONS.md), restores into a **different** database,
and requires `erp:verify-ledger` to pass with an identical `fingerprint`
(entry and line counts, base debit/credit totals, per-account digest, per-entry
digest). A negative control then inserts an unbalanced line into the restored
copy and requires `erp:verify-ledger` to fail.

```text
=== Backup and restore rehearsal ===
| migrate --force | PASS |
| erp:install | PASS |
| load balanced ledger fixture (3 entries) | PASS |
| erp:verify-ledger before backup | PASS |
| ledger is non-empty (3 entries, 6 lines) | PASS |
| pg_dump + gzip backup, archive integrity (gzip -t) | PASS |
| GPG AES256 encrypt/decrypt round trip is lossless | PASS |
| restore into a separate empty database (psql ON_ERROR_STOP) | PASS |
| erp:verify-ledger on the restored database | PASS |
| ledger fingerprint identical before and after restore | PASS |
| restored database has no pending migrations | PASS |
| row counts equal for users/accounts/journal_entries/journal_lines/settings | PASS |
| erp:verify-credentials on the restored database | PASS |
| negative control: verify-ledger rejects an unbalanced ledger | PASS |
Backup and restore rehearsal: PASS
```

### 2.3 Upgrade N-1 to N and rollback
"N-1" is defined by `PREV_REF=<tag or commit>` (migrations exactly as of that ref;
verified with `PREV_REF=c519f41`, one pending migration) or, when no tag exists,
by `UPGRADE_WITHHOLD=<K>` (default 3: the current migrations minus the last K
files; verified, 3 pending migrations). Steps: build the N-1 schema, load
reference data and a balanced ledger, record the fingerprint, take the
**pre-upgrade backup**, upgrade with `php artisan migrate --force`, require no
pending migrations, a second migrate that reports `Nothing to migrate`, a valid
ledger with the same fingerprint and `roles:sync`; then **rollback by restore**:
restore the pre-upgrade backup into a new database, require the same fingerprint
and an N-1 schema again (pending migrations present, so the previous image runs).

```text
=== Upgrade and rollback rehearsal ===
| N-1: migrate previous-release migrations | PASS |
| N-1: load reference data | PASS |
| N-1: load balanced ledger fixture | PASS |
| N-1: erp:verify-ledger (current code on previous schema) | PASS |
| PRE-UPGRADE BACKUP taken and verified (gzip -t) | PASS |
| upgrade is exercised (pending migrations before upgrade: 3) | PASS |
| UPGRADE: migrate --force with the current release | PASS |
| no pending migrations after upgrade | PASS |
| second migrate --force reports 'Nothing to migrate' | PASS |
| erp:verify-ledger after upgrade | PASS |
| ledger fingerprint unchanged by the upgrade | PASS |
| roles:sync after upgrade (release step) | PASS |
| ROLLBACK: restore pre-upgrade backup into a new database | PASS |
| ROLLBACK: erp:verify-ledger on the restored database | PASS |
| ROLLBACK: ledger fingerprint equals the pre-upgrade one | PASS |
| ROLLBACK: restored schema is N-1 again (pending=3), previous image can run | PASS |
Upgrade and rollback rehearsal: PASS
```

Limits, stated plainly: the ledger is a three-entry fixture, not production
volume; the fixture is written with SQL, so it proves the migrations preserve
data, not that every posting service works on the old schema; the first real
release tag will make `PREV_REF=<previous tag>` the correct choice.

### 2.4 The ledger check
`php artisan erp:verify-ledger [--json] [--connection=name]` is read-only and uses
raw queries on `journal_entries`, `journal_lines` and `accounts` only (so it runs
on the previous schema too). Exit code 0 only when all seven checks pass:

| Check | Fails when |
| --- | --- |
| `entries_balanced_base` | an entry's base debits differ from its base credits |
| `entries_balanced_transaction_currency` | a single-currency entry's debits differ from its credits |
| `trial_balance_zero` | posted base debits minus credits across the ledger is not 0 |
| `no_orphan_lines` | a line references a missing entry, account or branch |
| `no_empty_posted_entries` | a posted entry has no lines |
| `no_invalid_lines` | a line is negative, two-sided or all zero |
| `no_dangling_reversals` | `reversal_of_id` points at a missing entry |

Sample (empty ledger):

```text
entries_balanced_base                    PASS
entries_balanced_transaction_currency    PASS
trial_balance_zero                       PASS
no_orphan_lines                          PASS
no_empty_posted_entries                  PASS
no_invalid_lines                         PASS
no_dangling_reversals                    PASS
entries=0 lines=0 debit=0 credit=0 accounts_digest=e3b0c44298fc1c14
LEDGER OK
```

`erp:verify-credentials` (exit 1 on any user with a known default password or a
demo `@example.com` account outside demo mode) completes the credential gate.

## 3. Production backup and restore drill (server commands)

Policy, retention, encryption and the full restore text are in
[`OPERATIONS.md`](../architecture/OPERATIONS.md); this is the verification part.
Run on **staging** with a copy of a real backup. Never restore over production.

```bash
# 1. Fingerprint of the live ledger (read-only).
docker compose exec -T backend php artisan erp:verify-ledger --json > backups/ledger-live.json
# 2. Restore the backup into a separate database (OPERATIONS.md section 2) on the staging stack, then:
docker compose exec -T backend sh -c 'DB_DATABASE=tourism_erp_restore_drill php artisan erp:verify-ledger --json' > backups/ledger-restored.json
# 3. Compare (bash). Must print nothing.
diff <(sed -n '/"fingerprint"/,$p' backups/ledger-live.json) <(sed -n '/"fingerprint"/,$p' backups/ledger-restored.json)
```

Pass: both reports have `"passed": true` and the diff is empty. If the backup is
older than the live data the fingerprints legitimately differ; compare against a
report taken immediately before the backup. Attachments (`storage/app`) are
restored from `backups/storage-*.tar.gz` and spot-checked by opening a document:
UNVERIFIED (no attachment feature was exercised).

## 4. Release and upgrade runbook (customer server)

Pre-release (all must be true before the window):

- [ ] Gates 1-11 above are PASS for the exact tag; the tag is `vMAJOR.MINOR.PATCH`.
- [ ] Images are built from that tag and pinned by tag (never `latest`); previous tag recorded: `________`.
- [ ] Release notes list every migration and every new setting/permission; reviewed with the customer.
- [ ] Fresh backup taken and `gzip -t` verified (OPERATIONS.md section 1); location: `________`.
- [ ] `.env` and nginx config backed up and encrypted.
- [ ] `erp:verify-ledger --json` saved as `backups/ledger-before.json` and its `passed` is `true`.
- [ ] Maintenance window communicated; owner's written approval to deploy: `________`.

```bash
# 1. Stop background work so no financial job runs mid-migration.
docker compose --profile workers stop worker scheduler
# 2. Maintenance mode.
docker compose exec backend php artisan down --message="Maintenance" --retry=60
# 3. Fresh backup and the "before" fingerprint (see above), then pull the pinned release.
docker compose pull            # or: git checkout vX.Y.Z && docker compose build
# 4. Start the new backend. With RUN_MIGRATIONS=true the entrypoint runs `php artisan migrate --force`,
#    then (unless RUN_UPGRADE_TASKS=false) `php artisan roles:sync` and `php artisan accounting:ensure-accounts --apply`.
docker compose up -d --no-deps backend api web
docker compose logs --tail=50 backend
# 5. Release steps.
docker compose exec backend php artisan migrate:status --pending      # expect no Pending rows
docker compose exec backend php artisan roles:sync
docker compose exec -T backend php artisan erp:verify-ledger --json > backups/ledger-after.json
diff <(sed -n '/"fingerprint"/,$p' backups/ledger-before.json) <(sed -n '/"fingerprint"/,$p' backups/ledger-after.json)   # empty
docker compose exec backend php artisan erp:verify-credentials
# 6. Health, then reopen.
curl -fsS http://localhost:8080/api/v1/ready && curl -fsS http://localhost:8080/api/v1/health
docker compose exec backend php artisan up
docker compose --profile workers up -d worker scheduler
```

Smoke test after reopening: log in as the administrator, open the trial balance
and the general ledger for the current period, create and post a test journal
entry on a staging copy only (never invent entries in a customer ledger), confirm
`/api/v1/health` reports database, cache, Redis and scheduler OK.

Workers and scheduler: only one `scheduler` container may run per installation.
The scheduled accounting jobs use `withoutOverlapping()` (cache lock, so Redis
must stay up) and postings are protected by the unique index
`journal_entries(source_type, source_id, event)`, so a repeated job cannot post
twice. This is design evidence, not a test of two concurrent schedulers across an
upgrade: UNVERIFIED. Stopping the workers before migrating and starting them after
is the procedure that does not rely on it.

## 5. Rollback

Decision rule: if any step in section 4 fails, or the fingerprint differs, or
`erp:verify-ledger` fails, **stop and roll back**. Do not "fix forward" a ledger
mismatch on a customer server.

```bash
# 1. Keep the site closed.
docker compose exec backend php artisan down --message="Maintenance" --retry=60   # if not already down
docker compose --profile workers stop worker scheduler
# 2. Preserve the failed state for diagnosis (do not drop it).
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' | gzip -c > backups/failed-upgrade-$(date +%Y%m%d-%H%M%S).sql.gz
# 3. Put the previous images back (previous tag from the pre-release checklist).
git checkout vPREVIOUS && docker compose up -d --build --no-deps backend api web      # RUN_MIGRATIONS=false for this start
# 4. Restore the PRE-UPGRADE backup into a NEW database, verify it, then switch the application to it
#    (OPERATIONS.md section 2; change POSTGRES_DB or rename databases during the window with a DBA).
# 5. Verify exactly like section 2.2: erp:verify-ledger passes and the fingerprint equals ledger-before.json.
# 6. Reopen and re-run the smoke test.
docker compose exec backend php artisan up
```

Why a restore and not `php artisan migrate:rollback`: `down()` methods drop columns
and tables, which destroys data written since the upgrade, and a ledger must never
be rewritten in place. Data entered between the upgrade and the rollback is lost
by design; the maintenance window keeps that gap at zero. Rehearsed by
`rehearse-upgrade.sh` (section 2.3): PASS. On a real server: UNVERIFIED.

## 6. Release record (copy per release)

| Field | Value |
| --- | --- |
| Tag / previous tag | |
| Image digests | |
| Migrations in this release | |
| Backup file and `gzip -t` result | |
| `ledger-before.json` / `ledger-after.json` fingerprints equal | |
| Rehearsal output attached (`rehearse-all.sh`) | |
| Secret scan output attached | |
| Owner approval to deploy (name, date) | |
| Accountant sign-off recorded | |
| Result | PASS / FAIL / ROLLED BACK |

## 7. Known gaps (honest list)

- No release tag or registry image exists yet; N-1 is simulated (section 2.3).
- Docker images were not built or inspected here; CI builds them and runs the image scan.
- Worker and scheduler coordination during an upgrade, Redis persistence after a restart, attachment backup/restore and the TLS reverse proxy were not exercised.
- The `DEMO_PASSWORD` default `password` remains only in `docker-compose.demo.coolify.yml` for the disposable public demo; it is annotated for the scanner and must never be copied to a production file.
- `DocumentNumberAuditTest` fails on PostgreSQL (see gate 2); fix the fixture before running the whole suite on PostgreSQL in CI.
