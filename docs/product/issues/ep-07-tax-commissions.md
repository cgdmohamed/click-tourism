# EP-07 — Tax and Commissions

**Blocked by:** DEC-003/004/009/013/021.

---

## TE-033 — Tax profiles and calculation snapshots

**Goal:** Configurable tax with immutable historical rates.
**Scope in:** Tax profile CRUD (name, rate, inclusive/exclusive, effective dates, sales/purchase accounts), per-line calculation, snapshot on documents, zero-rated/exempt, tax summary query, rounding integration.
**Scope out:** Withholding tax, multi-jurisdiction engine, e-invoicing.
**Dependencies:** TE-013, TE-016, TE-011.
**API contract changes:** `/tax-profiles`, tax snapshot shape on booking items/expenses.
**Acceptance criteria:** AC-TAX-001..005.
**Test cases:** inclusive vs exclusive, zero/exempt, effective-date selection, rate edit after document, rounding, summary reconciliation.
**Owned files:** `backend/app/Modules/Tax`, migrations, tests.
**Reviewer:** Accountant.

---

## TE-034 — Commission rules and accruals

**Goal:** Deterministic commission calculation with immutable accruals.
**Scope in:** Rule CRUD (scope, basis sell/cost/margin/fixed, rate, effective dates, payee), precedence resolver, trigger handling (configurable), accrual creation, void/adjustment, accrual list, payable balance.
**Scope out:** Payee self-service.
**Dependencies:** TE-027, TE-033, DEC-003/004/013.
**API contract changes:** `/commission-rules`, `/commissions`.
**Acceptance criteria:** AC-COMM-001..003; deterministic precedence; replay-safe.
**Test cases:** overlapping rules, rule change after booking, cancelled booking, negative margin, multi-agent booking, trigger variants.
**Owned files:** `backend/app/Modules/Commissions`, migrations, tests.
**Reviewer:** Accountant + product.

---

## TE-035 — Commission approval, payout, and reports

**Goal:** Pay commissions through the ledger.
**Scope in:** Approval workflow, payout batch, posting (`Dr payable / Cr cash-bank`), status lifecycle, reports by booking/customer/agent/sales/branch/period, CSV/PDF hook.
**Scope out:** Payroll integration, tax withholding (DEC-021).
**Dependencies:** TE-034.
**API contract changes:** `/commissions/{id}/approve`, `/commission-payouts`.
**Acceptance criteria:** AC-COMM-004, AC-COMM-005; payable reaches zero after payout; approved accrual immutable.
**Test cases:** segregation of duties, partial payout, cross-currency payout, payout retry, cancelled accrual excluded.
**Owned files:** `backend/app/Modules/Commissions/Payouts`, tests.
**Reviewer:** Accountant.
