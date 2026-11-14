# Gap Register — Tourism ERP

Status: **Living document.** Date: 2026-11-12. Verified against `main` @ `f2c9487` (merge of PR #57, Invoices module complete).

Purpose: one place that answers "what is actually still missing?" so modules can be finished in order before any new feature work starts. Every gap below was verified against code, routes, tests, and CI — not copied from planning documents.

Companion documents: `PRD.md`, `acceptance-criteria.md`, `traceability-matrix.md`, `decision-implementation-matrix.md`, `issues/`, `../architecture/dependency-graph.md`, `../architecture/openapi.yaml`.

> Note (single tax): the installation has one tax defined in Settings; tax profiles are removed. The management tax report (`/reports/tax`) now reports the single configured rate and one row per tax account.

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
| Invoices (AR/AP) | **Complete** — API, documents, PDF, AP flow, E2E, CI | 0 | none |
| Reports | Core ledger + statements + aging/statements + management pack + cost-center exports + CSV/XLSX/PDF done | 3 | close RPT-02, RPT-05, then browser E2E |
| CRM | Lead capture, configurable sources/stages, rules, lead profile + timeline, duplicate flagging, pipeline metrics, conversion, WhatsApp consent, 24-hour reassignment scheduled, notifications done | 0 | none |
| Core / auth | Users, roles, branches, settings, audit browsing, notifications, health, install | 2 | attachments decision (CORE-03), self-service sessions (CORE-04) |
| Frontend / UX | 38 pages, design tokens from `DESIGN.md`, RTL/AR | 4 | E2E per module, a11y checks |
| QA / release | 886 backend tests, 93 unit tests, 9 CI jobs, E2E + axe a11y gate | 4 | backend coverage gate, close→report E2E, performance seed |
| Documentation | ADRs, OpenAPI, indexes and per-issue statuses current | 0 | none (DOC-01..05 closed) |

Counts include only tracked gaps below (open: RPT-02, RPT-05, RPT-06, CORE-03, CORE-04, QA-01, QA-03..05, DEC items; no P0 or P1 CRM/Core/Reports gaps remain).

## 3. Reports module

Implemented today: trial balance, general ledger, income statement, balance sheet, aging, aging reconciliation, party statement, dashboard summary, cost-center P&L and unallocated view, and the management pack (profitability, sales, commissions, tax). Exports are server-rendered CSV, XLSX, and PDF (`ReportExporter`, `ReportDocument`), audited as `report.exported`, branch-scoped, throttled, with a 50,000-row synchronous cap and a 5,000-row PDF cap.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| ~~GAP-RPT-01~~ | **P1 — closed** | Management report pack from EP-08 TE-040 was missing. Now `GET /reports/profitability` (by booking and service type: revenue, direct cost, margin), `/reports/sales` (revenue by branch and booking owner), `/reports/commissions` (ledger commission expense, pending accruals and payouts per beneficiary) and `/reports/tax` (output vs input tax per tax account, single configured rate) exist, derived from posted base-currency journal lines and branch scoped. Each exports CSV/XLSX/PDF at `/export` (`reports.export`, audited as `report.exported`, `report-exports` throttle) and has a tab in `Reports.tsx`. Service-type split allocates each booking's ledger revenue/cost over its items; ledger lines with no booking stay visible as "not linked". | `backend/app/Http/Controllers/Api/ManagementReportController.php`; `backend/app/Modules/Reports/Services/ManagementReportService.php`; `ManagementReportRows.php`; `web/src/pages/ManagementReports.tsx`; `docs/architecture/openapi.yaml`; `backend/tests/Feature/ManagementReportsTest.php`. | Closed by tests reconciling profitability/sales to the income statement and trial balance, commissions to account 6700, tax to accounts 2200/1160, plus branch isolation, permissions, and all three export formats with audit. Tax presentation still awaits accountant sign-off (DEC-004/DEC-028). | none |
| GAP-RPT-02 | P2 | Large exports are synchronous only; there is no `/exports` job resource, progress, or resumable download. | `ReportController::EXPORT_MAX_ROWS = 50000`; no queue job or `/exports` route. AC-RPT-007 is unmet. | Exports above a configured threshold queue, expose status, and produce the file only on success. | architect approval (new contract) |
| ~~GAP-RPT-03~~ | **P2 — closed** | Cost-center reports could not be exported. Now both export CSV/XLSX/PDF with the same filters as the JSON reports, base-currency amounts, audit, branch scope, and the shared export throttle. | `backend/app/Http/Controllers/Api/CostCenterController.php` (`exportProfitAndLoss`, `exportUnallocated`); `backend/app/Modules/Reports/Services/ReportCsv.php` (`costCenterProfitAndLossRows`, `costCenterUnallocatedRows`); `backend/routes/api.php:164,166`; `web/src/pages/Reports.tsx` (`canExport`); `backend/tests/Feature/CostCenterTest.php`; `backend/tests/Feature/ReportExportReliabilityTest.php`. | Closed by the three-format export tests (XLSX totals equal JSON), permission test, and branch-isolation coverage. | none |
| ~~GAP-RPT-04~~ | **P2 — closed** | GL drill-down opened the journal entry, not the source document page. Now general-ledger and party-statement rows in Reports show a source-document link (`web/src/lib/sourceLinks.ts`): invoices and bookings open the record itself, payments/refunds open the payments list filtered by number, other types open their module list; the journal entry modal stays available. Management report booking rows open the booking. | `web/src/lib/sourceLinks.ts`; `web/src/components/profile.tsx` (`SourceDocument`); `web/src/pages/Reports.tsx`; `web/src/lib/sourceLinks.test.ts`. | Closed by unit tests of the link mapping. Expense, document, and supplier-payment sources still land on the module list (no per-record page exists yet). | none |
| ~~GAP-RPT-05~~ | **P2 — closed** | The GL supplier filter missed cost lines. Now the cost line of a supplier invoice, the booking-cost posting and the period-end accrual each carry their supplier, so the ledger filtered by supplier returns them (`InvoiceService::postingLines`, `BookingService::postCost`, `CostAccrualService`). Entries posted before this change keep their old attribution. | `backend/tests/Feature/SupplierCostRecognitionTest.php` | — |
| GAP-RPT-06 | P3 | No scheduled delivery of reports (explicitly out of scope for TE-039, listed so it is not mistaken for a defect). | TE-039 "Scope out: Scheduled email delivery". | Keep out of scope until a decision adds it. | product decision |

## 4. CRM module

Implemented today: lead create/update/list, configurable sources/stages as data, assignment rule engine with priority/category/availability, inactivity measurement in business hours, conversion to customer exactly once, WhatsApp opt-in/opt-out enforcement, and an `assignment_rules` UI.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| ~~GAP-CRM-01~~ | **P0 — closed** | 24-hour reassignment was advertised but never ran. Now: `crm:reassign-stale-leads` runs hourly, calls `LeadService::reassignStale()`, is idempotent, measures inactivity in configured business hours, and writes an activity + audit row. | `backend/app/Console/Commands/ReassignStaleLeads.php`; `backend/routes/console.php` (`crm-reassign-stale-leads`); `backend/tests/Feature/LeadReassignmentCommandTest.php`. | Closed by the scheduled command and stale-only/idempotency test. No accounting period is touched by lead routing, so closed-period logic does not apply. | none |
| ~~GAP-CRM-02~~ | **P1 — closed** | Lead sources and stages could not be managed through the API/UI; they were seed data only. Now `GET/POST /lead-sources`, `PATCH/DELETE /lead-sources/{code}` and the same for `/lead-stages` manage them with immutable codes, won/lost flags, ordering, activation, and audit; the CRM settings page edits both, and Leads/Assignment Rules read the configured values. | `backend/app/Http/Controllers/Api/LeadConfigController.php`; `backend/app/Modules/CRM/Models/LeadSource.php` / `LeadStage.php` (`toApiArray`); `backend/routes/api.php` (lead-config block); `web/src/pages/LeadSettings.tsx`; `web/src/pages/Leads.tsx`; `web/src/pages/AssignmentRules.tsx`; `backend/tests/Feature/LeadConfigTest.php`; `docs/architecture/openapi.yaml`. | Closed by CRUD/permission/audit tests, in-use and fallback deletion refusals, won/lost exclusivity, and a test proving a custom won stage sets the lead status. | none |
| ~~GAP-CRM-03~~ | **P1 — closed** | No lead activity/timeline read API and no lead detail screen; activities were written but never displayed. Now `GET /leads/{id}` returns the lead, current owner, audit-derived owner history, and newest-first paginated activities; `/leads/:id` renders the timeline, owner history, consent, and conversion links. | `backend/app/Http/Controllers/Api/LeadController.php` (`show`, `ownerHistory`); `backend/app/Modules/CRM/Models/LeadActivity.php` (`actor`, `toApiArray`); `backend/routes/api.php`; `web/src/pages/LeadProfile.tsx`; `web/src/pages/Leads.tsx`; `backend/tests/Feature/LeadProfileTest.php`; `docs/architecture/openapi.yaml`. | Closed by tests covering the detail payload, newest-first pagination, actor names, manual owner-history entries, `leads.view`, branch isolation, and 404. | none |
| ~~GAP-CRM-04~~ | **P1 — closed** | No duplicate detection for phone/email, so the same lead could be captured repeatedly. Now `LeadService::create` matches normalized phone/email against same-branch leads active inside `crm.duplicate_window_days` (default 30, 0 disables), stores `duplicate_of_lead_id`/`duplicate_reason`, writes a `duplicate_flagged` activity and audit event, and returns `duplicate_of` in the API payload. | `backend/app/Modules/CRM/Services/LeadService.php` (`findDuplicate`); `backend/database/migrations/2026_11_23_000100_add_duplicate_tracking_to_leads.php`; `backend/app/Modules/CRM/Models/Lead.php`; `web/src/pages/Leads.tsx`; `backend/tests/Feature/LeadDuplicateTest.php`. | Closed by tests covering phone/email normalization, combined reasons, the window boundary, disabling detection, branch isolation, API payload, and converted leads. | none |
| ~~GAP-CRM-05~~ | **P1 — closed** | No pipeline metrics; `/reports/pipeline` did not exist. Now `GET /reports/pipeline` returns cohort totals plus stage/source/owner breakdowns for leads captured in `period` (`today`, `thisMonth`, `lastMonth`, `customRange`), scoped to the active branch, and the dashboard shows captured/open/won/lost/win-rate with the breakdowns. | `backend/app/Modules/CRM/Services/PipelineReportService.php`; `backend/app/Http/Controllers/Api/PipelineReportController.php`; `backend/routes/api.php`; `docs/architecture/openapi.yaml` (`PipelineReport`); `web/src/pages/Dashboard.tsx`; `backend/tests/Feature/PipelineReportTest.php`. | Closed by tests proving stage/source sums equal captured, conversion and unassigned counts, period/custom-range filtering, branch isolation, and the API contract. | none |
| ~~GAP-CRM-06~~ | **P2 — closed** | Reassignment/escalation produced no notification because no notification infrastructure existed. Now reassignment notifies the new owner and escalation notifies branch users holding `leads.manage_rules`; the bell/list shows them with per-user read state. | `backend/app/Modules/Core/Services/NotificationService.php`; `backend/app/Modules/CRM/Services/LeadService.php`; `web/src/components/NotificationBell.tsx`; `web/src/pages/Notifications.tsx`; `backend/tests/Feature/NotificationTest.php`. | Closed by tests proving the new owner and supervisors receive the right notification and that read state is per user. | overlaps GAP-CORE-01 |

## 5. Core / platform

Implemented today: Sanctum auth with rate limiting, role/permission matrix incl. branch scopes, branch switching, settings and decision registry, audit events, health/ready probes, scheduler heartbeat, install/upgrade/rollback commands, token pruning, demo-mode guards.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| ~~GAP-CORE-01~~ | **P1 — closed** | No in-app notifications anywhere, despite being a planned collaboration primitive. Now `notifications` stores one row per recipient with a public id, branch, type, title/body, JSON entity reference and read timestamp; `GET /notifications`, `POST /notifications/read-all`, and `POST /notifications/{id}/read` serve only the signed-in user, and CRM reassignment/escalation plus invoice/expense approval emit records. | `backend/database/migrations/2026_11_23_000200_create_notifications_table.php`; `backend/app/Modules/Core/Models/Notification.php`; `backend/app/Modules/Core/Services/NotificationService.php`; `backend/app/Http/Controllers/Api/NotificationController.php`; `web/src/components/NotificationBell.tsx`; `web/src/pages/Notifications.tsx`; `backend/tests/Feature/NotificationTest.php`. | Closed by tests covering per-user isolation, unread counts, read/read-all, CRM reassignment and escalation, and invoice/expense approval emission. | none |
| ~~GAP-CORE-02~~ | **P1 — closed** | Audit trail was write-only for operators: no API or screen to browse audit events (only the fixed-asset trail). Now `GET /audit-events` lists events newest-first with actor/action/entity/date/branch filters, `GET /audit-events/export` emits a filtered CSV capped at 50,000 rows and records an `audit.exported` event, and the `/audit-events` screen shows the trail with before/after snapshots. | `backend/app/Http/Controllers/Api/AuditEventController.php`; `backend/database/migrations/2026_11_23_000300_add_branch_to_audit_events.php`; `backend/app/Modules/Core/Models/AuditEvent.php`; `web/src/pages/AuditLog.tsx`; `backend/tests/Feature/AuditEventTest.php`. | Closed by tests covering `audit.view`/`audit.export`, actor/action/entity/date filters, branch scoping, pagination, CSV export, date validation, and branch inference. | none |
| ~~GAP-CORE-03~~ | **P2 — closed** | Attachments did not exist. Now private documents with classification, preview, download, personal-data permission, audit, and optional reviewed automatic reading (ADR-0021). | `backend/app/Modules/Attachments`, `backend/tests/Feature/AttachmentTest.php`, `AttachmentExtractionTest.php`, `web/src/components/AttachmentsCard.tsx` | — |
| ~~GAP-CORE-04~~ | **P3 — closed** | Users could not list or revoke their own sessions. Now `GET /auth/sessions`, `DELETE /auth/sessions/{id}` and `DELETE /auth/sessions` (all other devices) cover the caller's own login tokens only (MCP tokens and other users' tokens are never listed or revocable), audited, with a "Signed-in devices" card on Profile. | `backend/app/Http/Controllers/Api/SessionController.php`; `web/src/components/SessionsCard.tsx`; `backend/tests/Feature/SessionsTest.php`; `docs/architecture/openapi.yaml` | — |

## 6. Frontend / QA / release

Implemented today: design tokens from `DESIGN.md` (light/dark, AR/EN, Suisse Intl incl. Arabic), 38 routed pages, shared UI primitives, global search, demo banner, error/empty/loading states, 88 Vitest unit tests, Playwright critical-flow specs (invoice PDF, report export, lead conversion, payment refund request) plus an axe accessibility sweep.

| ID | Sev | Gap | Evidence | Done when | Blocked by |
|---|---|---|---|---|---|
| GAP-QA-01 | P2 | Browser E2E covers five journeys (invoice PDF, report export, lead→conversion, payment refund request, and now close→report: close the last period, run the trial balance, reopen). Refund approval (maker-checker, needs a second user) is still missing. Dialog accessibility (accrual, revaluation, documents, preview) is covered by `e2e/dialogs.spec.ts`. | `web/e2e/{invoice-pdf,reports-export,lead-conversion,payment-refund,period-close-report,dialogs}.spec.ts`; the state-changing specs only run with `E2E_APPROVE_POLICY=1` (CI sets it; disposable installs only). | Add the refund-approval spec. | none |
| ~~GAP-QA-02~~ | **P1 — closed** | Automated accessibility gate: `web/e2e/accessibility.spec.ts` runs `@axe-core/playwright` (WCAG 2 A/AA) on login, dashboard, bookings, invoices, payments, reports, leads and settings and fails on any serious/critical violation (allowlist `KNOWN_VIOLATIONS`, currently 0 entries). Fixed on the way: dashboard chart canvases had no accessible name, global-search `kbd` hint contrast, unlabeled status/stage filters, keyboard access to scrollable tables. | `web/e2e/accessibility.spec.ts`; `e2e` CI job. Not yet covered: dark theme, Arabic/RTL, modals, detail pages, and pages loaded with rich data. | Closed by the spec running in CI on every PR. | none |
| ~~GAP-QA-03~~ | **P2 — closed** | The backend coverage job is now a blocking gate: it fails below 80% line coverage on `app/Modules/Accounting` (measured 90.4% when enabled) or when the report cannot be parsed. Vitest still gates `src/lib`. | `.github/workflows/ci.yml` job `backend-coverage` | Confirm the number on the first green CI run after merge. |
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
| GAP-DEC-05 | DEC-028 | Statutory tax-invoice wording | Invoices are standard commercial invoices titled "Invoice" / "فاتورة"; no tax-invoice or certification wording. Not a statement about legal obligations. |
| GAP-DEC-06 | DEC-025 | Statutory report formats | Management-grade statements only |

## 9. Deliberately out of scope (do not "fix")

- Scheduled email/WhatsApp delivery of reports (TE-039 scope out).
- BI dashboards / warehouse (TE-040 scope out).
- Bank reconciliation automation (TE-038 scope out).
- Multi-tenancy, customer portals, SMS/email notifications (DEC-020/DEC-022).
- Reverse lead conversion / merge (TE-043 scope out).

## 10. Ordered remediation plan

Order follows the module completion sequence; each step is one branch → one PR with tests.

1. Reports, CRM and notifications gaps (RPT-01/03/04, CRM-01..06, CORE-01, CORE-02) — closed.
2. `agent/qa-harness` — GAP-QA-01 (partial), 02 (closed), 03 (Vitest gate + report-only backend job) done; GAP-QA-04, 05 and close→report E2E remain.
3. `agent/docs-truth-pass` — GAP-DOC-01..05 (closed).
4. Release rehearsal — re-run the PostgreSQL, compose, backup/restore, and N-1 upgrade jobs already in CI.

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
