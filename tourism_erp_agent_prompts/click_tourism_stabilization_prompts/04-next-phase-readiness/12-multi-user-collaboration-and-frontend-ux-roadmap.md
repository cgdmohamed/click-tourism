# Multi User Collaboration and Frontend UX Roadmap

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P2 preparation only  
**Depends on:** 01, 05 and existing RBAC

## Mission
Prepare multi-user operation and UI/UX improvements without conflating multi-user with SaaS multi-tenancy.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Audit current user, employee, role, branch access, session/token, login, UI responsive behavior, RTL/LTR and design tokens.
2. Produce gap inventory for user invitations/provisioning, per-role permissions, branch switching, disabled user and token revocation, concurrent edits, activity timeline, notifications, comments/mentions and approval inbox.
3. Design presence and optimistic-concurrency behavior for simultaneous invoice/booking updates; no silent lost update or duplicated financial action.
4. Conduct UI flow audit for accountant, booking agent, manager, sales and branch manager: information hierarchy, multi-step forms, tables, filters, empty/error/loading states, keyboard access, mobile responsiveness.
5. Specify incremental UI changes using `docs/design/DESIGN.md` tokens; do not redesign without justification or require frontend SPA rewrite.
6. Prioritize epics with acceptance tests and wireframes/task breakdown. Clarify that each install is single legal entity with potentially many users and branches.

## Acceptance tests and validation
- Roadmap separates foundation fixes, multi-user workflows and UX enhancements.
- Defines concurrency and role tests with branch-level isolation.
- No SaaS architecture or unrequested feature expansion.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
