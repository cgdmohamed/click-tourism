# 21 — Employee Accounts, Advances & Custody
**Agent:** Employee Finance Domain Agent

## Goal
Create an Employee Finance subsystem distinct from login users. Employees can be linked to system users but must not require login accounts. Integrate expenses, payroll liabilities (where configured), custody, advances and commissions with the one existing ledger.

## Required scope
- Employee master (internal code, branch, active state, role/category, optional linked user ID); preserve privacy and limit HR access. No sensitive fields unless required and authorized.
- Employee financial subledger (opening balances, transaction references, account mapping, statement, outstanding balances, period filter). Reconcile to designated employee control accounts in the GL.
- Advance lifecycle: request -> approve -> disburse -> settlement via expense receipts / cash return / allowed deductions; distinguish advance liability vs employee receivable per approved chart mapping.
- Custody / petty cash lifecycle: issuance -> receipts / expense clearance -> cash return -> closure; track custodian and outstanding items separately from salary advances.
- Commission accrual/payment linkage to existing commissions module; prevent duplicate commissions or postings.
- Attachment references and auditable approvals; prohibit exceeding available balance per defined policy and unauthorized write-offs.
- Frontend screens for employee profiles, account statements, advance/custody request, approval, settlement and reconciliation.

## Accounting decisions requiring approval
Control account hierarchy; advance recognition and netting rules; custody vs advance definitions; permission to deduct from payroll; employee vs independent agent classification. **Do not implement a full payroll engine in this prompt.**

## Acceptance tests
- Disburse 1,000 advance, settle 600 supported expense and return 400: outstanding = zero and GL matches.
- Two employees share one GL control account but statements reconcile separately.
- Partial settlement, reversal, cancellation, leave/inactive employee with open balance, and duplicate request protection.
- Employee record cannot be deleted when it has ledger activity; user access is not required for an employee.

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
