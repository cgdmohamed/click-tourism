# Accountant sign-off packet — 2026-11-11

- **Update 2026-11-11:** the decisions in §2 were closed by the project owner, who adopted the reference behaviour of Odoo and Zoho Books for each of them (`decisions-closed-2026-11-11.md`). This sheet now records the chosen rule and the go-live operations; the signature table in §5 remains for a client accountant's validation of a specific installation.
- **Purpose:** one page the accountant can read and sign before the first real posting run, listing every accounting choice the software currently makes on their behalf, the exact setting or command behind it, and what stays blocked until they decide.
- **Scope:** the hardening work of 2026-11-10/11 plus the decisions that were already open in `docs/product/decisions-needed.md`.
- **Companion documents:** `docs/audits/accounting-review-2026-11-10.md` (findings), `docs/audits/year-end-close-review-2026-11-11.md` (close/reopen), `docs/product/decisions-needed.md` (full register), `docs/architecture/migration-order.md` (upgrade order).
- **Rule:** nothing below is silently assumed in code. Every open item has either an explicit setting, a disabled-by-default flag, or a guarded default named in this document.

## 0. What the system already refuses to do without you

| Guard | Where | Default |
|---|---|---|
| All posting (automatic and manual) | `accounting.accounting_policy_approved` | `false` — `PostingService::assertActorMayPost()` throws until approved |
| Automatic booking posting | `accounting.auto_post_bookings` | `false` |
| Booking revenue/cost trigger | `accounting.booking_posting_trigger` | `confirm` |
| Tax on bookings and invoices | `tax.enabled` | `false` — `TaxPolicy::assertTaxAllowed()` rejects any non-zero tax |
| Period-end FX revaluation | `accounting.fx_revaluation_enabled` | `false` |
| Unified booking invoice document | `documents.unified_booking_invoice_enabled` | `false` (mode `separate`) |
| Credit-limit enforcement | `accounting.credit_limit_policy` | `warn` (`off` \| `warn` \| `block`) |

Approving the policy itself is a permission (`accounting.approve_policy`), and every change to a policy key is audited (`settings.updated`). The current policy state is visible in the app under Settings → Accounting and through `GET /api/v1/settings`.

## 1. Go-live operations

Run in this order on every existing installation after deploying the new code. The two accounting commands are dry-run first and idempotent.

| # | Step | Command | Needs approved policy? | Writes? |
|---|---|---|---|---|
| 1 | Sync roles and permissions (`bookings.update` and the rest of the additive set) | `php artisan roles:sync` | no | permissions only |
| 2 | Check the accounts 7901/6901 (unrealized FX) and 7920 (bank interest) exist | `php artisan accounting:ensure-accounts` | no | no |
| 3 | Audit duplicate document numbers (read-only, safe any time) | `php artisan accounting:audit-document-numbers` | no | no |
| 4 | **Approve the accounting policy** in Settings (needs `accounting.approve_policy`) | UI / `PUT /api/v1/settings` | — | yes, audited |
| 5 | Preview the 1150 split (employee advances 1151, custody 1152, supplier advances 1153, prepaid 1154) | `php artisan accounting:split-1150` | yes | no |
| 6 | Post the reclassification and update the account mappings | `php artisan accounting:split-1150 --apply` | yes | journal entries |
| 7 | Preview repairs of legacy cross-currency transfers booked entirely in the base currency | `php artisan accounting:repair-foreign-transfers` | yes | no |
| 8 | Write the repairs | `php artisan accounting:repair-foreign-transfers --apply` | yes | journal entries |
| 9 | Create the accounts step 2 reported missing (7901/6901 unrealized FX, 7920 bank interest income; `accounting:ensure-fx-accounts` remains as an alias for 7901/6901 only) | `php artisan accounting:ensure-accounts --apply` | no | chart of accounts |

**The order is forced by the policy guard.** Both `accounting:split-1150` and `accounting:repair-foreign-transfers` refuse to run — including their dry runs — while `accounting.accounting_policy_approved` is `false`, because both post or reverse journals. So the policy must be approved before you can even preview the fixes. `accounting:ensure-accounts` and `accounting:audit-document-numbers` are not posting commands and run before approval.

Step 6 changes `accounting.supplier_advance_account_code` to `1153`; step 8 must run after it so repairs use the final mapping. Step 9 must run before the first revaluation, otherwise `RevaluationService` stops with "FX revaluation account 7901 does not exist".

**Step 3 is a gate, not a fix.** No unique index is added on `journal_entries.number`, `refunds.number`, `transfers.number`, `expenses.number` or `accounting_documents.number` yet. New numbers are already unique because the sequence locks a per-branch row; the risk is rows written before the branch code became part of the number. Renumbering a posted document changes an issued legal number, so it needs your decision (§2.7) and a written remediation before any index is added.

## 2. Decisions requiring your signature

Each section states what the software does today, the exact knob behind it, and what stays blocked while the decision is open. The engineering views below are recommendations, not accounting rulings.

### 2.1 Opening balances and the cutover (DEC-011, DEC-033)

- **Today:** an opening balance is a batch of balanced lines per branch, posted once per branch and date, reversible with a reason (`OpeningBalanceService`). Multi-currency batches must balance per currency and receivable/payable lines must name the party. There is **no** importer from a legacy system and **no** automatic backfill: a cutover is a manual batch entry.
- **Why it matters:** the batch is the only place where pre-system balances enter the ledger. If the cutover date, the level of detail (per customer/supplier vs. control account) or the treatment of old unreconciled items is wrong, every later ageing report inherits the error.
- **Needs from you:** cutover date, which balances are imported (per-party or control totals), and whether pre-cutover open documents are re-entered as live documents or as a single opening line.
- **Blocks:** first production posting run; DEC-033 acceptance.

### 2.2 Revaluation scope and the close order (DEC-007, DEC-008, DEC-012)

- **Today:** revaluation covers the mapped AR (1100), AP (2100), customer advances (2300), supplier advances (1153) and every cash/bank account, with their sub-accounts. Only foreign-currency balances are touched; the native balance is never converted twice; the entry posts base-only lines and is auto-reversed on the first day of the next period (`fx_revaluation_auto_reverse = true`).
- **Order:** revaluation must run **before** the year-end close. After the close the period is `closed` and `PostingService` refuses the revaluation entry, so the year loses the unrealized difference permanently.
- **Gap:** the year-end checklist has no line for "unrevalued foreign balances". An accountant can close a year with FX exposure and only notice in the next period.
- **Needs from you:** (a) confirm the account scope, (b) confirm revalue-then-close as the written procedure, (c) decide whether an unposted revaluation must **block** the close or only warn. Engineering recommends block, because the fix afterwards is a prior-period adjustment.
- **Blocks:** enabling `fx_revaluation_enabled`; DEC-034 procedure.

### 2.3 Tax (DEC-009, DEC-021, DEC-032, DEC-036)

- **Today:** `tax.enabled = false` and `tax.default_rate = 0`. Booking items choose a tax profile (rate with up to four decimals plus an `is_inclusive` flag); customer invoice lines carry an explicit `tax_minor` and a tax account instead of a profile. The booking form only offers a tax profile when tax is enabled, and the server rejects any non-zero tax while it is disabled. The server rounds half-up to the minor unit and the web form mirrors the same rule (pinned by tests on both sides).
- **Mappings to confirm:** output tax / tax payable `2200`, input tax `1160`, and whether input tax on expenses is recoverable at all.
- **Needs from you:** the tax point (invoice date vs. service date vs. payment), the default rate, whether prices are quoted inclusive, and the treatment of withholding on commissions.
- **Blocks:** `tax.enabled`; commission tax lines; expense input tax.

### 2.4 Credit limit (DEC-025)

- **Today:** `accounting.credit_limit_policy = warn`. `warn` shows the over-limit amount and continues; `block` refuses the invoice unless the user holds the override permission and supplies a reason, which is audited as `customer.credit_limit_overridden`; `off` disables the check. Exposure is the customer's posted receivable balance (so receipts and credit notes reduce it) **plus** confirmed bookings that have neither a revenue entry nor an issued invoice yet. The limit itself is stored on the customer in base-currency minor units; `0` means "no limit".
- **Needs from you:** the policy (`off`/`warn`/`block`) and, if `block`, who may override.
- **Blocks:** nothing technically; the invoice screen already implements all three modes.

### 2.5 Refunds and cancellations (DEC-006, DEC-019)

- **Today:** a refund is requested against a posted receipt (`refunds.create`) and approved separately (`refunds.approve`); `sales` holds only the first, the accountant role holds both. The approval screen accepts the refund FX rate explicitly (no silent rate 1). A refund reduces the booking allocation first and the unallocated advance second, and refuses to exceed what the receipt still holds. Invoice-settled money must be unapplied before it can be refunded; the error message says so.
- **Gap:** approval is separated by **permission**, not by identity. `requested_by` and `approved_by` are both stored, but nothing compares them, so a user who holds both permissions (any `admin`, or the accountant role) can approve their own request. Enforcing requester ≠ approver is DEC-017 (segregation of duties).
- **Gap:** whether a cancelled booking keeps a cancellation fee, and how that fee is taxed and recognised, is not decided. The code can post a `cancellation_fee` entry but nothing in the UI or policy tells an operator when to use it.
- **Needs from you:** the cancellation/refund rule and the fee treatment.
- **Blocks:** DEC-006 acceptance; nothing in the refund mechanics.

### 2.6 Balance-sheet current / non-current split (no decision ID yet)

- **Today:** the split is by account-code prefix and the report says so: `classification = "provisional_by_code"`. Assets starting `11` are current; `21`–`24` liabilities are current; everything else is non-current.
- **Risk:** a long-term receivable under `11*` or a short-term loan outside `21`–`24` is classified wrongly with no warning.
- **Needs from you:** approve the classification or request an explicit per-account flag. Engineering recommends adding a decision ID (suggested DEC-041) so it appears in the in-app decision list instead of only in a code comment.
- **Blocks:** any external report that relies on the current ratio.

### 2.7 Duplicate document numbers and amendment policy (DEC-018 decided, DEC-019 open)

- **Today:** `accounting:audit-document-numbers` reports duplicates per branch across journal entries, refunds, transfers, expenses and accounting documents. It never renumbers. Unique indexes are deliberately deferred until the client's audit is clean.
- **Needs from you:** what to do with a duplicate found on a posted document (leave and annotate, or renumber with a memo entry), and the amendment policy that decides it.
- **Blocks:** the unique indexes on the five tables.

### 2.8 Year-end close (DEC-012, DEC-034)

- **Today:** closing entries are ordinary posted journals, one per branch with activity, dated on the last day of the year, tagged `source_type = fiscal_year`; reopening reverses exactly those entries. The checklist blocks on drafts, submitted expenses, an unbalanced year, a missing/invalid retained-earnings account (3100), a closed final period, and (new) a year with P&L activity whose year-end date is not covered by an open period.
- **Not checked:** unreconciled bank statements, open supplier obligations, approved-but-unissued documents, requested-but-unapproved refunds, and next-year schedules. None of them is a ledger error; whether any must block the close is your call.
- **Reopen semantics:** reopening sets **every** period of the year back to `open`, including months soft-closed for review. Balances are restored correctly, the period review state is not. This is intended but should be in the written procedure.
- **Soft-closed year end:** the checklist accepts a year-end period in `soft_closed` state, but posting into it needs `periods.post_soft_closed`. An accountant without that permission sees the checklist pass and then gets a posting error. Either require the year-end period to be `open`, or state in the procedure that closing a soft-closed year needs that permission.
- **Needs from you:** the blocking list and the written reopen procedure, including who may reopen and whether a reason must name the affected periods.
- **Blocks:** DEC-034 acceptance; nothing in the close mechanics.

### 2.9 Revenue, cost and rounding defaults (DEC-001, DEC-002, DEC-010)

- **Today:** revenue and cost post at booking **confirmation** when auto-posting is enabled (it is off by default), and the mapping is revenue `4100` / cost `5100` / unearned `2400` / retained `3100`. Rounding is half-up to the minor unit into `6999`.
- **Needs from you:** confirm the recognition point per product type and that `6999` is the residual account you want.
- **Blocks:** enabling `auto_post_bookings`.

### 2.10 Reporting findings

- **Bank interest default:** `accounting.bank_interest_account_code` defaults to `4100` (Service Revenue). Interest credited during a bank reconciliation would therefore appear as tour revenue. Engineering recommends a dedicated interest-income account and asks for the code to use (DEC-037).
- **Trial balance CSV layout** is unchanged and documented in ADR-0014 and the OpenAPI spec: columns are stable, minor units, one row per account, `classification` included on the balance sheet export only. Changing the layout would break the accountant's existing templates, so it stays until you ask.
- **Dashboard "collected"** is now period-scoped to the selected range instead of all-time; the label and totals for the current month changed accordingly.

## 3. State of the decision register

`GET /api/v1/decisions` returns the live list; `backend/app/Modules/Core/Services/DecisionRegistry.php` is the source.

| State | Count | Meaning |
|---|---|---|
| `open` | 28 | Not decided; a guarded default is in place |
| `pending_accountant_approval` | 5 | Implemented behind a switch, waiting for your approval (DEC-004, DEC-008, DEC-014, DEC-025, DEC-028) |
| `decided` | 7 | Recorded as decided (DEC-015, 016, 018, 020, 022, 029, 040) |

Resolving a decision in the app requires `settings.update`, writes a `decision.resolved` audit entry, and stores the resolution so the health endpoint and the decisions screen stop reporting it as open.

## 4. What engineering cannot close

| Item | Why | What would close it |
|---|---|---|
| Demo-stack verification (year-end close, USD lifecycle through the UI) | Docker daemon unavailable in the engineering environment; verification was PHP-level only | One run on a machine with Docker: reset the demo DB, post activity, close/reopen from the UI, confirm the balance sheet balances |
| Unique indexes on document numbers | Needs a clean audit plus §2.7 | A clean `accounting:audit-document-numbers` on the client data and your remediation rule |
| Opening-balance backfill | Needs §2.1 | Cutover date and import policy |
| Remaining 100-row dropdown caps | Invoices, Documents, OpeningBalances, Reports, SupplierSettlements and the apply-to-invoice modal still fetch the first 100 parties; the searchable component now supports server-side search, so it is mechanical work | A follow-up ticket per screen |
| All items in §2 | Accounting policy | Your signature below |

## 5. Sign-off

Approving the accounting policy in the app is the operative act; this sheet is the audit trail behind it.

| # | Item | Decision chosen | Approved by | Role | Date |
|---|---|---|---|---|---|
| 1 | Cutover date and opening-balance import policy (§2.1) | | | | |
| 2 | Revaluation account scope, order and whether it blocks the close (§2.2) | | | | |
| 3 | Tax point, default rate, inclusive/exclusive, input tax (§2.3) | | | | |
| 4 | Credit-limit policy and override authority (§2.4) | | | | |
| 5 | Cancellation fee and refund rule (§2.5) | | | | |
| 6 | Current / non-current classification (§2.6) | | | | |
| 7 | Duplicate-number remediation and amendment policy (§2.7) | | | | |
| 8 | Year-end close blocking list and reopen procedure (§2.8) | | | | |
| 9 | Revenue/cost recognition point and rounding account (§2.9) | | | | |
| 10 | Bank interest income account (§2.10) | | | | |

**Go-live gate:** steps 1–3 of §1 may run before signature (they only read data or create nothing). Steps 4–9 change the chart of accounts, post reclassification journals, repair history, or flip a policy switch; they require this sheet to be signed and the policy approved in the app (step 4). Enabling `auto_post_bookings`, `tax.enabled`, `fx_revaluation_enabled` or `credit_limit_policy = block` additionally requires the matching row above to be decided.
