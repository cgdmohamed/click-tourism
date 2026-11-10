# EP-08 — Reporting and Exports

---

## TE-036 — General ledger and trial balance

**Goal:** Core ledger visibility.
**Scope in:** GL detail by account/period/branch/party with opening/movement/closing, trial balance by period/branch/currency, drill-down to source document, filters, CSV hook.
**Scope out:** Custom report builder.
**Dependencies:** TE-016, TE-020.
**API contract changes:** `/reports/gl`, `/reports/trial-balance`.
**Acceptance criteria:** AC-RPT-001, AC-RPT-003, AC-RPT-005.
**Test cases:** opening balances, multi-currency, closed periods, branch scope, empty period, large dataset pagination.
**Owned files:** `backend/app/Modules/Reporting/Ledger`, tests.
**Reviewer:** Accountant.
**Status:** Implemented, pending accountant review. `GET /reports/general-ledger` (account/period/branch, opening/running/closing, pagination, descendant accounts, currency and customer/supplier filters), trial balance per currency, CSV export (`reports.export`, audited) and drill-down to the journal entry (which shows its source type) are done with tests and are in `openapi.yaml` (additive; architect to confirm). Known limits: booking-cost postings carry no supplier party (DEC-002), so the supplier filter only matches settlement postings; drill-down opens the journal entry rather than the originating document page. Prompt 28 extended the trial balance with per-account opening/period movement/closing balances and reconciling totals, `max_level` COA roll-up without double counting, cost-center allocated-portion view, `adjustments=include|exclude|only` scope, and general-ledger source document (`source_type`, `source_reference`, `source_public_id`) plus customer/supplier/employee party names. Verified by `FinancialReportsReconciliationTest` (12 tests) and the full backend suite.

---

## TE-037 — Income statement and balance sheet

**Goal:** Management financial statements.
**Scope in:** IS by period/branch with comparatives, BS as of date/branch, account grouping/mapping, retained earnings integration, reconciliation checks, filters.
**Scope out:** Statutory formats, consolidation.
**Dependencies:** TE-036, TE-021.
**API contract changes:** `/reports/income-statement`, `/reports/balance-sheet`.
**Acceptance criteria:** AC-RPT-002; assets = liabilities + equity; net income flows to equity.
**Test cases:** mid-year close, comparatives, branch-only statements, FX balances, mapping gaps.
**Owned files:** `backend/app/Modules/Reporting/Statements`, tests.
**Reviewer:** Accountant.
**Status:** Implemented, pending accountant review. Income statement accepts `compare_from`/`compare_to` and returns `comparison` + `variance`; balance sheet accepts `compare_as_of`, returns `comparison`, `variance` and an explicit `balanced` check. Statutory classifications/groupings remain provisional until the accountant approves the mapping policy. Verified by `FinancialReportsReconciliationTest`.

---

## TE-038 — Aging, statements, and cash book

**Goal:** Operational receivables/payables and cash visibility.
**Scope in:** AR/AP aging with configurable buckets, party statement integration, cash/bank book, transfer listing, filters/export hooks.
**Scope out:** Bank reconciliation automation.
**Dependencies:** TE-020, TE-024, TE-018.
**API contract changes:** `/reports/aging`, `/reports/cash-book`.
**Acceptance criteria:** AC-RPT-004, AC-RPT-005, AC-PARTY-004.
**Test cases:** bucket boundaries, partially allocated documents, multi-currency, branch scope, zero-balance parties.
**Owned files:** `backend/app/Modules/Reporting/Operations`, tests.
**Reviewer:** Accountant.
**Status:** Operational aging remains at `/reports/aging`. Prompt 28 added `/reports/aging-reconciliation` (ledger control-family vs open invoices and unapplied advances, explicit difference codes and per-party breakdown) and `/reports/party-statement` for customer/supplier/employee statements with opening/movements/closing, subaccount split and operational reconciliation. Differences are surfaced, never written off. Verified by `FinancialReportsReconciliationTest`.

---

## TE-039 — Report rendering: PDF, CSV, Excel, branding

**Goal:** Branded, bilingual, correct exports.
**Scope in:** PDF engine (Arabic shaping/RTL, fonts, page numbers, logo/legal identity), CSV/Excel export, async export jobs, download permissions, filter parity, audit.
**Scope out:** Scheduled email delivery.
**Dependencies:** TE-036..TE-038, TE-005, TE-048.
**API contract changes:** `/reports/{type}/export`, `/exports`.
**Acceptance criteria:** AC-RPT-006, AC-RPT-007, AC-DATA-006.
**Test cases:** Arabic PDF rendering, large export, permission/branch leakage, partial failure, filename/encoding.
**Owned files:** `backend/app/Modules/Reporting/Export`, templates, tests.
**Reviewer:** Frontend + accountant.
**Status:** CSV export (UTF-8 BOM, formula neutralisation, audit, branch scope, 50,000-row cap) is implemented for the existing and new reports, and the UI provides filters, pagination and print-ready RTL/AR layouts. Native PDF/XLSX generation is **not implemented**: no library dependency is approved and DEC-040 records the provisional browser print-to-PDF + CSV policy. Do not mark PDF/Excel complete until DEC-040 is decided and an approved package is added.

---

## TE-040 — Profitability, sales, commission, and tax reports

**Goal:** Management reporting by booking/customer/agent/sales/branch/period.
**Scope in:** Revenue/cost/profit reports, sales by user/branch, commission report, tax summary, filters, comparatives where applicable, export hooks.
**Scope out:** BI dashboards/warehouse.
**Dependencies:** TE-027, TE-035, TE-033, TE-039.
**API contract changes:** `/reports/profitability`, `/reports/sales`, `/reports/commissions`, `/reports/tax`.
**Acceptance criteria:** AC-BOOK-009, AC-COMM-005, AC-TAX-004, AC-RPT-005.
**Test cases:** cancelled bookings, refunds, multi-currency, branch/user filters, period boundaries, reconciliation to ledger.
**Owned files:** `backend/app/Modules/Reporting/Management`, tests.
**Reviewer:** Accountant + product.
