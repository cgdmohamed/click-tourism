# 25 — Fixed Assets & Depreciation
**Agent:** Fixed Asset Accounting Agent

## Goal
Implement a basic fixed-asset register and depreciation subledger compatible with the existing accounting core; all GL effects must go through the current posting services.

## Required scope
- Asset register: code, description, branch/location, category, purchase date, capitalization date, supplier, acquisition value/currency, residual value, useful life, status, account mappings, attachments.
- Asset categories configured to asset, accumulated depreciation, depreciation expense and disposal gain/loss accounts; optional cost center / employee custody link.
- Start with configurable straight-line depreciation; do not silently implement or promise other methods. Schedule generation with month-end prorating policy explicitly approved, precision rules and final-period correction.
- Events: acquisition/capitalization, depreciation runs, transfers (branch/cost center without rewriting GL), impairment/manual adjustment only via explicit approved document, disposal/sale/retirement with linked posting.
- Asset subledger and reports: roll-forward, depreciation schedule, current carrying value, additions/disposals by period/branch/cost center; reconcile control accounts to GL.
- UI for register, asset detail, run preview, approvals, posting status and audit trail.

## Accounting decisions requiring approval
Capitalization thresholds, useful life, residual value, in-service date, depreciation convention, handling of FX, taxes, impairment and gains/losses at disposal. Do not invent statutory compliance rules.

## Acceptance tests
- Asset cost 12,000, residual 0, useful life 12 months: total depreciation = 12,000, no negative carrying value.
- Duplicate/concurrent depreciation runs cannot duplicate postings; closed period refuses posting.
- Disposal halfway through schedule removes asset and accumulated depreciation and recognizes approved difference.
- Transfer across branches preserves global totals and audit trail; GL control balances reconcile to subledger.

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
