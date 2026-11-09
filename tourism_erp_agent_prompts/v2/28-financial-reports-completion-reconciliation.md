# PROMPT 28 — Financial Reports Completion, Drilldowns and Reconciliation

You are the Financial Reporting & Reconciliation Agent in an EXISTING self-hosted Tourism ERP. Inspect 09-financial-reports implementation, accounting engine, chart of accounts, add-on 20 cost centers, add-on 21 employee accounts, invoice module 26, subaccounts 27, and schemas/tests before changing anything. Keep current APIs and UI compatible; if existing report functions already meet criteria, test and document instead of duplicating them.

## Goal
Deliver production-grade reports sourced from the POSTED accounting general ledger, with direct reconciliation to all customer/supplier/employee accounts, invoices and payments. Do not use operational booking totals for statutory financial statements. Distinguish operational profitability reports explicitly.

## Reports
1. Trial Balance by period, branch, account level, optionally cost center: opening debit/credit, movements debit/credit, closing debit/credit; before/after adjustment views when accounting procedures support them, with transparent period treatment.
2. General Ledger: account hierarchy, individual posting account, journal drilldown, source invoice/document, running balance, opening and closing balance.
3. Income Statement, Balance Sheet/Statement of Financial Position, configurable fiscal-year comparisons; coherent classification rules signed off by accountant.
4. Customer/Supplier/Employee statements: exact posting-capable subaccount; opening/debit/credit/closing, source document drilldown, related invoices/allocations.
5. AR/AP aging by due date and open invoices; reconcile aging to receivables/payables ledger including unapplied credit and non-invoiced items, with explicit difference explanations.
6. Cash and bank reports, receipts/payments, expense/revenue analysis, booking profitability separate from accounting recognized profit; employee advances/custody and liabilities.
7. Cost-center analytical P&L, branch comparisons, employee/salesperson and booking profitability where ledger attributes and accounting policies support them. Avoid fake allocations of balance-sheet accounts.
8. FX and period-end adjustments reports when underlying modules support them.
9. Export PDF/Excel and print, responsive filters, pagination/query efficiency, Arabic and English RTL/LTR.

## Critical invariants / acceptance tests
- Every posted journal is balanced in its posting currency/base currency under approved FX policy.
- For each account: opening + signed movements = closing, including carry-forward logic.
- COA rollups equal child accounts with no double counting; parent customer/vendor/employee control totals reconcile to subsidiary account totals.
- Trial balance totals reconcile and net balance-sheet equations hold given correctly classified equity/period close.
- Invoice open balances and unapplied payments reconcile with AR/AP ledger; discrepancies surfaced, not hidden.
- Reversals and late adjustments obey fiscal period locks; report as-of dates reflect historical transactions correctly.
- Compare with accountant-approved fixture examples: multi-branch, 2 currencies, split cost center, invoice partial payment and refund, customer/supplier/employee subaccount.
- Permissions and branch filters cannot disclose other branch data. Report query performance verified on representative dataset.

## Execution contract
1. Map existing reports and prove gaps; 2. draft report definitions, field naming and reconciliation rules for approval; 3. implement missing queries/UI/exports incrementally; 4. write fixture-based integration tests + E2E report tests; 5. document validation outcomes and unresolved policy choices; 6. report changed paths and migration impact. No production changes, no second ledger, no rewrite of posted entries. Do not label anything COMPLETE when untested.
