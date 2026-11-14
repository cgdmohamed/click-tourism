# ADR-0025 - Background report exports (GAP-RPT-02, AC-RPT-007)

- **Status:** Accepted (project owner go-ahead 2026-11-14: "ابدأ RPT-02"; the gap register required architect approval for this new contract).
- **Date:** 2026-11-14
- **Related:** ADR-0014 (report export formats), `docs/product/acceptance-criteria.md` AC-RPT-007, `docs/deployment/coolify.md`.

## Context

Report exports were synchronous with a 50,000-row cap. AC-RPT-007 requires that a large export runs asynchronously, shows progress and produces a complete file only on success.

## Decision

1. **Resource `export_jobs`** (migration `2026_11_29_000100`): owner, branch, report key, format, filters, status `queued -> running -> succeeded | failed`, file name/size, error message, timestamps, expiry. The file path is never returned.
2. **API** (all need `reports.export`, all owner-only; a job of another user is 404): `POST /exports` (report from a fixed list of 13, format csv/xlsx/pdf, `params` = the report's own filters; idempotent; `throttle:report-exports`), `GET /exports`, `GET /exports/{id}`, `GET /exports/{id}/download`, `DELETE /exports/{id}`.
3. **A job replays the normal `/reports/{report}/export` route** in the queue worker as the user and branch that queued it. Permissions, filters, validation, branch scope, contact handling and the `report.exported` audit row are therefore exactly those of the synchronous export; nothing is re-implemented. A permission lost meanwhile makes the job `failed`.
4. **Higher row cap only for jobs:** the replayed request carries a request attribute (never a header or query value, so a client cannot set it) that raises the cap from 50,000 to `EXPORTS_ASYNC_MAX_ROWS` (200,000).
5. **Complete file only on success:** the file is stored only when the route answered 200 with a CSV, XLSX or PDF body; any other answer (validation message, 403, 5xx) marks the job `failed` with a short message and no file. `download` answers 409 until it succeeded and 410 after expiry.
6. **Retention:** finished files expire after `EXPORTS_TTL_DAYS` (7); `exports:prune` runs daily at 02:20 and deletes expired files and old failed rows. Audit: `export.queued`, `export.completed`, `export.failed`, `export.downloaded`.
7. **Progress** is the job status (queued, running, ready, failed): a single report query has no row-level progress to report, so none is invented. The Reports page polls every 3 seconds while a job is active.

## Consequences

- Needs the queue worker that the Coolify compose already runs (`queue:work`); without a worker a job stays `queued`. Files live on `EXPORTS_DISK` (default `local`, the same volume as other private files): include it in backups only if retention matters; it is disposable by design.
- Rollback: stop queueing (hide the panel) and run `exports:prune`; the table and routes are additive and unused when no job exists.
- A worker crash can leave a job `running`; the owner can no longer remove it until it ends, and a new export can simply be queued.
- Contract change: five routes and one resource. `openapi.yaml` is the source of truth.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | `/exports` resource and routes, the `export_jobs` table and the higher row cap for jobs | Project owner | architect | 2026-11-14 | Owner instruction in the implementation session: "اختار أ، وابدأ RPT-02" |
