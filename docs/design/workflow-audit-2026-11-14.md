# Workflow audit - 2026-11-14

Scope: the end-to-end jobs people do in the ERP, compared with the documented workflows (`docs/product/workflows.md`, 12 flows) and the role matrix (`docs/product/permission-matrix.md`). The UI audit (`ui-ux-audit-2026-11-14.md`) looks at screens; this one looks at the **hand-offs between screens and between people**.

Method and what was actually executed:
- **Read**: every route (`web/src/App.tsx`), the actions on each detail page, the notification producers in the backend, the permission matrix.
- **Executed (read-only, scratch database)**: logged in as the demo roles `sales`, `viewer` and `accountant` and called the list endpoints of the core flows; screenshots of the screens involved.
- **Not executed**: write flows as every role (the scratch database is shared with the redesign work), and the `cashier` role (not seeded). Where a finding rests on reading code and the matrix, it says so.
- Step counts are clicks from the page the person is on, counted from the UI code.

Legend: **P1** a job cannot be completed or a person is not told to act; **P2** extra steps or guessing; **P3** polish. "UI-only" = fixable with existing APIs; "needs approval" = needs a backend change (new event, endpoint or permission) and so the owner's decision.

## What already works well
- Booking -> invoice -> collection is one connected path for accounting roles: open the booking, **Confirm**, **Generate invoice** (opens the invoice), **Settle**, fill the dialog: **5 clicks** after the booking exists.
- Customer profile has "New booking" (`/bookings?customer_id=...&create=1`); global search (Ctrl K); credit-limit warnings on confirmation; idempotency on money-moving calls; refunds are maker-checker.
- The documented lifecycles (booking, expense, invoice, refund, voucher, period) all exist in the UI with the guards of the documents.

## Findings (prioritized)

### P1
| # | Workflow | Finding and evidence | Fix | Type |
|---|---|---|---|---|
| W1 | Approvals (expenses, vouchers, invoices, refunds, commissions, schedules, reconciliation) | **There is no "waiting for me" place and approvers are not notified.** Approvers must open each list and filter by status. Notifications exist only for `invoice.approved`, `expense.approved|rejected` (to the requester, `ExpenseService::notifyRequester`) and lead events. Nothing tells an approver that something was submitted. | An **Approvals** page (and a sidebar count) that lists what the signed-in user may approve, built from the lists that already exist (`status=submitted|requested`, filtered by permission); later a "submitted" notification. | page: UI-only; notification: needs approval |
| W2 | Booking -> invoice for sales roles | `sales`, `reservations`, `supervisor` can create and confirm bookings but **cannot list invoices** (verified: `GET /invoices` = 403 for `sales`). The booking page hides the invoice panel for them and nothing tells accounting that a booking is ready to invoice, so the hand-off happens outside the system. | A "Ready to invoice" worklist for accounting (confirmed or completed bookings without an invoice) and a read-only invoice status on the booking for its owner. | worklist: UI-only if the bookings list carries the linked invoice; status for sales: needs approval (permission or payload) |
| W3 | Customer collection by the cashier | The collection dialog loads open invoices from `GET /invoices`, which needs `invoices.view`; the matrix gives `payments.create` to the cashier but not `invoices.view`. From the code, the invoice dropdown cannot load for a cashier, so a cashier can allocate to bookings only. (Read from code and matrix; not executed as a cashier.) | Give the cashier a collection-scoped way to find open customer invoices (a limited endpoint or a narrow permission). | needs approval |

### P2
| # | Workflow | Finding and evidence | Fix | Type |
|---|---|---|---|---|
| W4 | Lead -> customer -> booking | After **Convert to customer** the lead page offers no next step; "New booking" exists only on the customer page. Today: convert, open the customer, New booking = 3 navigations. | After conversion show the primary action "Create a booking for this customer" (the `create=1` deep link already exists). | UI-only |
| W5 | Supplier cost (booking item -> supplier invoice -> obligation -> payment) | The supplier profile links to the unfiltered lists `/invoices` and `/supplier-settlements`; there is no "Record supplier invoice" or "Pay" with the supplier pre-filled, and no way to start a supplier invoice from a booking's cost lines. | Pre-filled entry points on the supplier profile and the booking (query parameters the pages read). | UI-only |
| W6 | "View invoices" from a customer or supplier profile | Goes to the whole list, not that party's invoices (customer profile lines 212/254, supplier profile line 192). | Link with the party filter applied (the new filter drawer takes it). | UI-only |
| W7 | Month-end and year-end close | The journey is split over five places: the periods table (status is a select per month), the cost-accrual dialog, the revaluation dialog, the year checklist/close, then Reports. There is no ordered month checklist with the state of each step. | A month-end stepper on the Periods page (accruals -> revaluation if enabled -> checks -> soft close -> close) using the endpoints that exist. | UI-only |
| W8 | First run | A new installation lands on a dashboard of zeros; nothing tells the administrator what is left to set up (company, branches, chart of accounts, tax, currencies, users, opening balances). | A setup checklist card for administrators, derived from existing settings and counts, hidden when complete. | UI-only |
| W9 | Expense and voucher approval | Submit/approve/reject exist only as buttons on list rows; the new detail pages (`/expenses/:id`, `/documents/:id`) are read-only, so an approver reading a record must go back to the list to act. | Put the same actions on the detail pages (same endpoints and permissions) and a "next waiting" link. | UI-only |
| W10 | Notifications coverage | Beyond W1: no notification when a refund is requested, a scheduled report fails (visible only on its card), a background export finishes, or a period is about to need closing. | Add events as the owner prioritizes them. | needs approval |

### P3
| # | Workflow | Finding | Fix | Type |
|---|---|---|---|---|
| W11 | Dashboard quick actions | They open a list page, not the action ("Record collection" opens Payments; then another click on New). | Deep-link `?new=1` so the dialog opens (Bookings already supports `create=1`). | UI-only |
| W12 | Visibility | `reports.view` is granted to every role in the matrix; verified that `sales` can read the trial balance through the API. Documented design, but worth an explicit owner decision. | Owner to confirm intended exposure; if not, restrict `reports.view` per role. | needs approval |

## Suggested order
1. W1 Approvals page (UI-only part) - the biggest day-to-day gain.
2. W4, W6, W11 (small, UI-only), then W9.
3. W5, W7, W8.
4. Decisions for the owner: W2, W3, W10, W12 (each needs a backend change).

Not changed here: no code was modified for this audit.


## Status after implementation (2026-11-14)

| # | Status |
|---|---|
| W1 | Done: `/approvals` page and sidebar count (refund approval stays on Payments because it needs an FX decision). |
| W4 | Done: "Create a booking for this customer" after conversion. |
| W5 | Done: "Record supplier invoice" and "Pay supplier" on the supplier page, pre-filled. Starting a supplier invoice from a booking's cost lines is **not** done (needs a design decision on how cost lines map to invoice lines). |
| W6 | Done: profile "View all" links apply the party filter (`?party_type=&party_id=`, `?supplier_id=`). |
| W7 | Done: month-end guide on the Periods page for the oldest open period. The accrual and revaluation steps cannot show a done state because the API does not report whether they ran. |
| W8 | Done: setup checklist for administrators on the dashboard, derived from existing data, hidden when complete. |
| W9 | Done: submit / approve / reject and a "next waiting" link on the expense and voucher pages. |
| W11 | Done: dashboard shortcuts open the dialog (`?new=`). |
| W2, W3, W10, W12 | Still need owner decisions (backend changes). |

Measured with `web/e2e/workflow-timing.spec.ts` (actions, screens and elapsed time on one machine, local API):

- Receipt for a customer, from the customer page to the receipt saved: 8 actions / 2.4 s via Payments, 5 actions / 1.4 s via the shortcut.
- Supplier invoice, from the supplier page to the form open with the supplier chosen: 5 actions via Invoices, 1 via the shortcut; elapsed time is about the same because page load dominates. The journey was not measured through to saving.
- Reaching a waiting expense: 4 actions via Expenses > Filter, 0 via Approvals.


## Owner decisions applied (2026-11-14)

| # | Decision | What was built |
|---|---|---|
| W2 | A "ready to invoice" worklist and a read-only invoice status on the booking | `GET /bookings?needs_invoice=1` (confirmed, in progress or completed bookings with no live customer invoice); every booking row and detail carries `invoice` (`number`, `status`, `currency`, `total_minor`, `outstanding_minor`) readable with `bookings.view`. The Approvals page lists "Ready to invoice" for `invoices.create` holders with a Create invoice action that opens the new draft. The Bookings filter drawer has "Ready to invoice". No role gained `invoices.view`. |
| W3 | A limited endpoint for collection | `GET /collections/open-invoices` (needs `payments.create` only): issued customer invoices that still owe, for one customer in the active branch. The receipt and apply dialogs use it for customers; supplier invoices keep the invoice list. |
| W10 | Notify approvers of a new refund request; notify the owner of a failed scheduled report | `refund.requested` goes to every active user of the branch holding `refunds.approve` (not the requester); `report_schedule.failed` goes to the schedule's owner on each failed run, saying when the schedule was switched off. Both are best-effort and never block the operation. Export-finished and period-closing reminders were not requested and are not built. |
| W12 | Restrict `reports.view` to the financial roles | Withdrawn from `sales`, `reservations` and `cashier`; kept for `admin`, `finance_manager`, `senior_accountant`, `accountant`, `viewer` and `supervisor` (the supervisor already holds `reports.export`; remove the line in `RolePermissionSeeder` if it should go too). System roles are re-synced by `roles:sync` on upgrade, so no migration is needed. The Reports menu item is hidden without the permission. |
