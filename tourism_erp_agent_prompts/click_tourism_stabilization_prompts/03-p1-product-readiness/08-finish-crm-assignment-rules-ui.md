# Finish CRM Assignment Rules UI

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 01 and existing CRM API

## Mission
Complete administrator-facing configuration of lead routing without changing existing business rules silently.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Inspect `LeadService`, `AssignmentRule`, migrations, settings, route permissions, UI `Leads.tsx`, RTL/LTR design tokens and accessibility.
2. Expose rule management API if missing: list, create, edit, priority reorder, enable/disable, preview/simulate, protected by management permissions and branch scope.
3. Build frontend for rule priority, branch, travel category, source, availability, workload and fallback queue; use existing design system only.
4. Add explainability: show why a lead was assigned, last meaningful activity, business calendar, reassignment attempts, history and manual overrides.
5. Test time zones, holidays/business calendars, simultaneous rules and deterministic tie-breaks; clearly mark unsupported holiday configuration rather than assuming national calendars.

## Acceptance tests and validation
- Admin can configure rules end to end without direct DB edits.
- Sales user cannot edit routing rules.
- 24 business hours, priority and fallback behavior tested; no duplicated assignments.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
