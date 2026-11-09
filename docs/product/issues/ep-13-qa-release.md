# EP-13 — QA, Release, and Clean-Install Acceptance

---

## TE-053 — QA automation suite

**Goal:** Prove the product against the PRD before release.
**Scope in:** Unit tests for money/accounting/FX/commission/tax, integration tests for posting flows, authorization matrix tests, concurrency/idempotency tests, API contract tests, E2E for critical journeys (booking→payment→refund, lead→conversion, close→report), performance seed + p95 checks, accessibility checks, security scans (secrets, dependencies).
**Scope out:** Manual exploratory testing (tracked separately).
**Dependencies:** All MVP epics; TE-054 in parallel.
**API contract changes:** None.
**Acceptance criteria:** AC-FIN-*, AC-PAY-*, AC-RPT-*, AC-CRM-*, AC-INT-*, AC-DATA-*, AC-SEC-*, AC-NFR-001; coverage ≥ 80% on accounting engine and money/FX; no critical defects.
**Test cases:** full matrix referenced in `acceptance-criteria.md`; failure injection for postings/jobs/webhooks.
**Owned files:** `backend/tests`, `web/tests`, `.github/workflows`, `tests/performance`.
**Reviewer:** QA lead + architect.

---

## TE-054 — Release packaging, backup/restore, and clean-install acceptance

**Goal:** A human can deploy, upgrade, and recover a customer installation.
**Scope in:** Versioned Docker images/compose, env documentation, migration + rollback rehearsal, encrypted backup/restore scripts, health checks, release notes template, upgrade guide, clean-install checklist per `19-clean-install-acceptance.md`, no-secret verification.
**Scope out:** Managed hosting, domain procurement.
**Dependencies:** TE-053 (can start after MVP modules stabilize).
**API contract changes:** None.
**Acceptance criteria:** AC-NFR-002, AC-NFR-003, AC-NFR-004, AC-SEC-001; clean install passes on a fresh host; restore reproduces data/reports; rollback verified.
**Test cases:** fresh install, upgrade from previous version, failed migration rollback, backup restore, timezone/DST, Arabic PDF after restore.
**Owned files:** `docker/`, `deploy/`, `docs/ops/`, `scripts/backup`, release notes.
**Reviewer:** Architect + human release approver (**no deployment without human approval**).
