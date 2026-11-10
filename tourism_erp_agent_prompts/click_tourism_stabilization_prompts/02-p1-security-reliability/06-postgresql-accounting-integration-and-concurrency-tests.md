# PostgreSQL Accounting Integration and Concurrency Tests

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 01–05 for stable contract

## Mission
Make production database behavior a tested release gate, not only SQLite.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Add ephemeral PostgreSQL 16 service to CI and run a representative feature/integration suite or full suite with `DB_CONNECTION=pgsql`; retain SQLite for fast tests.
2. Build end-to-end financial scenario test: new legal entity install, account tree + subsidiary customer/supplier/employee, booking, invoice, partial allocation, supplier obligation, refund, FX, fiscal close, cost center, trial balance and statements.
3. Test transaction/row locks, idempotency, atomic document numbering, concurrent lead reassignment, duplicate webhooks, concurrent voucher approvals and safe restarts.
4. Add invariant checks: journals balanced in original/base currencies, no duplicate economic recognition, closed-period immutability, no orphan allocations, multi-branch scoping.
5. Use seed fixtures and reproducible test commands; do not insert production secrets or real customer data into CI.
6. Capture failing cases as regression tests, then fix minimal root causes rather than adjusting expected outputs to match bugs.

## Acceptance tests and validation
- PostgreSQL tests run in CI with reported counts and zero failures.
- Concurrent posting/retry and lock contention are demonstrated in tests.
- Same test dataset yields matching Trial Balance, GL and party statements.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
