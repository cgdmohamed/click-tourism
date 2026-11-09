# 23 — Bank Reconciliation
**Agent:** Treasury / Bank Reconciliation Agent

## Goal
Match external bank statements to existing bank-ledger postings. Reconciliation must not modify historic ledger entries or implicitly create unapproved journals.

## Required scope
- Configurable bank accounts linked to chart-of-accounts accounts and currency. Import bank statement CSV/XLSX with bank-specific mapping; record line hash/reference and import job for deduplication.
- Reconciliation sessions per account/statement period with opening/closing bank balances; bank lines, unmatched items, book ledger lines and explicit match/unmatch actions.
- Matching by amount/currency/date/reference; configurable tolerance and window, human review on ambiguous matches; support one-to-one and split/group matching without double match.
- Difference explanation: outstanding checks/transfers, deposits in transit, bank fees, interest and FX; generate proposed adjustment vouchers routed through existing approval/posting service only after user action.
- Lock approved reconciliation snapshot or provide controlled reversal/reopen with full audit trail; do not change periods already closed.
- UI for upload, mapping preview, match candidates, unmatched lists, approved differences and PDF reconciliation report.

## Accounting decisions requiring approval
Supported import formats by bank; currency conversion treatment; tolerance for auto-match; posting bank charges and interest; whether partial/many-to-many matches are allowed in v1.

## Acceptance tests
- Importing same statement twice creates no duplicates.
- Matching does not post journals; adjustment document posts only after authorized approval.
- Book balance + approved reconciliation differences explains bank statement closing balance, with outstanding items shown separately.
- Incorrect currency, ambiguous match, duplicate ledger match, reversal and cross-branch access all covered.

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
