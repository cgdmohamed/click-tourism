# Backend API

Laravel 12 API for the Tourism ERP. Modular monolith under `app/Modules/<Module>`;
the only writer of the general ledger is
`app/Modules/Accounting/Services/PostingService.php` (ADR-0004).

## Local setup

```powershell
copy .env.example .env
php artisan key:generate
# Add DEMO_PASSWORD=<choose-one> to .env: demo users have no default password.
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

SQLite is used for local development and tests. Production uses PostgreSQL 16 +
Redis; set `DB_CONNECTION=pgsql` and the `DB_*` variables in `.env`.

## Tests

```powershell
php artisan test
composer audit
```

Tests use `RefreshDatabase` and minimal fixtures
(`tests/Support/BuildsAccounting.php`) instead of the full demo seeder, because
SQLite writes on this filesystem are slow.

## Conventions that matter

- Money is `BIGINT` minor units plus a currency code. Never use floats.
- Posted `journal_entries` and their `journal_lines` are immutable; corrections
  go through `PostingService::reverse()`.
- Every automatic or manual posting goes through `PostingService`; no module may
  insert journal rows directly.
- `PostingService::post()` requires an open fiscal period covering the entry
  date. Drafts resolve a period at creation and can later be posted with
  `PostingService::postDraft()`.
- FX rates are decimal strings (`numeric(20,10)`). Base amounts are stored at
  posting time and reports never re-convert history.
- Branch access is enforced server-side: `X-Branch-Id` is validated against the
  user's `user_branch` assignments.
- Idempotent money endpoints use the `Idempotency-Key` header; replays return the
  stored response with `Idempotent-Replayed: true`.
- Authorization failures return `{ "message": "...", "code": "forbidden" }`.
- Date-range queries use `whereDate()` because date casts are stored as datetime
  strings in SQLite.
- Accounting defaults are settings, not constants. See
  `app/Modules/Core/Services/SettingService.php` and the pending-approval
  checklist in the root [`README.md`](../README.md).

## API

Routes: `routes/api.php` (prefix `/api/v1`). Contract:
[`../docs/architecture/openapi.yaml`](../docs/architecture/openapi.yaml).
Schema: [`../docs/architecture/ERD.md`](../docs/architecture/ERD.md).
