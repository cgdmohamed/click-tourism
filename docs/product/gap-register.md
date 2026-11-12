# Gap Register — Tourism ERP

Status: **Living document.** Date: 2026-11-12. Verified against `main` @ `f2c9487` (merge of PR #57, Invoices module complete).

Purpose: one place that answers "what is actually still missing?" so modules can be finished in order before any new feature work starts. Every gap below was verified against code, routes, tests, and CI — not copied from planning documents.

Companion documents: `PRD.md`, `acceptance-criteria.md`, `traceability-matrix.md`, `decision-implementation-matrix.md`, `issues/`, `../architecture/dependency-graph.md`, `../architecture/openapi.yaml`.

## 1. How to read this register

| Field | Meaning |
|---|---|
| ID | Stable identifier: `GAP-<area>-<n>` |
| Severity | **P0** corrupts money/data or makes a claimed feature unusable; **P1** blocks launch acceptance; **P2** should ship before release; **P3** later |
| Evidence | File, route, or test that proves the gap exists today |
| Done when | Observable condition that closes the gap |
| Blocked by | Decision or approval that must land first (see `decisions-closed-2026-11-11.md`) |

Severity is about product risk, not effort.

## 2. Snapshot

| Module | State | Open gaps | Next action |
|---|---|---|---|
| Invoices (AR/AP) | **Complete** — API, documents, PDF, AP flow, E2E, CI | 1 (GAP-RPT-03 adjacent, export parity) | none |
| Reports | Core ledger + statements + aging/statements + management pack + CSV/XLSX/PDF done | 4 | close RPT-02, RPT-03, RPT-05, then browser E2E |
| CRM | Lead capture, rules, conversion, WhatsApp consent done | 6 | wire reassignment (P0), then pipeline/activities |
| Core / auth | Users, roles, branches, settings, audit events, health, install | 3 | notifications, attachments decision |
| Frontend / UX | 38 pages, design tokens from `DESIGN.md`, RTL/AR | 4 | E2E per module, a11y checks |
| QA / release | 876 backend tests, 88 unit tests, 8 CI jobs | 4 | coverage gate, a11y, performance seed |
| Documentation | ADRs, OpenAPI, indexes and per-issue statuses current | 0 | none (DOC-01..05 closed) |

Counts include only tracked gaps below (35 total, 3 at P0).

## 3. Reports module

Implemented today: trial balance, general ledger, income statement, balance sheet, aging, aging reconciliation, party statement, dashboard summary, cost-center P&L and unallocated view, and the management pack (profitability, sales, commissions, tax). Exports are server-rendered CSV, XLSX, and PDF (`ReportExporter`, `ReportDocument`), audited as `report.exported`, branch-scoped, throttled, with a 50,000-row synchronous cap and a 5,000-row PDF cap.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| ~~GAP-RPT-01~~ | **P1 — closed** | Management report pack from EP-08 TE-040 was missing. Now `GET /reports/profitability` (by booking and service type: revenue, direct cost, margin), `/reports/sales` (revenue by branch and booking owner), `/reports/commissions` (ledger commission expense, pending accruals and payouts per beneficiary) and `/reports/tax` (output vs input tax by tax profile) exist, derived from posted base-currency journal lines and branch scoped. Each exports CSV/XLSX/PDF at `/export` (`reports.export`, audited as `report.exported`, `report-exports` throttle) and has a tab in `Reports.tsx`. Service-type split allocates each booking's ledger revenue/cost over its items; ledger lines with no booking stay visible as "not linked". | `backend/app/Http/Controllers/Api/ManagementReportController.php`; `backend/app/Modules/Reports/Services/ManagementReportService.php`; `ManagementReportRows.php`; `web/src/pages/ManagementReports.tsx`; `docs/architecture/openapi.yaml`; `backend/tests/Feature/ManagementReportsTest.php`. | Closed by tests reconciling profitability/sales to the income statement and trial balance, commissions to account 6700, tax to accounts 2200/1160, plus branch isolation, permissions, and all three export formats with audit. Tax presentation by profile still awaits accountant sign-off (DEC-004/DEC-028). | none |
| GAP-RPT-02 | P2 | Large exports are synchronous only; there is no `/exports` job resource, progress, or resumable download. | `ReportController::EXPORT_MAX_ROWS = 50000`; no queue job or `/exports` route. AC-RPT-007 is unmet. | Exports above a configured threshold queue, expose status, and produce the file only on success. | architect approval (new contract) |
| GAP-RPT-03 | P2 | Cost-center reports cannot be exported at all. | No export route for `reports/cost-center-pl` / `cost-center-unallocated`; `web/src/pages/Reports.tsx:404` excludes `isCostCenterReport` from `canExport`. | Both cost-center reports export CSV/XLSX/PDF with filter parity and audit. | none |
| ~~GAP-RPT-04~~ | **P2 — closed** | GL drill-down opened the journal entry, not the source document page. Now general-ledger and party-statement rows in Reports show a source-document link (`web/src/lib/sourceLinks.ts`): invoices and bookings open the record itself, payments/refunds open the payments list filtered by number, other types open their module list; the journal entry modal stays available. Management report booking rows open the booking. | `web/src/lib/sourceLinks.ts`; `web/src/components/profile.tsx` (`SourceDocument`); `web/src/pages/Reports.tsx`; `web/src/lib/sourceLinks.test.ts`. | Closed by unit tests of the link mapping. Expense, document, and supplier-payment sources still land on the module list (no per-record page exists yet). | none |
| GAP-RPT-05 | P2 | GL supplier filter silently misses booking-cost postings because those postings carry no supplier party. | TE-036 known limit; postings from `Bookings` do not set a supplier party (DEC-002). | Supplier filter returns all supplier-attributable cost rows, or the UI explains the exclusion. | DEC-002 |
| GAP-RPT-06 | P3 | No scheduled delivery of reports (explicitly out of scope for TE-039, listed so it is not mistaken for a defect). | TE-039 "Scope out: Scheduled email delivery". | Keep out of scope until a decision adds it. | product decision |

## 4. CRM module

Implemented today: lead create/update/list, configurable sources/stages as data, assignment rule engine with priority/category/availability, inactivity measurement in business hours, conversion to customer exactly once, WhatsApp opt-in/opt-out enforcement, and an `assignment_rules` UI.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| GAP-CRM-01 | **P0** | 24-hour reassignment is advertised but never runs. `LeadService::reassignStale()` has no caller: no artisan command, no scheduler entry. | `backend/app/Modules/CRM/Services/LeadService.php:154`; `backend/routes/console.php` has no lead job; `backend/app/Console/Commands` has no reassignment command; `web/src/pages/Leads.tsx:98,107` promises "automatic reassignment will apply". | A scheduled command runs `reassignStale()`, is idempotent, respects closed periods/business hours, writes activity + audit, and has a feature test proving stale-only reassignment. | none |
| GAP-CRM-02 | P1 | Lead sources and stages cannot be managed through the API/UI; they are seed data only. | No `/lead-sources` or `/lead-stages` routes in `backend/routes/api.php`; models exist in `Modules/CRM/Models`. | CRUD (or settings-managed) sources/stages with won/lost flags, ordering, and tests; AC-CRM-001/005 fully satisfiable. | none |
| GAP-CRM-03 | P1 | No lead activity/timeline read API and no lead detail screen; activities are written but never displayed. | Only `index`, `store`, `update`, `convert` in `LeadController`; no `/lead-activities` route; no lead profile route in `web/src/App.tsx`. | `GET /leads/{lead}` with paginated activities and a detail page showing timeline, owner history, and consent state. | none |
| GAP-CRM-04 | P1 | No duplicate detection for phone/email, so the same lead can be captured repeatedly. | No duplicate-normalisation logic in `LeadService`; AC-CRM-007 unmet. | Creating/importing a lead inside the configured window flags the match and links it instead of silently duplicating. | duplicate-window setting (default 30 days) |
| GAP-CRM-05 | P1 | No pipeline metrics; `/reports/pipeline` does not exist. | No route; TE-043 unmet. | Pipeline metrics by stage/owner/source/branch/period, reconciles with lead data, visible on dashboard. | none |
| GAP-CRM-06 | P2 | Reassignment/escalation produce no notification because no notification infrastructure exists. | No `Notifications` directory, no notifications table/migration, no route. AC-CRM-003 requires "notified". | In-app notification record + bell/list, created on reassign/escalate, with read state per user. | overlaps GAP-CORE-01 |

## 5. Core / platform

Implemented today: Sanctum auth with rate limiting, role/permission matrix incl. branch scopes, branch switching, settings and decision registry, audit events, health/ready probes, scheduler heartbeat, install/upgrade/rollback commands, token pruning, demo-mode guards.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| GAP-CORE-01 | P1 | No in-app notifications anywhere, despite being a planned collaboration primitive. | No `notifications` migration/table, model, route, or UI. | Notification model, per-user list with read/unread, emitted by CRM reassignment and approval flows, tested. | none |
| ~~GAP-CORE-02~~ | **P1 — closed** | Audit trail was write-only for operators: no API or screen to browse audit events (only the fixed-asset trail). Now `GET /audit-events` lists events newest-first with actor/action/entity/date/branch filters, `GET /audit-events/export` emits a filtered CSV capped at 50,000 rows and records an `audit.exported` event, and the `/audit-events` screen shows the trail with before/after snapshots. | `backend/app/Http/Controllers/Api/AuditEventController.php`; `backend/database/migrations/2026_11_23_000300_add_branch_to_audit_events.php`; `backend/app/Modules/Core/Models/AuditEvent.php`; `web/src/pages/AuditLog.tsx`; `backend/tests/Feature/AuditEventTest.php`. | Closed by tests covering `audit.view`/`audit.export`, actor/action/entity/date filters, branch scoping, pagination, CSV export, date validation, and branch inference. | none |
| GAP-CORE-03 | P2 | Attachments are claimed by the module matrix but do not exist. | `docs/product/module-matrix.md` lists attachments under Core; no attachment table, route, or module. | Either implement document attachments (storage, virus/type limits, permission, audit) or remove the claim from the matrix. | decision to implement or de-scope |
| GAP-CORE-04 | P3 | Users/roles/branches are editable, but there is no self-service session/token management screen. | No `/profile` sessions section; only `sanctum:prune-expired`. | Signed-in user can list and revoke their own devices. | none |

## 6. Frontend / QA / release

Implemented today: design tokens from `DESIGN.md` (light/dark, AR/EN, Suisse Intl incl. Arabic), 38 routed pages, shared UI primitives, global search, demo banner, error/empty/loading states, 88 Vitest unit tests, one Playwright critical-flow spec.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| GAP-QA-01 | P1 | Browser E2E covers one journey only (invoice PDF); no per-module critical flow. | `web/e2e` contains only `invoice-pdf.spec.ts`. | One spec per finished module: reports export, lead→conversion, payment/refund, close→report. | none |
| GAP-QA-02 | P1 | No accessibility gate although AC-UI-006 requires an automated check. | `.github/workflows/ci.yml` has no axe/a11y step. | Automated a11y check on key pages fails the build on regressions. | none |
| GAP-QA-03 | P2 | No coverage threshold, so "coverage ≥ 80% on accounting engine" is unenforced. | `php artisan test` runs without `--coverage` (`ci.yml:53`); no Vitest threshold. | Coverage reported and gated per configured scope. | none |
| GAP-QA-04 | P2 | No performance seed or p95 check (AC-NFR-001). | No `tests/performance`; no k6/benchmark step. | Seeded reference-scale dataset + repeatable p95 assertion in CI or a documented pre-release job. | none |
| GAP-QA-05 | P3 | Frontend tests are thin relative to surface: 11 test files for 38 pages. | `web/src/**/*.test.*` count = 11. | Critical forms/reports have component tests for validation, empty, and error states. | none |

## 7. Documentation drift

These are not code defects, but they make the repository lie about its own state — which is how gaps get missed.

| ID | Sev | Gap | Evidence | Done when |
|---|---|---|---|---|
| ~~GAP-DOC-01~~ | **P1 — closed** | `docs/product/README.md` still says "No application code has been written" and lists the design system as missing. | `docs/product/README.md:3,18-26`. | Index reflects shipped modules and links this register. |
| ~~GAP-DOC-02~~ | **P1 — closed** | EP-08 TE-039 says native PDF/XLSX are "not implemented"; both now exist for reports and invoices. | `issues/ep-08-reporting.md:61` vs `ReportDocument::pdf/xlsx` and `InvoiceDocument`. | Status line updated; DEC-040 marked resolved by ADR-0014. |
| ~~GAP-DOC-03~~ | **P1 — closed** | 11 of 13 epic issue files carry no status, so nobody can tell done from planned. | Only `ep-01`, `ep-08`, `ep-12` contain `Status:` lines. | Every `TE-###` has an explicit status: done / partial / not started / blocked. |
| ~~GAP-DOC-04~~ | **P2 — closed** | `module-matrix.md` and `traceability-matrix.md` are still "Draft — no code implied". | Both headers. | Promoted to implementation-aware docs or superseded by this register. |
| ~~GAP-DOC-05~~ | **P2 — closed** | TE-036 records an "additive, architect to confirm" OpenAPI note with no follow-up. | `issues/ep-08-reporting.md:16`. | Architect sign-off recorded in the ADR or the note removed. |

## 8. Blocked by accountant / architect decisions

Tracked here so they are never silently implemented or silently assumed "done".

| ID | Ref | What is blocked | Current safe behaviour |
|---|---|---|---|
| GAP-DEC-01 | DEC-002 | Supplier cost recognition point; blocks GAP-RPT-05 | Booking costs post without a supplier party |
| GAP-DEC-02 | DEC-004 | Commission trigger/posting for production | Posted only when `accounting_policy_approved` and `commission_auto_post` are enabled |
| GAP-DEC-03 | DEC-008 | FX revaluation policy | Scheduled command is a no-op unless explicitly enabled |
| GAP-DEC-04 | DEC-014 | Inter-branch clearing model | Only fixed-asset transfers post inter-branch |
| GAP-DEC-05 | DEC-028 | Statutory tax-invoice wording | Document titles deliberately avoid "Tax invoice" / "فاتورة ضريبية" |
| GAP-DEC-06 | DEC-025 | Statutory report formats | Management-grade statements only |

## 9. Deliberately out of scope (do not "fix")

- Scheduled email/WhatsApp delivery of reports (TE-039 scope out).
- BI dashboards / warehouse (TE-040 scope out).
- Bank reconciliation automation (TE-038 scope out).
- Multi-tenancy, customer portals, SMS/email notifications (DEC-020/DEC-022).
- Reverse lead conversion / merge (TE-043 scope out).

## 10. Ordered remediation plan

Order follows the module completion sequence; each step is one branch → one PR with tests.

1. `agent/reports-close-gaps` — GAP-RPT-01, 03, 04; E2E for a report export (GAP-QA-01).
2. `agent/crm-close-gaps` — GAP-CRM-01 (P0 scheduler) then 02, 03, 04, 05.
3. `agent/core-notifications-audit` — GAP-CORE-02 closed by `agent/core-audit-browser`; GAP-CORE-01 notifications and GAP-CORE-03 decision next.
4. `agent/qa-harness` — GAP-QA-02, 03, 04, 05; remaining per-module E2E.
5. `agent/docs-truth-pass` — GAP-DOC-01..05.
6. Release rehearsal — re-run the PostgreSQL, compose, backup/restore, and N-1 upgrade jobs already in CI.

Accountant-blocked items stay blocked; they do not enter this order.

## 11. Verification commands

```bash
# backend
cd backend
php artisan test
./vendor/bin/pint --test

# frontend
cd web
npm run lint
npm run test
npm run build
npm run test:e2e        # needs a migrated install; see README
```

Re-verify any row above with:

```bash
git grep -n "reports/profitability\|reports/sales\|reports/pipeline" -- backend/routes
git grep -n "reassignStale" -- backend/app backend/routes
git grep -n "notifications" -- backend/database/migrations
```

## 12. Maintenance rule

- A gap is removed only when its "Done when" condition is demonstrably true on `main` **and** covered by a test.
- New gaps get a new ID; never renumber.
- Any change that contradicts this document must update it in the same PR.
