# Tourism ERP — Safe Way reference installation

Self-hosted accounting and operations system for a tourism business. This is a
single reusable product installed once per customer — **not SaaS, not
multi-tenant, and not forked per client**.

- **Backend:** Laravel 12 API (PHP 8.2+), Sanctum tokens, Spatie permissions.
- **Frontend:** React 19 + TypeScript + Vite + Tailwind v4 (RTL Arabic / English).
- **Data:** PostgreSQL 16 + Redis in production; SQLite is supported for local
  development and tests.
- **Money:** integer minor units (`BIGINT`), never floats. Posted journals are
  immutable; corrections are reversals.

Product and architecture contracts live in [`docs/`](docs/):

- [`docs/product/PRD.md`](docs/product/PRD.md) — scope, 90 functional requirements.
- [`docs/product/decisions-needed.md`](docs/product/decisions-needed.md) — decisions,
  including the items still awaiting accountant sign-off.
- [`docs/architecture/README.md`](docs/architecture/README.md) — ADRs, ERD, event
  catalog, migration order, threat model.
- [`docs/architecture/openapi.yaml`](docs/architecture/openapi.yaml) — API contract.

## Current status

Working end to end against the seeded demo data:

- Login, profile, branch switching and server-side branch scoping.
- Chart of accounts, customers, suppliers.
- Bookings (draft → quoted → confirmed → in progress → completed / cancelled)
  with revenue and cost posting.
- Receipts, partial allocations, customer advances, refunds, idempotent replays.
- Supplier settlement: obligations (with unique invoice references), payments,
  advances, and applying an advance to one or more open obligations. Each
  movement posts one balanced journal entry (AP / cash, or supplier advance /
  cash). Automatic obligation creation from booking costs is **not implemented**
  because it is blocked on DEC-002 (supplier cost recognition).
- Manual journal entries: balanced validation, save as draft, post a draft,
  reverse a posted entry.
- Trial balance, income statement, balance sheet, receivables aging, dashboard.
- CRM leads, conversion to customer, inactivity reassignment.
- Meta / WhatsApp webhook verification, HMAC validation and message-id dedupe
  (live acceptance still needs real credentials).

The browser UI has been exercised for login, every page, and the create/save
flows: customer, supplier, booking (with confirmation posting), receipt with
allocation, supplier obligation → payment → advance → apply, manual journal
(draft, post, reverse) and settings save. The production stack is described by
`docker-compose.yml`; the CI workflow is in `.github/workflows/ci.yml`.

`php artisan test` → **73 passing tests (226 assertions)**. The API smoke script
in `C:\Users\Mohamed\AppData\Local\Temp\opencode\smoke.ps1` also passes against a
seeded database.

### Supplier settlement API

- `GET/POST /api/v1/supplier-obligations` and `GET /api/v1/supplier-obligations/{id}`
- `GET/POST /api/v1/supplier-payments` and `GET /api/v1/supplier-payments/{id}`
- `POST /api/v1/supplier-payments/{id}/apply` to apply an unapplied advance

`POST /supplier-payments` and `POST /supplier-payments/{id}/apply` accept an
`Idempotency-Key` header. Access is guarded by `supplier_obligations.*` and
`supplier_payments.*` permissions.

## Local quick start (SQLite)

Requirements: PHP 8.2+ with `pdo_sqlite`, `bcmath`, `intl`, `mbstring`, `zip`;
Composer 2.x; Node 20+ and npm.

```powershell
# 1. Backend
cd backend
copy .env.example .env          # if .env is missing
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000

# 2. Frontend (new terminal)
cd web
npm install
npm run dev -- --host 127.0.0.1 --port 5173
```

Open <http://127.0.0.1:5173>. The Vite dev server proxies `/api/v1` to
`http://127.0.0.1:8000`.

Demo users (all use password `password`):

| Email | Role |
| --- | --- |
| `admin@example.com` | admin |
| `accountant@example.com` | accountant |
| `sales@example.com` | sales |
| `viewer@example.com` | viewer |

Demo seeding is controlled by `DEMO_SEED=true` in `backend/.env`.

### Production data services

Production targets PostgreSQL 16 and Redis. The repository ships a
`docker-compose.yml` for that stack; see [Docker deployment](#docker-deployment).
SQLite is only a development/test fallback; it is not the production database.

## Docker deployment

Prerequisites: Docker Engine / Docker Desktop with Compose v2.

```powershell
copy .env.example .env
# Edit .env: set APP_KEY, POSTGRES_PASSWORD, APP_URL and FRONTEND_URL.
# Generate a key with:  cd backend; php artisan key:generate --show

docker compose up -d --build
docker compose logs -f backend
```

Open <http://localhost:8080> (change `WEB_PORT` in `.env` to expose another
port). The stack runs:

| Service | Image / build | Purpose |
| --- | --- | --- |
| `db` | `postgres:16-alpine` | Application database, volume `pgdata`. Not published to the host. |
| `redis` | `redis:7-alpine` | Cache and queue backend, volume `redisdata`. Not published to the host. |
| `backend` | `backend/Dockerfile` | PHP-FPM with `pdo_pgsql`, `intl`, `bcmath`, `opcache`, `redis`. |
| `api` | `nginx:1.27-alpine` | FastCGI front end for Laravel; internal port `8000`. |
| `web` | `web/Dockerfile` | Builds the SPA with Node 22 and serves it via nginx, proxying `/api` to `api`. |
| `worker` | `backend/Dockerfile` | Optional queue worker, `profiles: ["workers"]`. |

Behaviour worth knowing:

- On start the backend container waits for PostgreSQL, runs
  `php artisan migrate --force`, links storage and caches config/routes
  (`APP_OPTIMIZE=true`). Set `RUN_MIGRATIONS=false` for a container that must
  not migrate (for example a read-only replica or an existing database).
- Demo data is **not** seeded in production. Set `SEED_DEMO=true` for a demo
  installation, then set it back to `false`.
- `APP_KEY` is mandatory; the container refuses to start without it.
- Back up `pgdata` with `docker compose exec db pg_dump -U tourism tourism_erp`
  and test restores before go-live.
- Enable background workers with
  `docker compose --profile workers up -d --build`.
- Never run `migrate:fresh` against a production database. Use
  `php artisan migrate` and follow
  [`docs/architecture/migration-order.md`](docs/architecture/migration-order.md)
  for the rollback plan.
- Deployments still require human approval per the project rules.

## Tests and quality gates

```powershell
cd backend
php artisan test
composer audit
```

The framework is on Laravel **12.71.0**; `composer audit` reports no known
advisories. Node 20.11 emits an `EBADENGINE` warning because Vite 6 asks for
Node 20.19+; the build still succeeds. Prefer Node 20.19+ or 22 LTS for CI.

Frontend checks:

```powershell
cd web
npm run build      # tsc -b && vite build
npm run lint
```

Continuous integration is defined in
[`.github/workflows/ci.yml`](.github/workflows/ci.yml): PHP 8.2 runs the test
suite and `composer audit`, Node 22 runs lint and the production build.

## Pending accountant approval (blocking go-live)

The accounting treatment is configurable and deliberately **not guessed in
code**. The seeded installation carries:

- `accounting.approval_status = pending_accountant_review`
- `accounting.approval_note` listing DEC-001, DEC-002, DEC-007, DEC-010, DEC-019

Before go-live an accountant must review and sign off the following settings
(visible and editable in **Settings**):

| Setting | Provisional default | Decision |
| --- | --- | --- |
| `accounting.booking_posting_trigger` | `confirm` | DEC-001 revenue recognition trigger |
| `accounting.auto_post_bookings` | `true` | Whether confirmation posts automatically |
| `tax.enabled` / `tax.default_rate` | `false` / `0` | DEC-002 tax point and rate |
| `accounting.fiscal_year_start` | `01-01` | Fiscal year boundaries |
| `accounting.rounding_policy` | `half_up` | Rounding policy |
| `accounting.rounding_account_code` | `6999` | FX rounding account |
| `accounting.ar_account_code` | `1100` | Receivables control account |
| `accounting.advance_account_code` | `2300` | Customer advances |
| `accounting.revenue_account_code` | `4100` | Service revenue |
| `accounting.unearned_account_code` | `2400` | Unearned revenue |
| `accounting.cost_account_code` | `5100` | Cost of services |
| `accounting.ap_account_code` | `2100` | Payables control account |
| `accounting.supplier_advance_account_code` | `1150` | Supplier advances (asset until applied) |
| `accounting.tax_payable_account_code` | `2200` | VAT payable |
| `accounting.fx_gain_account_code` / `fx_loss_account_code` | `7900` / `6900` | FX policy (DEC-007) |
| Commission treatment | not implemented yet | DEC-010 |
| Closing / branch accounting | not implemented yet | DEC-019 |

Also unresolved and tracked in `docs/product/decisions-needed.md`: commission
recognition, period-closing mechanics and branch accounting mode. Do not enable
these in production until the accountant has confirmed the treatment.

## Design system replacement point

The customer design system has **not been supplied**. A temporary neutral token
layer is isolated in one place:

- [`web/src/index.css`](web/src/index.css) — the `@theme` block defines brand,
  surface, line, ink, positive/negative and radius tokens.
- [`web/src/components/ui.tsx`](web/src/components/ui.tsx) — shared primitives
  (buttons, cards, tables, fields, modals, badges) built only from those tokens.

When the approved design system arrives, replace the token values and primitive
styles in those two files. Do not add a second UI kit or fork components per
screen.

## Operational notes

- Secrets belong in `backend/.env` for local development and in the repository
  root `.env` for the Docker stack (never committed), plus the deployment secret
  store. Meta / WhatsApp credentials are not present; webhook live acceptance is
  blocked until they are.
- A stale GitHub OAuth token in `%APPDATA%\Composer\auth.json` breaks Composer.
  Rotate or remove it, or use an isolated `COMPOSER_HOME` for local work.
- The workspace is not yet a git repository. The project rules require one
  issue → one branch → one PR, tests for every change, no force push, and no
  deployment without human approval.
- When upgrading an existing installation, run
  `php artisan db:seed --class=RolePermissionSeeder` after `php artisan migrate`
  so newly introduced permissions are created and attached to roles. The seeder
  is idempotent.
- Do not run `migrate:fresh` against production. Use `php artisan migrate` and
  verify the migration order and rollback plan in
  [`docs/architecture/migration-order.md`](docs/architecture/migration-order.md).

## Repository layout

```
backend/        Laravel 12 API (app/Modules/<Module>, tests, seeders, migrations)
web/            React + TypeScript + Vite frontend
deploy/         nginx configuration used by the Docker stack
docs/product/   PRD, module matrix, workflows, decisions, acceptance criteria, issues
docs/architecture/ ADRs, ERD, event catalog, threat model, OpenAPI draft
reference/      Source proposal and product context
tourism_erp_agent_prompts/  Delivery playbook and task/PR templates
docker-compose.yml          PostgreSQL 16 + Redis + API + SPA
.github/workflows/ci.yml    Backend tests/audit and frontend lint/build
```
