# 20 — Cost Centers & Analytical Accounting
**Agent:** Cost Accounting Domain Agent

## Goal
Introduce reusable cost centers and analytical dimensions for the Tourism ERP WITHOUT rewriting the existing chart of accounts or double-entry ledger. Cost center is not a GL account; it is an analytical dimension on posted journal lines or allocated portions of them.

## Required scope
- Configurable hierarchical cost center tree (code, name AR/EN, parent, status, optional effective dates) with safe archival and prohibition of cycles.
- Optional default center from branch, booking/service, employee or expense category with documented precedence; manual override subject to permission. Never silently infer allocation.
- Support splitting a journal line among several centers using exact amounts or percentages; enforce that allocations equal 100% / exact parent line amount at the currency's allowed precision. Handle rounding deterministically.
- Tag expense/revenue lines (and optional additional account types per approved policy); keep debits/credits unchanged. Cost centers must not independently create ledger entries.
- APIs + UI for center tree, allocation editor in journal/document workflows, search/filter; reports: cost-center P&L, expenses by center, branch-center comparison, unallocated entries. Reconcile each report total to GL balances for selected period and consistent inclusion policy.
- Maintain historical attribution when centers are renamed or deactivated; never delete centers with postings. Backfill historical records only via opt-in, auditable migration.

## Accounting decisions requiring approval
Should balance-sheet accounts use analytical dimensions? Are allocations mandatory on specific account codes? How are inter-branch and shared costs allocated? How are canceled bookings reclassified?

## Acceptance tests
- 60/40 split of a 100.00 expense maps to 60.00/40.00 without changing posted journal totals.
- Hierarchy aggregates without double-counting; optional unallocated bucket is visible.
- Sum of allocated cost center reports agrees with eligible ledger lines; cannot allocate 110%, negative prohibited by policy, inactive center rejects new transactions.
- Authorized users in branch A cannot see/write restricted branch B records; period closing prevents retroactive alteration.

## Mandatory operating rules
- You are extending an ALREADY IN-PROGRESS repository. Inspect the actual code, tests, database migrations, APIs and `AGENTS.md` before editing. Do not assume prior prompts were completed successfully.
- Product is a reusable **self-hosted Tourism ERP**: one codebase, separate server and PostgreSQL database per customer. No SaaS, tenant_id, multi-tenancy, or customer-specific branches.
- Stack: Laravel API, React/TypeScript/Vite, PostgreSQL, Redis. Respect repo's actual versions and existing Design System (tokens only, NOT complete screens).
- Preserve existing domain behavior and public APIs. Define compatibility plan and migrations for existing records; never drop columns, rewrite posted journals or replace previous modules without review.
- Integrate with the EXISTING accounting posting service and chart of accounts; never implement a second ledger, compute balances from UI values, use floats for money, or edit/delete posted entries. Use PostgreSQL transactions, DECIMAL/NUMERIC, deterministic idempotency keys and audit records.
- Financial policy ambiguities are **DECISIONS REQUIRED** for the owner/accountant, not rules for the agent to invent. Implement configurable policies only after approval and document defaults.
- Respect branch-level authorization, fiscal period closure, FX rate snapshots, RTL/LTR UI, access controls and permission tests. No production writes, real payments or credential changes.
- Work in a separate issue/branch/worktree and open a PR; do not commit to main, force push or perform broad refactors.

## Execution protocol
1. **Audit first:** report EXISTING / PARTIAL / MISSING functionality with code paths, migrations and tests. Identify dependencies and any schema/API conflicts.
2. **Contract first:** write a concise proposal (tables/columns, relations, policies, endpoints, UI screens, posting examples, migration compatibility). Surface accounting decisions needing sign-off.
3. Implement a small vertical slice with explicit tests, not a complete rewrite. Add a migration/backfill plan for pre-existing installations.
4. Run unit, feature/API, authorization, and at least one end-to-end scenario appropriate to the change; test with 2 branches and both currencies where relevant.
5. Report changed files, commands/test results, sample API requests, accounting examples, unresolved risks, and next handoff. Mark anything untested **UNVERIFIED**.

**Definition of done:** functionality integrated with current core + documented business rules + passing tests + backward-compatible migration + no general ledger imbalance + independent review of accounting treatment. Never claim accountant approval unless explicitly obtained.
