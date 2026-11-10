# Authorization SoD and Financial Threat Model

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 02–04 accounting models stabilized

## Mission
Make financial authorization robust across HTTP controllers, jobs and service calls; review segregation of duties.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Build permissions matrix for posting, reversal, refunds, invoices, expenses, employees, bank transfers, report export, approval and branch scopes.
2. Audit service-level actor authorization, including `PostingService::assertActorMayPost` (currently observed branch check only), background jobs and `actorId=null` semantics; assess trust boundary and service account identity.
3. Implement centralized domain authorization/policy checks and separation of duties (maker ≠ checker where required). Do not break existing routes or legitimate machine jobs.
4. Add explicit authorization for system jobs with limited machine identity, audited provenance and scoped posting capability; prevent unauthenticated internal invocation.
5. Check direct object references, branch switching, API validation, sensitive audit logs, invoice / report exports and least privilege. Update `threat-model.md`.
6. Document any 2FA/session lifecycle requirements but defer full new auth features to a separate task.

## Acceptance tests and validation
- A staff member cannot approve own protected refund/expense, bypass controller via service, or cross branch.
- Allowed supervisor/system-job operations succeed with auditable identity.
- No sensitive PII/tokens in logs and error responses.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
