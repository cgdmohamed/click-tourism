# EP-04 — Customers, Suppliers, and Payees

---

## TE-022 — Customer master

**Goal:** Customer records used by bookings, payments, and reports.
**Scope in:** Customer CRUD, individual/company, AR/EN names, contacts, tax ID, billing address, credit terms/limit, branch ownership, status, duplicate detection, deactivation, merge.
**Scope out:** Customer portal.
**Dependencies:** TE-004, TE-020.
**API contract changes:** `/customers`.
**Acceptance criteria:** AC-PARTY-001, AC-PARTY-002, AC-PARTY-005, AC-PARTY-006.
**Test cases:** required fields, duplicate phone normalization, delete with postings blocked, branch isolation, merge audit.
**Owned files:** `backend/app/Modules/Parties/Customers`, tests.
**Reviewer:** Architect.
**Status:** done — `/customers`; `PartyProfileTest`.

---

## TE-023 — Supplier master, categories, and payees

**Goal:** Supplier records by tourism category, plus commission payees.
**Scope in:** Supplier CRUD, categories (flight/hotel/transport/visa/agent/other), contacts, tax ID, settlement currency, payment terms, status, agent/employee payee records.
**Scope out:** Supplier portal, procurement/PO.
**Dependencies:** TE-004, TE-020.
**API contract changes:** `/suppliers`, `/payees`.
**Acceptance criteria:** AC-PARTY-003, AC-PARTY-005; category filters work; payee linkable to commission rules.
**Test cases:** category validation, duplicate detection, deactivation with obligations, payee deactivation with open accruals.
**Owned files:** `backend/app/Modules/Parties/Suppliers`, tests.
**Reviewer:** Architect.
**Status:** done — `/suppliers`, `/employees`; `PartyProfileTest`, `EmployeeFinanceTest`.

---

## TE-024 — Party statements and balance drill-down

**Goal:** Customer/supplier statements reconciling to the ledger.
**Scope in:** Statement query by date range/branch/currency, opening balance, documents, allocations, closing balance, PDF/CSV hook, drill-down API.
**Scope out:** Report rendering engine (EP-08).
**Dependencies:** TE-022, TE-023.
**API contract changes:** `/customers/{id}/statement`, `/suppliers/{id}/statement`.
**Acceptance criteria:** AC-PARTY-004, AC-FIN-012.
**Test cases:** opening balance, partial allocation, refund, multi-currency, branch filter, empty statement.
**Owned files:** `backend/app/Modules/Parties/Statements`, tests.
**Reviewer:** Accountant.
**Status:** done — `/reports/party-statement`, `/employees/{id}/statement`; `FinancialReportsReconciliationTest`.
