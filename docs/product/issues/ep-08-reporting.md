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
**Status:** Implemented, pending accountant review. `GET /reports/general-ledger` (account/period/branch, opening/running/closing, pagination, descendant accounts, currency and customer/supplier filters), trial balance per currency, CSV export (`reports.export`, audited) and drill-down to the journal entry (which shows its source type) are done with tests and are in `openapi.yaml` (additive; architect to confirm). Known limits: booking-cost postings carry no supplier party (DEC-002), so the supplier filter only matches settlement postings; drill-down opens the journal entry rather than the originating document page.

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
