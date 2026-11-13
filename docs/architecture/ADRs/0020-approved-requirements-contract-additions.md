# ADR-0020 - Contract additions for the approved requirements of 2026-11-13 (PR 1)

- **Status:** **Accepted** (project owner decision 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). The business requirements were approved the same day; the rows below were approved after this ADR was presented.
- **Date:** 2026-11-13
- **Related:** `docs/product/approved-requirements-2026-11-13.md`, ADR-0015 (party subledgers), ADR-0018, `docs/architecture/openapi.yaml`.

## Decision

All additive; no existing response field changes meaning and no existing route changes.

1. **Schema:** `invoice_lines.booking_item_id` (nullable FK to `booking_items`, null on delete) - a supplier invoice line may name the booking service it pays for.
2. **Routes:** `GET|POST /fiscal-periods/{period}/revaluation` (review and confirm FX revaluation; `periods.view` / `periods.close`), `GET|POST /fiscal-periods/{period}/cost-accrual` (same permissions).
3. **Response additions:** `GET /bookings/{id}` adds `cost_summary`; `GET /commissions/beneficiaries`, `GET /commissions/statement` and `GET /reports/commissions` add `expected_minor` and `adjusted_minor` (reports also `total_expected_minor`, `total_adjusted_minor`); invoice line payloads add `booking_item_id`; feature settings add `accounting.accrued_costs_account_code` (default 2170).
4. **Posting change (GAP-RPT-05):** cost lines of supplier invoices, booking-cost postings and accruals now carry the supplier as the line party. Entries already posted are not rewritten.
5. **Behaviour:** a supplier payment from a cash or bank account registered to another branch is refused (DEC-014).
6. **New command/schedule:** `accounting:reverse-cost-accruals`, daily.

## Consequences

Rollback: the migration is reversible; the new routes can be removed without data loss; accrual entries, if any were posted, are ordinary reversible journal entries.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Schema: `invoice_lines.booking_item_id` | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0020 (PR #77) |
| 2 | Routes: revaluation and cost-accrual review/confirm | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0020 (PR #77) |
| 3 | Response additions (items 3) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0020 (PR #77) |
| 4 | Supplier party on cost lines (item 4) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0020 (PR #77) |
