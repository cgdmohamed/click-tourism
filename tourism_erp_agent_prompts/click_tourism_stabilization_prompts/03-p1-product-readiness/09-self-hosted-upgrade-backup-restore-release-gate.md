# Self Hosted Upgrade Backup Restore Release Gate

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 06 and green CI

## Mission
Ensure every customer server can be installed, backed up and upgraded safely from one codebase.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Audit Docker Compose, container entrypoints, migration-on-start, queue worker, scheduler, Redis persistence, health probes, storage attachments and secrets configuration.
2. Create versioned release process using tags/images, tested one-install-per-legal-entity setup, no demo credentials by default, build reproducibility and backup-before-upgrade checklist.
3. Implement repeatable PostgreSQL logical backup + attachment backup with encryption/retention options and a **tested** restore drill in isolated environment; document RPO/RTO as targets, not promises.
4. Perform schema migration compatibility smoke tests for N-1 → N and rollback procedure. Avoid `migrate:fresh`, in-place ledger modifications and auto-rollbacks that lose posted entries.
5. Handle worker/scheduler coordination across upgrades and prevent duplicate financial jobs. Include rollback guide and validation checklist.
6. No external production deployment or credential access without explicit owner approval.

## Acceptance tests and validation
- Fresh installation, restore, upgrade and rollback simulation documented with exact commands and logs.
- No seeded demo passwords or leaked secrets in release image.
- Ledger balances unchanged after controlled upgrade.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
