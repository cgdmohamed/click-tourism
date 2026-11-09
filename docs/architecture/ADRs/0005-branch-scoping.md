# ADR-0005 — Branch scoping and branch accounting

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + accountant (DEC-014)

## Context

The proposal requires 2–3 branches and allows a branch to behave as an independent account or as a customer, depending on company style. Reports and permissions must never leak across branches.

## Decision

1. `branch_id` is mandatory on operational and financial rows (bookings, payments, journals, expenses, leads, sequences).
2. Users are linked to branches via `user_branch`. A user with no branch assignment sees nothing except explicitly global data.
3. Authorization uses a `BranchScope` service: `allowedBranchIds(user)`; every query applies it server-side, including exports and reports.
4. Cross-branch permission (`branches.view_all`) is explicit and audited.
5. **Accounting model (MVP):** one ledger with branch as a posting dimension (Option A, DEC-014). Inter-branch effects use configured `due_to_branch`/`due_from_branch` accounts when enabled.
6. **Branch as customer/account:** configurable per branch via `branches.accounting_mode` (`dimension`, `customer`, `independent`). The setting changes how branch reports and inter-branch transactions are presented; it does not change the schema.
7. Branch switching in the UI sets an active branch, but the API never trusts it for authorization — it re-validates against the user's assignments.

## Consequences

- Simple MVP ledger; branch P&L available as a dimension.
- "Independent branch" full separate books remain a later extension; the setting prepares the UI/reporting contract.
