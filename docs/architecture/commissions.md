# Commissions — contract (TE-034 / TE-035)

Status: implemented on branch `agent/commissions`. Follows the closed decisions of 2026-11-11 (DEC-003/004/013/021) and ADR-0015. No new accounting question was opened; every interpretation below is the Odoo/Zoho-style default and is marked where an accountant may still tune it.

## Model (additive migration `2026_11_22_000100_create_commission_tables`)

| Table | Purpose |
|---|---|
| `commission_rules` | One row per rule **version** (`code` + `version`). Beneficiary `employee` / `agent` (supplier of category agent) / `branch`; basis `percent_revenue`, `percent_margin` or `fixed`; `rate_bp` (basis points) or `fixed_minor`; optional `branch_id` and `service_type` scope; `allow_cross_branch`; `effective_from/to`; `status` draft, pending_approval, approved, rejected, retired. |
| `commissions` | Accrual per booking and beneficiary. Snapshots rule code/version/rate, `basis_amount_minor`, `calculated_minor`, optional `override_minor`, final `amount_minor`, `paid_minor`, journal links. `active_key` is unique while the accrual is live, so a booking can never accrue twice for the same beneficiary. |
| `commission_payments` | Settlement of the payable (one journal each). |

Rollback: `down()` drops the three tables in reverse order. Nothing existing is altered.

## Rules

- **Money:** integer minor units only. Commissions are base-currency obligations: booking revenue (net of tax) and cost are converted with the booking FX rate (`Money::convertTo`, configured rounding policy), then `basis * rate_bp / 10000` is rounded with `Money::divideRounded`. A margin basis is floored at zero (no negative commission).
- **Trigger (DEC-004):** the booking is confirmed or later and allocated receipts cover its whole total. Re-evaluated after receipt, allocation, unallocation, receipt cancellation, refund approval, booking transition and booking cancellation. Only `fully_collected` is implemented; `accounting.commission_trigger` is informational.
- **Precedence (DEC-013):** per beneficiary the most specific rule wins (service type 4, branch 2, named beneficiary 1), then the latest `effective_from`, then the highest version. Rules never stack for one beneficiary; different beneficiaries (seller, agent, branch) each earn.
- **Beneficiary:** a rule without `beneficiary_id` pays the booking owner's employee (employee) or the booking branch (branch); an agent rule always names the agent.
- **Cross-branch eligibility:** a beneficiary attached to another branch than the booking earns only if the rule has `allow_cross_branch`. The expense always lands on the booking branch.
- **Versioning:** approved versions are immutable. A new version is a draft copy; approving it ends the previous version the day before its own `effective_from`. Accruals keep their snapshot.
- **Approval:** rules need a second person (`commissions.segregation`, default true). A commission amount override (`commissions.override`) takes effect only when someone other than the overrider approves; a beneficiary cannot approve their own commission.
- **No commission tax (DEC-021):** no tax or withholding lines exist.

## Posting (PostingService only)

| Event | Entry |
|---|---|
| post (`commission` / `accrual`) | Dr commission expense (`accounting.commission_expense_account_code`, default 6700) / Cr beneficiary payable |
| pay (`commission_payment` / `payment`) | Dr beneficiary payable / Cr cash or bank (base currency, same branch) |
| reversal | `PostingService::reverse` of the accrual entry (event `accrual.reversal`) |

Payable per ADR-0015: agent -> supplier payable subaccount; employee -> employee payable subaccount (new, under the payables control account); branch -> payables control account tagged `party_type=branch`.

Guards: `accounting.accounting_policy_approved=false` makes every post, payment and ledger reversal fail (PostingService). `accounting.commission_auto_post` (default `false`, now a policy switch needing `accounting.approve_policy`) only decides whether approval posts by itself; with the policy unapproved approval still succeeds without posting. Existing installs create account 6700 with `php artisan accounting:ensure-accounts --apply`.

## Reversal

Cancelled booking, refund or unallocation that breaks full collection: an unposted accrual becomes `cancelled`; a posted or paid one is reversed in the ledger and becomes `reversed`. If the ledger refuses (closed period, policy revoked) the commission is flagged `needs_reversal` and the originating cash/booking action still succeeds; an accountant reverses it manually (`POST /commissions/{id}/reverse`). A payout made before the reversal stays booked, leaving a debit balance on the beneficiary payable (the beneficiary owes it back); this is reported in the audit event. A rejected accrual keeps its slot so it is not re-accrued.

## API

See `openapi.yaml`, tag Commissions: `/commission-rules` (+ `new-version`, `submit`, `approve`, `reject`, `retire`), `/commissions` (+ `override`, `approve`, `reject`, `post`, `pay`, `reverse`), `/commissions/beneficiaries`, `/commissions/statement`. Permissions: `commissions.view`, `commission_rules.manage`, `commission_rules.approve`, `commissions.override`, `commissions.approve`, `commissions.post`, `commissions.pay`, `commissions.reverse` (see `docs/product/permission-matrix.md`).

## Known limits (UNVERIFIED / for the accountant)

- Collection is read from booking payment allocations; collection through invoice applications only is not separately detected.
- A partial refund reverses the whole commission; it re-accrues in full once the booking is fully collected again (no proportional reversal).
- Commission payouts are single-commission, single-currency (base); there is no batch payout or payout reversal yet.
- Realised FX on foreign-currency bookings is not applicable because commissions are fixed in base currency at accrual.
