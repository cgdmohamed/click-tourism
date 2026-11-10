# Close AR AP Payment Allocation and Subledger Gaps

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P0  
**Depends on:** 03 (unified recognition path)

## Mission
Reconcile operational invoice balances, party subaccounts, supplier obligations and GL control balances, including partial payments and advances.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Inspect invoice payment allocation schema, payment/refund controllers, `PartyProfileService`, `PartyStatementService`, `AgingReconciliationService`, ledger posting and existing reconciliation tests.
2. Map invoice lifecycle: issue, receipt, partially applied receipt, customer advance, settlement, overpayment, credit note, cancellation; corresponding supplier AP/advance flows.
3. Implement explicit allocation/unallocation workflows, immutable audit events, validation of remaining balances, transaction locks and idempotency. Ensure payment on account does not masquerade as invoice settlement.
4. Guarantee GL customer/supplier/employee subsidiary ledgers and control accounts can be reconciled, while preserving correct distinctions between unapplied cash and outstanding invoices.
5. Provide user-facing discrepancy explanation and safe resolution workflow; do not edit posted journals in place. Keep customer/supplier profile statement and aging aligned.
6. Ensure separate branch and currency accounting; document FX adjustment policy gaps.

## Acceptance tests and validation
- Invoice 1000 + receipt 400 allocated → invoice outstanding 600; balances reconcile per designed liability/AR handling.
- Receipt 400 unapplied → explain advance/unapplied balance, do not falsely mark invoice paid.
- Over-allocation, replay, double application, wrong party/branch, reversal, closed period are rejected or safely processed.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
