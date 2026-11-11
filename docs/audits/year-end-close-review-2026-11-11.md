# Year-end close review — 2026-11-11

- **Scope:** `FiscalCloseService` (checks, close, reopen), the `fiscal-years` API, the period-status guards in `PostingService`, and the year-end interaction with FX revaluation.
- **Method:** code review plus PHP-level feature tests (`FiscalCloseTest`, `UsdLifecycleTest`). The demo stack was **not** exercised: the Docker daemon is unavailable in this environment (see §5).
- **Status legend:** *verified* = covered by a passing test in this review; *from code* = read but not executed end to end in a browser/API session.

Nothing here changes the accounting policy. Findings that need an accountant decision are listed as such and were not implemented.

## 1. What works (verified)

The close is a reversible set of ordinary posted journals, one per branch with profit-and-loss activity, dated on the last day of the year and tagged `source_type = fiscal_year`. On reopen those exact entries are reversed; nothing is edited or deleted.

Covered by 14 passing tests in `backend/tests/Feature/FiscalCloseTest.php`:

| Behaviour | Test |
|---|---|
| Profit closes to retained earnings (3100), balanced entry | `test_a_profitable_year_closes_into_retained_earnings_with_a_balanced_entry` |
| Loss debits retained earnings | `test_a_loss_year_debits_retained_earnings` |
| Income statement still reports the closed year; balance sheet balances; trial balance pre/post closing | `test_reports_still_show_a_closed_years_profit_and_the_balance_sheet_balances` |
| One closing entry per branch | `test_each_branch_gets_its_own_closing_entry` |
| Closing requires access to every branch with activity | `test_closing_needs_access_to_every_branch_with_activity` |
| Draft entries block the close until posted | `test_pre_close_checks_block_the_close` |
| Submitted expenses and a closed final period block the close | `test_submitted_expenses_and_a_closed_final_period_block_the_close` |
| A closed year refuses postings and period-status changes | `test_a_closed_year_rejects_postings_and_period_status_changes` |
| Reopen reverses closings, keeps `close_count`, and a second close is round 2 | `test_reopening_reverses_the_closing_entries_and_the_year_can_be_closed_again` |
| A year with no activity closes with no entries | `test_a_year_without_activity_closes_without_entries` |
| A period with drafts cannot be closed; soft close is allowed | `test_closing_a_single_period_is_blocked_while_it_has_drafts` |
| Close/reopen need `periods.close` / `periods.reopen` | `test_close_and_reopen_require_their_own_permissions` |

## 2. Fixed in this review

### 2.1 The checklist could pass and the close still fail on a missing period (verified)

`close()` dates the closing entries on `fiscal_year.ends_on`. `checks()` only verified that the *last existing* period was not closed. On an installation whose periods end before 31 December (for example monthly periods created only up to November), `can_close` returned `true`, and the close then failed inside `PostingService` with `No fiscal period covers 2026-12-31`.

Added a `year_end_period` check that only applies when the year has posted revenue/expense activity, so a clean year without activity still closes as before. Covered by:

- `test_closing_needs_a_period_covering_the_year_end_when_the_year_has_activity`
- `test_a_year_without_activity_still_closes_without_a_period_covering_the_year_end`

## 3. Findings that need an accountant decision

### 3.1 Revaluation must run before the close, and the checklist does not say so (from code)

`RevaluationService::revalue()` posts into the period; once the year is closed that period is `closed` and the posting is refused. The year-end checklist has no line for unrealized FX, so an accountant can close a year that still has unrevalued foreign-currency balances and lose the ability to recognise them in that year. The reverse order (revalue, then close) is the only one that works today.

Recommendation: record the closing order in the policy (revalue → reconcile → close) and decide whether an unposted revaluation must **block** the close or only warn. Deferred to DEC-008 / DEC-012.

### 3.2 Which open items must block a close

The checklist currently blocks on drafts, submitted expenses, an unbalanced year, a missing retained-earnings account, a closed final period, and now a missing year-end period. It does **not** look at:

- unreconciled bank statements,
- open supplier obligations or advances,
- approved-but-unissued documents,
- requested (unapproved) refunds,
- schedules/accruals whose next event falls in the next year.

None of these affects the posted ledger, so none is treated as a ledger error. Whether any of them must block a close is a policy choice. Deferred to DEC-012 / DEC-034.

### 3.3 Reopen resets every period of the year (from code)

`reopen()` sets **all** periods of the year to `open`, including months that were soft- or hard-closed earlier for a month-end review. The account balances are restored correctly, but the period-level review state is lost and must be re-applied manually. There is no warning listing which periods changed. This is documented behaviour, not a defect, but it should be part of the written close/reopen procedure (DEC-034).

### 3.4 Soft close at year end needs an extra permission

The close accepts a year-end period in `soft_closed` state (`status !== 'closed'`), but `PostingService::resolveOpenPeriod()` only posts into a soft-closed period when the actor holds `periods.post_soft_closed`. An accountant without that permission sees the checklist pass and then gets a posting error. Recommendation: either require the year-end period to be `open`, or state that closing a soft-closed year needs `periods.post_soft_closed`. Left as is pending DEC-017 (segregation/authority).

## 4. Smaller observations (from code)

- Draft entries and submitted expenses are counted **globally** for the year, not per branch. An accountant closing a year can be blocked by a draft in a branch they cannot access, and the message does not name the branch. Low risk; a branch hint in the message would help.
- Reopening is audit-logged with the reason and the number of reversed entries, but the closing entries themselves carry no reference to the reason; the reversal entries do. Acceptable.
- The checklist is read-only; there is no preview of the closing entries before they post. The close is atomic and reversible, so this is a convenience gap rather than a correctness one.

## 5. Not verified

- **Running demo stack.** Docker was unavailable during this review, so the year-end close was not exercised through the UI/API on PostgreSQL with the seeded demo data. The tests run on SQLite in CI, which is also the case for the existing suite.
- **Post-close reporting on the demo data.** Covered by test fixtures only.
- **Multi-year sequences around a reopen.** Covered at the service level (`close_count`), not with real 12-month data.

To close this gap, on a machine with Docker:

1. `docker compose up -d` and reset the demo database.
2. Post activity across a full year, run `GET /api/v1/fiscal-years/{id}/close-checks`, then close and reopen from the UI.
3. Confirm the balance sheet balances after each step and that the income statement for the closed year is unchanged.

## 6. Evidence

- `php artisan test --compact tests/Feature/FiscalCloseTest.php` → 14 passed (133 assertions).
- `php artisan test --compact tests/Feature/UsdLifecycleTest.php` → 1 passed (50 assertions); the USD lifecycle asserts every posted entry in the chain balances and is traceable to its source document.
