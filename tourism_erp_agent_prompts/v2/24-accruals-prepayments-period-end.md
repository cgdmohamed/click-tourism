# 24 — Accruals, Prepayments & Period-End Adjustments
**Agent:** Period-End Accounting Agent

## Goal
Provide configurable accruals, prepaid/deferred items and scheduled reversals/recognition while preserving the existing journal, fiscal periods, FX snapshots and approval workflow.

## Required scope
- Accrued expenses and revenues, prepaid expenses, deferred revenues, period-end adjustment documents and reversal schedules.
- Allocation/recognition schedules with start/end, periodicity, total, allocated amount, recognized balance, exact rounding residual handling and posting references.
- Use distinct draft vs posted schedule events; only post through the existing ledger and approvals. Scheduler must be retry-safe and pause when period closed, with exception queue and manual retry.
- Support cancellation/reclassification via approved adjustments and reversals; preserve immutable historical schedule and GL linkage.
- Period-end checklist: unposted documents, unresolved reconciliations, draft schedules, negative/abnormal balances, trial balance tie-out.
- UI for schedule creation, review, calendar, upcoming postings, exceptions, and period-end checklist.

## Accounting decisions requiring approval
Recognition policies for deposits received from tourists, booking deposits paid to suppliers, trip fulfillment vs booking date, accounting basis, tax timing and revaluation of open foreign currency accruals. Do not hardcode a revenue-recognition method.

## Acceptance tests
- Prepayment of 1,200 over 12 periods recognizes total 1,200 exactly, including rounding residual.
- Re-running job creates zero duplicate journals; closed period does not post.
- Correct reversal of accrual in following period without modifying prior journal; schedule and GL reconcile.
- Refund / booking cancellation after partial recognition follows approved adjustment rules.

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
