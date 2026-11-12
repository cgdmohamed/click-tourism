# Clean install acceptance

Purpose: prove a brand-new customer server can be installed from the release
artifact with **no demo accounts, no known password and no customer-specific
data**, and that the installation is a neutral, single-company, self-hosted
instance. Run it for every release candidate and keep the filled-in record.
The upgrade/rollback gate is in [`release-checklist.md`](release-checklist.md).

Status legend: **PASS** executed and verified, **FAIL** executed and failed,
**UNVERIFIED** not executed in the environment that produced this document (a
sandbox without a Docker daemon). UNVERIFIED is not a pass; the release owner
must run those rows on a staging server before sign-off. The "Evidence" column
below was produced on 2026-11-12 against PostgreSQL 16.15, PHP 8.3.

## 1. First-run setup command

`erp:install` is the only supported way to create the first administrator. It
runs once, inside one transaction, and writes:

| Created | Detail |
| --- | --- |
| Company profile | `general.company_name_en/_ar`, `general.company_logo_path` (a relative path such as `branding/logo.png`; the file itself is placed in `storage/app/public`), `general.base_currency`, `general.locale`, `general.timezone`, `tax.enabled` (default off) |
| Fiscal calendar | `accounting.fiscal_year_start` (MM-DD) and the fiscal year containing today with 12 open periods |
| Chart of accounts | the product default chart, cash and bank accounts registered in the base currency, expense categories |
| Roles and permissions | `admin`, `accountant`, `senior_accountant`, `sales`, `viewer` |
| Reference data | one branch `HQ` (name configurable), currencies, CRM sources and pipeline stages, tax settings (`tax.enabled`, `tax.default_rate`, `tax.inclusive`; the installation has exactly one tax) |
| Initial administrator | role `admin`, attached to `HQ`; audit event `installation.completed` (no secrets) |

It never creates demo users, sample customers, bookings, a second branch or a
tax profile (tax is one rate in Settings, off with rate 0 by default). The accounting policy stays **unapproved**
(auto-posting off) until an accountant signs off in the application.

Fail-closed rules (all covered by `InstallCommandTest`):

- no administrator password -> exit 1, nothing written (there is no default password);
- the password is **never** a command-line argument. Sources, in order:
  `ERP_ADMIN_PASSWORD`, `ERP_ADMIN_PASSWORD_FILE`, masked prompt (interactive only);
- minimum 12 characters, at least 5 distinct characters, not a well-known
  default (`password`, `change-me`, `administrator`, ...), must not contain the
  e-mail name;
- refused in demo mode, refused when the schema is not migrated, refused when any
  user or the install marker already exists (runs once);
- invalid currency, fiscal start (`02-29` included), timezone, e-mail or a logo
  path that is a URL or contains `..` -> exit 1, nothing written.

Every option has an environment fallback for automation
(`ERP_COMPANY_NAME`, `ERP_COMPANY_NAME_AR`, `ERP_LOGO_PATH`, `ERP_BASE_CURRENCY`,
`ERP_FISCAL_START`, `ERP_TIMEZONE`, `ERP_LOCALE`, `ERP_TAX_ENABLED`, `ERP_TAX_RATE` (0-100), `ERP_TAX_INCLUSIVE`,
`ERP_BRANCH_NAME`, `ERP_ADMIN_NAME`, `ERP_ADMIN_EMAIL`). Supported base
currencies are the seeded ones: EGP, SAR, USD, EUR, AED, KWD. The base currency
is locked once the first journal entry is posted.

There is deliberately **no HTTP install endpoint**: an unauthenticated route that
creates an administrator is a takeover risk, so `docs/architecture/openapi.yaml`
is unchanged.

## 2. Exact commands (Docker Compose baseline)

```bash
# On the new server, in the release checkout/tag.
cp .env.example .env
# Edit .env: APP_KEY, APP_URL, POSTGRES_PASSWORD (long random values, never committed).
#   php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
#   openssl rand -hex 24
docker compose up -d --build
docker compose logs backend | tail -5
```

Expected: the backend logs `Running production migrations...` and then starts
PHP-FPM; the stack refuses to start with an empty or well-known
`POSTGRES_PASSWORD` (`docker compose config` prints
`required variable POSTGRES_PASSWORD is missing a value`; the entrypoint prints
`FATAL: DB_PASSWORD is empty or a well-known default`).

```bash
# Create the company and the first administrator (interactive, masked prompt).
docker compose exec backend php artisan erp:install \
  --company-name="Your Company" --company-name-ar="شركتك" \
  --logo-path=branding/logo.png --base-currency=EGP --fiscal-start=01-01 \
  --timezone=Africa/Cairo --locale=ar --admin-email=owner@your-company.example
```

Expected output (the password prompts are not echoed):

```text
Initial administrator password (minimum 12 characters)
Repeat the password
Installation complete.
Company:        Your Company
Base currency:  EGP
Fiscal start:   01-01
Administrator:  owner@your-company.example
No demo accounts or sample data were installed.
Accounting policy is NOT approved: auto-posting stays off until an accountant signs off.
Remove ERP_ADMIN_PASSWORD from the environment now that the account exists.
```

Non-interactive (CI/automation): provide the password for this one command only,
from a protected file that is mounted into the container (for example a Docker secret), and remove it afterwards:

```bash
docker compose exec -e ERP_ADMIN_PASSWORD_FILE=/run/secrets/erp_admin backend php artisan erp:install --no-interaction ...
```

Validate input without writing anything: add `--dry-run`.

```bash
docker compose exec backend php artisan erp:verify-credentials
docker compose exec backend php artisan erp:verify-ledger
curl -fsS http://localhost:8080/api/v1/ready
curl -fsS http://localhost:8080/api/v1/health
```

Expected: `CREDENTIALS OK: 1 user(s) checked against 16 known passwords.`,
`LEDGER OK` (seven `PASS` lines, `entries=0 lines=0`), HTTP 200 for both probes.

On Coolify the same command runs in the `backend` terminal
([`coolify.md`](coolify.md) section 1, step 6). The old `setup:admin <email>` is a
deprecated alias that delegates to `erp:install`.

## 3. Automated rehearsal of the same procedure

```bash
# Needs PostgreSQL and a role with CREATEDB (it creates and drops erp_rehearsal_* databases only).
export DB_HOST=127.0.0.1 DB_USERNAME=erp DB_PASSWORD=...
sh scripts/release/rehearse-fresh-install.sh
```

Expected (verbatim from the 2026-11-12 run):

```text
=== Fresh install rehearsal ===
| Step | Result | Detail |
|------|--------|--------|
| migrate --force on empty database | PASS |  |
| no pending migrations after migrate | PASS |  |
| demo seeding (db:seed) is refused in production mode | PASS |  |
| no user exists after the refused seed | PASS |  |
| erp:install without an admin password fails closed | PASS |  |
| still no user and no chart of accounts after the refused install | PASS |  |
| erp:install with a random one-time password | PASS |  |
| second erp:install is refused | PASS |  |
| erp:verify-credentials (no known/default passwords, no demo accounts) | PASS |  |
| erp:verify-ledger on the new installation | PASS |  |
| exactly one user, an active admin, none at @example.com | PASS |  |
| reference data present (accounts, one branch, open fiscal period, base currency) | PASS |  |
| no customer-specific data (no 'Safe Way' text, no sample customers/bookings) | PASS |  |
| accounting policy is NOT auto-approved on a new installation | PASS |  |
| HTTP: /api/v1/ready answers 200 | PASS |  |
| HTTP: administrator can log in with the install-time password | PASS |  |
| HTTP: login refused for owner@rehearsal.invalid with 'password' | PASS |  |
| HTTP: login refused for owner@rehearsal.invalid with 'change-me' | PASS |  |
| HTTP: login refused for admin@example.com with 'password' | PASS |  |

Fresh install rehearsal: PASS
```

It runs in CI (`postgres-integration` job) on every pull request.

## 4. Acceptance record

Fictitious company used by the rehearsal: "Rehearsal Travel Co", EGP, fiscal start
01-01. Replace with the customer's real values on a staging server.

| # | Scenario (from prompt 19) | Command / evidence | Status |
| --- | --- | --- | --- |
| 1 | Schema migrates on empty PostgreSQL 16 | `rehearse-fresh-install.sh` step 1 | PASS |
| 2 | Production seeding creates no demo user and no shared password | `db:seed` refused with exit 1; `erp:verify-credentials` OK; `UserSeederSafetyTest` | PASS |
| 3 | First-run setup: company, base currency, fiscal start, chart, roles, admin | `erp:install`; `InstallCommandTest` (22 cases) | PASS |
| 4 | Install fails closed without a password / with a weak one / twice | rehearsal steps 5, 8; `InstallCommandTest` | PASS |
| 5 | No Safe Way or other customer-specific data | rehearsal: data dump contains no `safe way`; zero customers/suppliers/bookings | PASS |
| 6 | Admin can log in over HTTP; `password`, `change-me`, demo account refused | rehearsal HTTP steps (PHP built-in server) | PASS |
| 7 | Logo stored as configuration | path validated and stored in `general.company_logo_path` | PASS (path only) |
| 8 | Logo file upload and rendering in the UI / PDF | not exercised | UNVERIFIED |
| 9 | Tax toggle, branches, users, currencies, module availability configured through the UI | settings API exists; not walked through on a fresh install | UNVERIFIED |
| 10 | Full stack from release images with `docker compose up` (db, redis, backend, api, web) | no Docker daemon in the sandbox; only `docker compose config` was validated | UNVERIFIED |
| 11 | HTTPS reverse proxy / Coolify deployment, real domain | not deployed (never deploy without approval) | UNVERIFIED |
| 12 | Customer, supplier, booking with 3 services, split payments, ledger postings | covered by automated tests (`BookingServiceTest`, `PaymentServiceTest`, `PostingServiceTest`: 701 tests pass), not replayed on a fresh install by hand | UNVERIFIED |
| 13 | Reports, refund, PDF export, follow-up lead on the fresh install | same as 12 | UNVERIFIED |
| 14 | Backup, restore, ledger unchanged | [`release-checklist.md`](release-checklist.md) section 3 | PASS (scripted) |
| 15 | Upgrade between two tagged releases | no release tag exists yet; N-1 was simulated from migrations | UNVERIFIED (simulated PASS) |
| 16 | Image contains no `.env`, no baked secret | repository and build-context checks PASS; built image not inspected (no daemon); runs in CI | UNVERIFIED |
| 17 | Accountant sign-off of the provisional accounting policy | decision items stay `PENDING_ACCOUNTANT_APPROVAL`; auto-posting is off after install | PENDING |

**Release decision: NOT READY on this evidence alone.** Rows 8-13, 15 and 16 must
be executed on a staging server from the tagged release artifact and the
accountant sign-off (17) obtained. CI green is not a substitute.
