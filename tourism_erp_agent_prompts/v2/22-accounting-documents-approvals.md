# 22 — Accounting Documents, Vouchers & Approval Workflow
**Agent:** Financial Documents / Workflow Agent

## Goal
Make traceable financial source documents for receipt, payment, internal cash/bank transfer, manual adjustment and refund, posting to EXISTING journal service only when approved per the configured policy.

## Required scope
- Document types: receipt voucher, payment voucher, transfer voucher, adjustment voucher, refund voucher; link to customer, supplier, employee, booking and supporting files when relevant.
- Consistent state machine Draft -> Submitted -> Approved -> Posted, with Rejected / Cancelled where valid; role-restricted transitions, maker-checker option, timestamps, actor and comments.
- Separate document identifier and journal reference; configurable number series by document type and branch, concurrency-safe uniqueness, no number reuse after posting.
- All postings happen through existing posting API/service, are atomic and idempotent; avoid double postings on retries, queue replay or concurrent approval.
- Define line-level amounts, cash/bank accounts, currencies and snapshots, allocation references, cost centers if present. Model intra-company transfers as a single balanced transaction with appropriate clearing when required.
- Pre/post posting correction rules: draft editable; posted document immutable except audit metadata; corrections via linked reversal/correction document with explicit reasons.
- UI for lists/details/create/approve/print/PDF, filters, audit timeline and drill-down from voucher to GL entry.

## Accounting decisions requiring approval
Who approves each amount/type? Which transfers need an in-transit account? What is the tax treatment and mandatory documentation? Whether canceled approved/unposted documents can be reopened.

## Acceptance tests
- Same voucher approved twice/concurrently creates exactly one posted journal; no partial posting on failure.
- Transfer between two cash/bank accounts keeps total balance invariant (apart from approved fees/FX).
- Unauthorized maker cannot approve when maker-checker enabled; closed periods reject posting; reversal retains original trail.
- Branch-specific numbering is unique; rendered PDF matches saved posted document values.

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
