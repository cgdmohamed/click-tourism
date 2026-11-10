# MCP Tooling and AI Assistant Security Blueprint

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P2 preparation only  
**Depends on:** 01–07 stable + accounting sign-off roadmap

## Mission
Prepare a concrete design for a secure MCP layer and AI assistant; do not implement arbitrary write tools yet.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Audit supported domain services, API scopes, auditable events, authorization, data classification and self-hosted deployment boundaries.
2. Produce tool catalog organized by risk: read-only search (customers, bookings, invoices, supplier statements), low-risk drafts, approval-dependent writes, prohibited actions. Define JSON schemas, errors and paging.
3. Define MCP authentication per user, branch scoping, RBAC, tool allowlist, consent/confirmation for writes, human approval for financial actions, idempotency and complete audit logs.
4. Define model provider abstraction (cloud or private), prompt-injection boundaries, PII redaction, tenant-independent installation secrets, rate/cost limits, sensitive tool result policies and no model-generated SQL.
5. Outline architecture decision record and a phased proof of concept: READ-ONLY MCP with 3 tools and 10 adversarial security tests; no direct GL write and no bypass of approval states.
6. For AI assistant, define Arabic/English conversations, citations to internal records, source freshness, clear differentiation between explanation and authorized action.

## Acceptance tests and validation
- Blueprint has exact tool schemas, scopes, threat cases and staged acceptance criteria.
- All proposed write tools are blocked pending explicit product approval and accounting governance.
- No actual AI/API provider keys embedded in repo.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
