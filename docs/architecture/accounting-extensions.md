# Accounting extensions — contract (TE-017..TE-021)

Status: **Proposed**, implemented behind provisional defaults. Every policy below that is
not an engineering fact is a **decision requiring accountant sign-off** (DEC-031..DEC-034 in
`docs/product/decisions-needed.md`). Nothing here is accountant-approved.

## Audit of what existed before this change

| Area | State | Evidence |
| --- | --- | --- |
| Posting engine, reversals, source idempotency | EXISTING | `PostingService`, unique `(source_type, source_id, event)` |
| Fiscal years/periods, soft-close guard | EXISTING (no checks, no year-end) | `FiscalPeriodController::updateStatus` |
| Cash/bank accounts | PARTIAL: any `11xx` asset account is listed as a "cash account"; no master data, no transfers | `PaymentController::cashAccounts` |
| Expenses | MISSING | — |
| Opening balances | MISSING | — |
| Fiscal close, retained-earnings roll | MISSING | — |
| Operating-expense accounts in the seed chart | MISSING (only cost of services and FX) | `ChartOfAccountsSeeder` |

## Engine constraint that shapes the design

`PostingRequest` carries **one transaction currency per entry**; base amounts come from
`amount × fx_rate`. Therefore:

* Same-currency movements post in that currency.
* Cross-currency transfers post in the **base currency**: each leg is valued at its own
  manual rate and the difference goes to the configured FX gain/loss accounts (DEC-007). The
  foreign amounts are stored on the transfer record. Per-account foreign-currency balances
  are **not** tracked by the ledger (known limitation).
* Opening balances post **one entry per currency**, each balanced on its own.

## Data model (additive migrations only)

* `cash_accounts` — `account_id` (unique FK to a postable asset account), `branch_id` (nullable),
  `kind` (`cash|bank`), names, `currency_code`, `bank_name`, `account_last4` (never the full number),
  `is_active`.
* `transfers` — number (sequence `transfer`), branch, from/to cash account, date, from/to
  amount + currency + rate, optional fee, status (`posted|reversed`), journal links, memo, creator.
* `expense_categories` — code, names, expense `account_id`, `is_active`.
* `expenses` — number, branch, category, date, payee (`supplier|employee|none`), payment source
  (cash account or supplier payable), currency/rate snapshot, gross and tax amounts, status
  (`draft|submitted|approved|rejected|cancelled`), approver, rejection reason, journal link.
* `opening_balance_batches` / `opening_balance_lines` — as-of date, branch, status
  (`draft|posted|reversed`), lines per account/currency/party.
* `fiscal_years` gains `status`, `closed_at`, `closed_by`, `close_count`.

All money is integer minor units. Nothing writes the ledger except `PostingService`.

## Posting examples

Transfer 1,000.00 EGP cash → bank, fee 5.00:
`Dr Bank 1,000.00 · Dr Bank charges 5.00 · Cr Cash 1,005.00` (fee is paid from the source).

Cross-currency 100.00 USD (rate 50) → 5,100.00 EGP, base-currency entry:
`Dr EGP bank 5,100.00 · Cr USD cash 5,000.00 · Cr FX gain 100.00`.

Expense 1,150.00 incl. 150.00 input VAT, paid from cash:
`Dr Expense 1,000.00 · Dr Input VAT 150.00 · Cr Cash 1,150.00`. Approved on credit to a supplier:
`Cr Accounts payable 1,150.00` with the supplier as party.

Opening balances (EGP): `Dr Cash · Dr AR (party) · Cr AP (party) · Cr Owner's capital`, balanced.

Year close (per branch, base currency, dated on the last day of the year):
`Dr each revenue account · Cr each expense account · Cr/Dr Retained earnings (net)`.

## Endpoints

`/cash-accounts` (GET, POST, PATCH), `/transfers` (GET, POST idempotent, `POST /transfers/{id}/reverse`),
`/expense-categories`, `/expenses` (+ `submit`, `approve`, `reject`, `cancel`),
`/opening-balances` (+ `post`, `reverse`, `reconciliation`),
`POST /fiscal-years/{id}/close`, `POST /fiscal-years/{id}/reopen`, `GET /fiscal-years/{id}/close-checks`.
Permissions: `cash_accounts.*`, `transfers.*`, `expenses.*`, `opening_balances.*`, `periods.close`, `periods.reopen`.
Existing `GET /cash-accounts` keeps its shape (`id` = GL account public id) and only gains fields.

## Compatibility and rollback

* Migrations create new tables and add nullable/defaulted columns; `down()` drops only what `up()` added.
* Existing `11xx` accounts remain usable by payments with or without registration as cash accounts.
* Closing entries are identified by `source_type='fiscal_year'`; the income statement excludes them so a closed
  year still reports its profit. The balance sheet gains `current_earnings_minor` so
  `assets = liabilities + equity + current earnings` holds before and after closing.
* Reopening a year reverses (never deletes) its closing entries.

## Decisions required (provisional defaults in brackets)

* **DEC-031** Cash/transfer policy: block negative cash balances? in-transit account for inter-branch or
  bank transfers? FX treatment of cross-currency transfers. [allow negative; no in-transit; FX to gain/loss]
* **DEC-032** Expense policy: approval thresholds, input-VAT account, segregation of duties.
  [no thresholds; maker ≠ checker on; input VAT to `1160`]
* **DEC-033** Opening balances: single batch per branch at cutover, AR/AP must carry a party,
  correction by reversal only. [one posted batch per branch and as-of date]
* **DEC-034** Year-end close: closing entry per branch, retained-earnings account, who may reopen.
  [per branch, account `3100`, `periods.reopen`]
