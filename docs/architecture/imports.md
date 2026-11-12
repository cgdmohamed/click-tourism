# Import framework (work order 13)

Generic, versioned CSV/XLSX import used by customers, suppliers, employees,
tour packages, the chart of accounts and opening-balance lines. API contract:
`docs/architecture/openapi.yaml` (tag `Imports`). Code: `backend/app/Modules/Imports`,
`backend/app/Http/Controllers/Api/ImportController.php`, web page `/imports`.

## Flow

1. `GET /imports/templates` lists templates (entity, `version`, columns with English and Arabic headers).
   `GET /imports/templates/{entity}?format=csv|xlsx&locale=ar|en` downloads a blank file
   (`customers-template-v1-ar.csv`). Upload headers may be the key, either label, or a legacy alias.
2. `POST /imports` (multipart) scans the whole file first (encoding, size, rows, cell length, header,
   template version). A problem is a 4xx with a `code` and **no job is created**. Otherwise a job is stored
   and run as a **dry run** (default): status `previewed`, counts, first 50 preview rows, first 200 row errors,
   full error report CSV. Nothing is written.
3. `POST /imports/{id}/confirm` moves `previewed -> queued` atomically (second call: 409) and re-validates every row
   at write time. Files up to `IMPORTS_SYNC_MAX_BYTES` (100 KB) run in the request; larger files are pushed to the
   default queue (`ProcessImportJob`, never retried; the runner claims the job atomically). The uploaded file is
   deleted when the job is final. Final statuses: `completed`, `partial` (some rows failed), `failed`.
4. `GET /imports/{id}` for status polling, `GET /imports/{id}/errors` for the partial-failure report,
   `DELETE /imports/{id}` to discard a preview.

## Rules

- **Duplicate policy** `skip | update | fail` (per row). Matching: customers by tax id, else email, else phone, else
  English name, inside the importing branch; suppliers likewise without tax id; employees by `internal_code`
  (global, upper-cased); tour packages by `code`, else name + destination, inside the branch. Accounts keep the legacy
  upsert-by-code default (`update`). A repeated key inside one file is always a row error (`duplicate_in_file`).
  `update` only overwrites columns that have a value; blank cells never erase data.
- **Error policy** `on_error=skip` (default) imports valid rows; `abort` writes nothing if any row fails.
  Accounts and opening-balance lines are always all-or-nothing.
- **Safe casting**: no floats. Money is parsed digit by digit into minor units using the currency exponent
  (`1,234.5`, Arabic-Indic digits accepted; extra decimals are an error). Enums, booleans (yes/no/نعم/لا), currency codes
  (must exist), dates (strict) and e-mail/phone formats are validated per row.
- **Formula injection**: a cell starting with `=`, `@`, a tab/CR, or `+`/`-` followed by anything other than
  number/phone punctuation is rejected for that row (`formula_detected`). Every generated file (templates, error
  report) prefixes risky cells with an apostrophe.
- **Limits** (`config/imports.php`, env `IMPORTS_*`): 5 MB, 10 000 rows, 60 columns, 2 000 characters per cell,
  UTF-8 (UTF-16 with BOM accepted; anything else is `invalid_encoding`), first sheet of an XLSX only.
- **Permissions**: the permission that creates the record by hand (`customers.create`, `suppliers.create`,
  `employees.manage`, `bookings.create`, `accounts.update`, `opening_balances.manage`), plus the update permission when
  the policy is `update`. Re-checked by the worker. Templates and jobs of entities the user cannot import are hidden.
- **Branch scope**: a job belongs to the branch it was started in (`X-Branch-Id` must be an assigned branch). Rows only
  match/modify records of that branch; an employee code owned by another branch is reported as `other_branch` without
  touching or revealing it. Jobs are invisible and untouchable from other branches.
- **Audit**: `import.uploaded`, `import.previewed`, `import.confirmed`, `import.completed|partial|failed|discarded`
  (with file SHA-256, counts) plus the normal per-record events (`customer.created`, `employee.updated`, ...).
- **Opening balances** produce a *draft* batch only (as-of date and memo are upload options); posting stays a
  separate, separately permissioned action. The dry run builds the batch in a rolled-back transaction, so it applies the
  real per-currency balance validation. Historical journal data is deliberately not importable.

## Operations

Large imports need the queue worker (`php artisan queue:work`, already in docker-compose `worker`). The job timeout is
`IMPORTS_JOB_TIMEOUT` (900 s); keep `DB_QUEUE_RETRY_AFTER` above it if you raise it. If the worker dies the job is
marked `failed` (`worker_failed`) by the queue failure hook. The `import_jobs` migration is additive; `down()` drops the
table. Uploaded sources and reports live on the private disk `IMPORTS_DISK` under `imports/{job id}/`.

To add an entity: implement `ImportDefinition` (or extend `AbstractRowDefinition`) and register it in `ImportRegistry`.
Bump `version()` on any incompatible column change.
