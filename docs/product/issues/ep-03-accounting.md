# EP-03 — Accounting Core

**Blocked by:** EP-01, EP-02, and accountant decisions DEC-001..014 where applicable.

---

## TE-013 — Chart of accounts (nested)

**Goal:** Configurable nested chart of accounts.
**Scope in:** Account CRUD, types, parent/child, postable flag, currency restriction, active/inactive, tree API, seed template (neutral + Egypt/GCC presets pending DEC-030).
**Scope out:** Statutory mapping/e-filing.
**Dependencies:** TE-012.
**API contract changes:** `/accounts`, `/accounts/tree`.
**Acceptance criteria:** AC-FIN-001, AC-FIN-002; max-depth validation; type inheritance; no posting to non-postable.
**Test cases:** deep nesting, cycle prevention, delete/deactivate with postings, branch-independent account behavior.
**Owned files:** `backend/app/Modules/Accounting/Accounts`, migrations, tests.
**Reviewer:** Architect + accountant.

---

## TE-014 — Fiscal years, periods, and locking

**Goal:** Period-aware posting control.
**Scope in:** Fiscal year/period generation, open/soft-close/closed states, posting guard, admin API, audit.
**Scope out:** Statutory filing.
**Dependencies:** TE-013.
**API contract changes:** `/fiscal-years`, `/periods`.
**Acceptance criteria:** AC-FIN-004, AC-FIN-013 (locking part); posting into closed/soft-closed period blocked per permission.
**Test cases:** period boundaries, overlapping years, year-end generation, locked-period override authorization.
**Owned files:** `backend/app/Modules/Accounting/Periods`, migrations, tests.
**Reviewer:** Architect + accountant (DEC-012).

---

## TE-015 — Manual journal entries

**Goal:** Create and post balanced manual entries.
**Scope in:** Draft entry with lines, account/party/branch/dimension fields, transaction + base amounts, validation, post action, list/detail/filter, attachments.
**Scope out:** Recurring entries (later).
**Dependencies:** TE-014.
**API contract changes:** `/journal-entries`.
**Acceptance criteria:** AC-FIN-003, AC-FIN-005 (pre-post edit), AC-FIN-014.
**Test cases:** unbalanced txn currency, unbalanced base currency, non-postable account, closed period, currency-restricted account, duplicate submit.
**Owned files:** `backend/app/Modules/Accounting/Journal`, migrations, tests.
**Reviewer:** Architect + accountant.

---

## TE-016 — Posting engine, reversals, and source tracing

**Goal:** One engine for all postings with immutable history.
**Scope in:** Posting service, posting rule resolver (account roles), transaction wrapper, balance invariant, reversal entry, `reversal_of_id`, `source_type/source_id`, idempotency per source event, FX gain/loss roles, branch/inter-branch roles.
**Scope out:** Business-event adapters (delivered by each module, registered here).
**Dependencies:** TE-015.
**API contract changes:** Posting contract/event catalog entries; `/journal-entries/{id}/reverse`.
**Acceptance criteria:** AC-FIN-003..007, AC-FIN-009, AC-FIN-014, AC-MONEY-005; no partial posting on failure; reversal exactly once.
**Test cases:** rollback on line failure, concurrent post of same source event, reversal twice, reverse reversal, cross-currency settlement, closed-period reversal.
**Owned files:** `backend/app/Modules/Accounting/Posting`, contracts, tests.
**Reviewer:** Architect + accountant.

---

## TE-017 — Opening balances

**Goal:** Controlled cutover balances.
**Scope in:** Opening batch import, per-account/party/currency lines, balance validation, single post, reconciliation report, privileged correction.
**Scope out:** Full legacy migration.
**Dependencies:** TE-016.
**API contract changes:** `/opening-balances`.
**Acceptance criteria:** AC-FIN-008; batch posts once; totals reconcile; re-post blocked.
**Test cases:** unbalanced batch, duplicate batch, foreign currency, AR/AP detail mismatch, correction flow authorization.
**Owned files:** `backend/app/Modules/Accounting/OpeningBalances`, tests.
**Reviewer:** Accountant.

---

## TE-018 — Cash, bank accounts, and transfers

**Goal:** Cash/bank ledger accounts with transfers.
**Scope in:** Cash/bank account master linked to GL account, deposit/withdrawal, internal transfer (same and cross currency), FX difference posting, reconciliation notes.
**Scope out:** Bank feeds, statement import (later).
**Dependencies:** TE-016.
**API contract changes:** `/cash-accounts`, `/transfers`.
**Acceptance criteria:** AC-FIN-009, AC-FIN-010.
**Test cases:** transfer rollback, cross-currency gain/loss, closed period, insufficient-balance policy, duplicate transfer idempotency.
**Owned files:** `backend/app/Modules/Accounting/CashBank`, tests.
**Reviewer:** Accountant.

---

## TE-019 — Expenses

**Goal:** Categorized expenses with approval and posting.
**Scope in:** Expense CRUD, category, supplier/employee payee, tax, currency, payment source, approval thresholds, posting, attachment, list/report.
**Scope out:** Recurring expenses, reimbursement payroll.
**Dependencies:** TE-016, TE-018, TE-033 (tax profile dependency; can stub tax as zero behind flag).
**API contract changes:** `/expenses`, `/expense-categories`.
**Acceptance criteria:** AC-FIN-011; approved expense posts once; rejected expense posts nothing; branch scope enforced.
**Test cases:** approval segregation, tax-inclusive expense, foreign currency, duplicate submit, closed period.
**Owned files:** `backend/app/Modules/Accounting/Expenses`, tests.
**Reviewer:** Accountant + architect.

---

## TE-020 — AR/AP subledger, allocation, and aging queries

**Goal:** Party balances reconciled to control accounts.
**Scope in:** AR/AP subledger entries derived from postings, document allocation model, aging query, statement query, reconciliation report.
**Scope out:** Customer/supplier UI (EP-04), payment creation (EP-06).
**Dependencies:** TE-016.
**API contract changes:** `/subledger/ar`, `/subledger/ap`, `/aging`.
**Acceptance criteria:** AC-FIN-012, AC-PARTY-004 (data part); control-account reconciliation green for seeded scenarios.
**Test cases:** partial allocation, over-allocation blocked, credit notes, multi-currency aging, branch filtering.
**Owned files:** `backend/app/Modules/Accounting/Subledger`, tests.
**Reviewer:** Accountant.

---

## TE-021 — Fiscal close

**Goal:** Close a period safely and reopen with control.
**Scope in:** Pre-close checks, P&L roll to retained earnings, lock, close report, reopen with approval/reason/audit, post-close correction flow.
**Scope out:** Statutory audit packs.
**Dependencies:** TE-017, TE-020.
**API contract changes:** `/periods/{id}/close`, `/periods/{id}/reopen`.
**Acceptance criteria:** AC-FIN-013; net income rolls correctly; reopen is audited; closed period rejects postings.
**Test cases:** close with drafts, close with unreconciled subledger, reopen permission, reversal after reopen, year-end retained earnings.
**Owned files:** `backend/app/Modules/Accounting/Closing`, tests.
**Reviewer:** Chief accountant + architect.
