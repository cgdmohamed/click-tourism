# Repair CI and Frontend Quality Gate

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P0  
**Depends on:** None

## Mission
Make main branch CI green and prevent recurrence; fix the actual cause, not lint rules.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Inspect latest GitHub Actions run and logs. Audit `web/src/` and `.github/workflows/ci.yml` (last observed failure: 4 ESLint errors, 10 warnings, `useI18n` called conditionally, explicit `any`). Revalidate: do not assume this is still current.
2. Fix hook-order violations structurally (move hooks before early returns), narrow unknown API types using Zod/TypeScript types, and eliminate duplicate keys/routes if still present. Preserve design system, RTL/LTR and page behavior.
3. Make `npm run lint`, `npm run build`, `php artisan test`, `vendor/bin/pint --test`, and `composer audit` required CI steps. Do not weaken rules, add broad eslint-disable, or skip build on lint failure.
4. Add a small UI regression test for the affected component if test infrastructure supports it; otherwise establish a reliable lightweight component test harness and document constraints.
5. Inspect and report branch-protection/ruleset availability; recommend required status checks, but do not change repository policy without authorization.

## Acceptance tests and validation
- CI pipeline succeeds on the branch end-to-end; include run URL and SHA.
- The conditional React hook no longer exists; lint has zero errors.
- TypeScript build succeeds; no behavioral regressions in the corrected pages.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
