# Traceability Matrix — Tourism ERP

Status: Draft. Links the supplied source scope to product requirements, modules, delivery issues, and observable acceptance criteria.

## 1. Source scope → requirements

| # | Source scope item (`reference/SOURCE_SCOPE.md`) | PRD requirements | Module | Issues | Acceptance criteria |
|---|---|---|---|---|---|
| S1 | Journals, automatic/manual entries | FR-FIN-003, FR-FIN-004, FR-FIN-009 | Accounting | TE-015, TE-016 | AC-FIN-003..007, AC-FIN-014 |
| S2 | Cash/banks, transfers | FR-FIN-006 | Accounting | TE-018 | AC-FIN-009, AC-FIN-010 |
| S3 | Expenses | FR-FIN-007 | Accounting | TE-019 | AC-FIN-011 |
| S4 | Customers | FR-PARTY-001, FR-PARTY-002 | Parties | TE-022, TE-024 | AC-PARTY-001, AC-PARTY-002, AC-PARTY-004 |
| S5 | Suppliers (flight/hotel/transport/visa/agents) | FR-PARTY-003, FR-PARTY-004, FR-PARTY-006 | Parties | TE-023, TE-024 | AC-PARTY-003, AC-PARTY-004 |
| S6 | Employees/agents (as payees) | FR-PARTY-006 | Parties/Commissions | TE-023, TE-034 | AC-COMM-001..005 |
| S7 | Branches | FR-CORE-003, BR-AUTH-002, BR-LEDGER-006 | Core | TE-004 | AC-CORE-004, AC-CORE-005 |
| S8 | Opening balances | FR-FIN-005 | Accounting | TE-017 | AC-FIN-008 |
| S9 | Partial collections | FR-PAY-001, FR-PAY-002 | Payments | TE-029 | AC-PAY-002, AC-PAY-003 |
| S10 | Refunds/cancellation fees | FR-PAY-003, FR-BOOK-006 | Bookings/Payments | TE-026, TE-030 | AC-BOOK-008, AC-PAY-006, AC-PAY-007 |
| S11 | Fiscal closure | FR-FIN-002, FR-FIN-010 | Accounting | TE-014, TE-021 | AC-FIN-004, AC-FIN-013 |
| S12 | Nested accounts | FR-FIN-001 | Accounting | TE-013 | AC-FIN-001, AC-FIN-002 |
| S13 | Currencies EGP/SAR/USD + configurable | FR-CORE-005, BR-MONEY-001..005 | Money | TE-011, TE-012 | AC-MONEY-001..005 |
| S14 | Manual rate per transaction | FR-CORE-005, BR-MONEY-003 | Money | TE-012 | AC-MONEY-003 |
| S15 | FX differences | BR-MONEY-004, BR-MONEY-005 | Money/Accounting | TE-011, TE-016 | AC-MONEY-005 |
| S16 | Bookings: flight/hotel/transport/visa/programs | FR-BOOK-001, FR-BOOK-002 | Bookings | TE-025 | AC-BOOK-001, AC-BOOK-002 |
| S17 | Multi-service bookings | FR-BOOK-001, FR-BOOK-002 | Bookings | TE-025 | AC-BOOK-002, AC-BOOK-005 |
| S18 | Headcount, dates | FR-BOOK-001 | Bookings | TE-025 | AC-BOOK-001 |
| S19 | Price/cost/supplier/owner | FR-BOOK-002, FR-BOOK-004 | Bookings | TE-025, TE-027 | AC-BOOK-002, AC-BOOK-005 |
| S20 | Booking profitability | FR-BOOK-005, FR-RPT-006 | Bookings/Reporting | TE-027, TE-040 | AC-BOOK-009, AC-COMM-005 |
| S21 | Profit/commissions by booking/customer/agent/sales/branch | FR-COMM-001..005, FR-RPT-006 | Commissions/Reporting | TE-034, TE-035, TE-040 | AC-COMM-001..005 |
| S22 | Income statement, balance sheet, TB, GL | FR-RPT-001..004 | Reporting | TE-036, TE-037 | AC-RPT-001..003 |
| S23 | Statements, aging | FR-FIN-008, FR-RPT-005 | Accounting/Reporting | TE-020, TE-038 | AC-FIN-012, AC-RPT-004, AC-PARTY-004 |
| S24 | Expense/revenue and profit reports, period/branch/person | FR-RPT-006 | Reporting | TE-040 | AC-RPT-005, AC-BOOK-009 |
| S25 | Branded PDF | FR-RPT-008, FR-UI-006 | Reporting | TE-039, TE-052 | AC-RPT-006 |
| S26 | CRM: Meta lead forms | FR-INT-001, FR-CRM-002 | Integrations/CRM | TE-044 | AC-INT-001..003 |
| S27 | CRM: WhatsApp Business | FR-INT-002 | Integrations | TE-045 | AC-INT-004, AC-INT-005 |
| S28 | CRM: manual/Excel/CSV | FR-CRM-002, FR-DATA-001 | CRM/DataTransfer | TE-041, TE-047 | AC-CRM-001, AC-DATA-001..004 |
| S29 | Configurable lead assignment + 24h reassignment | FR-CRM-004, FR-CRM-005 | CRM | TE-042 | AC-CRM-002..004 |
| S30 | Configurable stages and sales metrics | FR-CRM-001, FR-CRM-008 | CRM | TE-041, TE-043 | AC-CRM-005, AC-CRM-008 |
| S31 | 10 users, 2–3 branches, flexible roles | FR-CORE-002, FR-CORE-003 | Core | TE-003, TE-004 | AC-CORE-003..005 |
| S32 | Web responsive only, no native app | FR-UI-001..007, NFR-006 | Frontend | TE-049..TE-052 | AC-UI-001..008 |
| S33 | No legacy full migration | FR-DATA-001..005 (master data only) | DataTransfer | TE-047, TE-048 | AC-DATA-001..006 |
| S34 | External API fees/server/domain excluded | Non-goals §1.2, assumptions A-006 | — | Release docs | — |
| S35 | Usage license, not source ownership | Non-goals §1.2 | — | Release docs | — |

## 2. PRODUCT_CONTEXT → requirements

| Context statement | Coverage |
|---|---|
| Self-hosted, one product, per-customer install, no multi-tenancy | §2 deployment model; DEC-020; NFR-010, NFR-011 |
| Laravel + React/TS/Vite + Tailwind/DS + PostgreSQL + Redis + Docker + Actions | §2; TE-001; NFR-010, NFR-012 |
| Sanctum auth, Spatie/policies | FR-CORE-001, FR-CORE-002; TE-002, TE-003 |
| Double-entry, CoA, journals, cash/bank, expenses, opening balances, fiscal close | FR-FIN-001..010 |
| Multi-currency + editable FX per transaction | FR-CORE-005; BR-MONEY-001..005 |
| Suppliers, customers, bookings, partial payments, refunds, profitability, commissions | FR-PARTY-*, FR-BOOK-*, FR-PAY-*, FR-COMM-* |
| Financial reports + PDF | FR-RPT-001..008 |
| Configurable tax | FR-TAX-001..003 |
| CRM + Meta/WhatsApp/manual/CSV + assignment/24h + pipeline + branch/user sales | FR-CRM-*, FR-INT-* |
| Roles and branch scopes | FR-CORE-002, FR-CORE-003; BR-AUTH-* |
| CSV/Excel import/export | FR-DATA-* |
| RTL Arabic + English responsive | FR-UI-*, NFR-005, NFR-006 |
| Web only, external credentials/fees out of scope | Non-goals §1.2 |
| No floats; DECIMAL + minor units | BR-MONEY-001; NFR-009 |
| Balanced immutable postings, reversal corrections | BR-LEDGER-001..004 |
| Idempotent actions/webhooks | BR-DATA-002; AC-PAY-004, AC-INT-002, AC-DATA-003 |
| Policy checks all endpoints, audit, redaction, timezone | FR-CORE-006; NFR-003, NFR-004; AC-CORE-003, AC-CORE-008 |
| Backup/restore, migration verification | NFR-002, NFR-010; AC-NFR-002 |
| No production secrets | NFR-003; AC-SEC-001 |
| Accountant validation of financial rules | `decisions-needed.md` ACC items |
| Inspect repo/docs first; no overwrite; issue-scoped branch; no force push | AGENTS.md; delivery rules in `epics.md` |

## 3. PRD → issue coverage

| Epic | Issues | PRD requirements |
|---|---|---|
| EP-01 Foundation | TE-001..TE-010 | CORE-001..010, NFR-001..012 |
| EP-02 Money/FX | TE-011..TE-012 | FR-CORE-005, BR-MONEY-001..005 |
| EP-03 Accounting core | TE-013..TE-021 | FIN-001..010 |
| EP-04 Parties | TE-022..TE-024 | PARTY-001..006 |
| EP-05 Bookings | TE-025..TE-028 | BOOK-001..009 |
| EP-06 Payments | TE-029..TE-032 | PAY-001..008 |
| EP-07 Tax/Commissions | TE-033..TE-035 | TAX-001..003, COMM-001..005 |
| EP-08 Reporting | TE-036..TE-040 | RPT-001..010 |
| EP-09 CRM | TE-041..TE-043 | CRM-001..009 |
| EP-10 Integrations | TE-044..TE-046 | INT-001..005 |
| EP-11 Data transfer | TE-047..TE-048 | DATA-001..005 |
| EP-12 Frontend | TE-049..TE-052 | UI-001..007 |
| EP-13 QA/Release | TE-053..TE-054 | NFR-001..012, release criteria |

## 4. Unmapped / at-risk items

| Item | Status | Action |
|---|---|---|
| Full Safe Way proposal details beyond summary | **MISSING** | Human to supply; PRD re-baseline required. |
| Design system assets/tokens | **MISSING** | Blocks TE-010, TE-049..TE-052; run step 02 when supplied. |
| Accountant-approved accounting policies | **PENDING** | Blocks EP-03, EP-06, EP-07, EP-08 defaults. |
| Meta/WhatsApp credentials and app review | **PENDING** | Blocks live integration acceptance; mock contract tests possible. |
| Statutory report formats (Egypt/GCC) | **PENDING** | Confirm whether management-grade reports suffice. |
| Licensing/legal terms | **PENDING** | Release documentation. |
