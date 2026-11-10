# Cost Center Precision and Report Reconciliation

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 04 and 06

## Mission
Remove rounding ambiguity in cost-center reporting and prove report reconciliation invariants.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Review `ReportService::trialBalanceAmounts`, ratios from `cost_center_allocations`, any use of FLOAT, `ROUND`, sum-casts, integer minor units and currency conversions.
2. Prefer integer/decimal-safe allocation distribution with deterministic residual strategy and `SUM` on appropriate PostgreSQL numeric/bigint types; protect from overflow. Confirm existing database semantics.
3. Ensure cost-center filtering does not incorrectly claim a fully balanced trial balance when only one side of transactions is allocated; label such an analytical report honestly and supply proper totals.
4. Add per-branch, per-currency, shared allocation, reversal and cross-period checks. Audit report export for exact numeric values and CSV formula injection.
5. Document numerical invariant, examples and migration implications; avoid changing historical allocation amounts without explicit correction workflow.

## Acceptance tests and validation
- Distribute 100 minor units 33.33/33.33/33.34 and sum exactly 100.
- Large amounts and several cost centers produce deterministic reproducible totals on PostgreSQL.
- Standard TB and drill-down totals reconcile; analytic views transparently indicate partial allocations.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
