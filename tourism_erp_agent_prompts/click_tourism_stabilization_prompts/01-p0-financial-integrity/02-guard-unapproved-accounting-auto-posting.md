# Guard Unapproved Accounting Auto Posting

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P0  
**Depends on:** 01 (CI green)

## Mission
Prevent posting based on provisional accounting policies; design a safe transition for existing installations.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Audit `backend/app/Modules/Core/Services/SettingService.php`, `DecisionRegistry.php`, booking transitions, `AccountingScheduleService`, scheduled jobs, fiscal close, tax, invoicing and settings APIs.
2. Trace all `auto_post` and state-changing financial paths. Identify current default `accounting.auto_post_bookings=true`, `booking_posting_trigger=confirm`, `schedule_auto_post=true` and exact runtime behavior. Inspect if a real approval guard already exists.
3. Introduce centralized accounting-policy authorization/preflight for each posting path, keyed to explicit approved policy/version per installation and required accountant sign-off; do not simply flip defaults on existing live deployments without assessing their effect.
4. Default **new** installations to no posting for approval-dependent policies until approved. For existing installations, create non-destructive migration/upgrade warning and review workflow, avoiding silent historical changes.
5. Preserve draft/preview workflows while blocked. Surface actionable errors/UI warnings and health/decision state; log decisions and blocked attempts. Never forge accountant approval.
6. Publish a reconciliation/impact report for installations with previously posted entries, without reversing them automatically.

## Acceptance tests and validation
- Unapproved booking confirmation and schedule activation cannot create financial posting, including from queue/CLI/API.
- Approved policy can post once with full audit and fiscal period checks.
- Existing posted entries unchanged; idempotent upgrade and rollback documented.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
