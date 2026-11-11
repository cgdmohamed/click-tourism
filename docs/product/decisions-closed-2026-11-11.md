# Accounting and product decisions — closed 2026-11-11

- **Decided by:** the project owner.
- **Basis:** the accounting reference for this product is the behaviour of well-known systems, **Odoo** and **Zoho Books**. Wherever a decision was open, the system keeps (or was changed to) the behaviour those products ship by default, unless the decision is a pure product choice.
- **Effect:** `DecisionRegistry` marks all 40 decisions `decided`; `GET /api/v1/decisions` and `/health` report none as open (`?all=1` lists them with their history). No further accounting decisions are pending. A new decision would be added to the registry as `open` and would show again.
- **Not done:** the chart of accounts, tax rates and legal wording of a specific client were not validated by that client's accountant. `AGENTS.md` still applies to a client go-live: financial rules must be validated by the client's accountant before real postings, and the sign-off sheet (`accountant-signoff-packet-2026-11-11.md`) is the place to record that.

## 1. Rulings and the reference behaviour behind them

| Topic | Ruling in the product | Odoo / Zoho reference | Where |
|---|---|---|---|
| Revenue recognition (DEC-001) | On the **issued invoice**. Posting revenue from a booking is optional and off by default (`auto_post_bookings = false`); a booking that already recognized revenue blocks the invoice posting it a second time. | Revenue is posted by the customer invoice, not by the sales order | `InvoiceService::issue`, `BookingService` |
| Supplier cost (DEC-002) | On the **supplier invoice**. Booking-cost posting is optional; a supplier invoice linked to a booking that already posted its cost is refused (and the reverse). | Cost/expense is posted by the vendor bill | `BookingService::postCost`, `InvoiceService::issue` |
| Commissions (DEC-003, 004, 013, 021) | Expense + payable; accrued when the booking is **fully collected**; most-specific rule wins; no commission tax in the MVP. | Commission = expense and payable once the invoice is paid | settings `commission_*` |
| Customer advances (DEC-005) | Liability (2300) until applied to an invoice or booking. | Unapplied customer payment sits as a credit until reconciled | `PartyLedgerService`, `InvoiceSettlementService` |
| Cancellation fee (DEC-006) | Charged when the booking is cancelled and posted as revenue; the refund is a separate refund document. | Fee is a line on the invoice/credit note | `BookingService::cancel` |
| FX (DEC-007, 008) | Manual daily rate table, rate snapshot on each document; realized gain/loss at settlement against the original rate; **monthly revaluation with automatic reversal** into dedicated unrealized accounts (7901/6901). Revaluation before the year-end close is shown as a warning, not a blocker. | Exchange difference on payment; unrealized revaluation with auto-reversal | `RealizedFxService`, `RevaluationService` |
| Tax (DEC-009, 021, 032, 036) | Tax is off until enabled; per-profile rate; **tax point = invoice date**; amounts exclusive unless the profile is marked inclusive; input tax is recoverable through the input-tax account. | Taxes defined per tax with a "included in price" flag; tax computed on the invoice | `TaxPolicy`, tax profiles |
| Rounding (DEC-010) | Per line, `half_up` or `bankers` by setting; the residue goes to 6999. | Rounding method configurable | `RoundingPolicy`, `Money` |
| Opening balances (DEC-011, 033) | One dated opening batch per branch; each customer/supplier balance becomes an **open document** that is settled like an invoice. Cut-over date = go-live date. | Opening entry plus open items per partner | `OpeningBalanceService` |
| Fiscal year and close (DEC-012, 034) | Configurable year start; the close posts reversible closing entries per branch; the final period must be **open**; reopen needs `periods.reopen`. | Fiscal-year lock dates and unaffected earnings | `FiscalCloseService` |
| Branches (DEC-014) | One ledger, branch as a dimension. | Analytic/branch dimension on one company | `BranchScope` |
| Approvals (DEC-017) | Optional. Maker-checker stays on vouchers, expenses, journal drafts; a **senior accountant** may post own drafts. Refund requester ≠ approver is a setting (`accounting.refund_segregation`, default off). | Approval rules are optional per document type | seeders, settings |
| Deletion / amendment (DEC-019) | Never delete a posted document: reverse or issue a credit note; drafts may be deleted. | Posted entries are immutable; credit notes and reversals | `PostingService` |
| Document numbering (DEC-018) | `PREFIX-BRANCH-YEAR-NNNNNN`, per branch and year; posted numbers are never rewritten; duplicates found by the audit command are annotated, and a unique index is added only when the audit is clean. | Unique sequence per journal | `SequenceService`, `accounting:audit-document-numbers` |
| Cash and transfers (DEC-031) | Direct transfers between cash/bank accounts, cross-currency posted per currency; overdraft allowed unless `block_negative_cash`. | Internal transfer account, overdraft allowed | `TreasuryService` |
| Employee advances/custody (DEC-035) | Separate accounts 1151 (advances) and 1152 (custody); 1153 supplier advances; 1154 prepaid expenses. | One receivable-type account per advance kind | `Account1150SplitService` |
| Bank reconciliation (DEC-037) | Tolerance 0, date window 5 days, auto-match off; interest income to its own account **7920**. | Reconciliation models, interest income account | `BankReconciliationService` |
| Accrual/prepayment (DEC-038) | Schedule-based monthly recognition with optional reversal. | Deferred revenue/expense models | `AccountingScheduleService` |
| Fixed assets (DEC-039) | Straight-line monthly; configurable convention; gain/loss accounts on disposal. | Asset models (linear) | `FixedAssetService` |
| Balance-sheet classification | By an explicit **account classification** (current asset, non-current asset, fixed asset, prepayment, current/non-current liability), not by code prefix. | Account types: Current Assets, Non-current Assets, Fixed Assets, Prepayments, Current/Non-current Liabilities (Odoo); Other Current Asset, Fixed Asset, Long Term Liability (Zoho) | `accounts.classification` |
| Credit limit (DEC-025) | Warn by default; `block` with override permission and audited reason; `off` available. | Credit limit warning, optional blocking | `CreditLimitService` |
| Negative margin (DEC-025) | Needs manager approval before confirmation (setting). | — | `BookingService` |
| Booking invoice (DEC-028) | Separate documents (booking, then invoice). | Sales order then invoice | settings `booking_invoice_mode` |

## 2. Product decisions closed the same way

| ID | Ruling |
|---|---|
| DEC-015, 016, 018, 020, 022, 029, 040 | Already decided before this date (reassignment, lead strategy, numbering, one legal entity per installation, no portal, document language, export formats). |
| DEC-023 Payment methods and cash reconciliation | Payment methods are configurable records linked to a cash/bank account. |
| DEC-024 Data retention and PII | No automatic deletion; retention is a per-installation operational policy. |
| DEC-026 Notification channels | In-app first; email/WhatsApp are integrations, not core. |
| DEC-027 Idempotency scope | `Idempotency-Key` per user and endpoint on creating operations. |
| DEC-030 Reference data seeding | Seeders create currencies, chart of accounts, treasury accounts, tax profiles, CRM stages and roles. |

## 3. What changed in the code with this closure

- `DecisionRegistry`: all entries `decided`; documentation and tests updated.
- Year-end checklist: the final period must be **open** (a soft-closed final period used to pass the check and then fail at posting); unrevalued foreign balances appear in `warnings` and never block.
- Refunds: optional requester ≠ approver rule (`accounting.refund_segregation`).
- Bank interest: default account moved from 4100 (service revenue) to a dedicated **7920 Bank Interest Income** (existing installs keep their stored value).
- `account classification` for the balance sheet (separate pull request).
