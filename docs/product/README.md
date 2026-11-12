# Product Documentation — Index

Originally produced by step `01-product-discovery`; now maintained alongside the code. **`gap-register.md` is the authority on what is shipped, what is missing, and what is blocked.** Where a planning document below disagrees with it, the register wins.

| Document | Purpose | Status |
|---|---|---|
| `PRD.md` | Product blueprint: actors, scope, requirements, business rules, NFRs, assumptions. | Draft — human review |
| `module-matrix.md` | Module ownership, boundaries, dependencies, phases, feature toggles. | Implementation-aware; register supersedes |
| `workflows.md` | Booking/payment/refund/supplier/commission/CRM state machines and posting flows. | Draft — accounting items need approval |
| `decisions-needed.md` | 30 numbered decisions with options, owners, and blocking impact. | Open |
| `acceptance-criteria.md` | 112 observable/testable criteria. | Draft |
| `traceability-matrix.md` | Source scope → requirements → modules → issues → criteria. | Implementation-aware; register supersedes |
| `gap-register.md` | Verified list of remaining implementation, QA, and documentation gaps with evidence, severity, and remediation order. | Living |
| `epics.md` | 13 epics, critical path, milestones, delivery rules. | Draft |
| `multi-user-ux-roadmap.md` | Multi-user collaboration (concurrency, notifications, approvals inbox, assignment, activity feed) and UX epics; role/branch test plan; single legal entity per install. | Proposed - planning only |
| `../architecture/mcp-ai-assistant-blueprint.md` | Read-only MCP tool catalog, per-user token model, threat cases, staged acceptance; write tools BLOCKED. | Proposed - design only |
| `issues/` | 54 dependency-ordered, PR-sized issues; each `TE-###` carries a Status line (done / partial / not started / blocked). | Living |

## What exists vs. what is missing

**Shipped modules:** Core/auth (users, roles, branches, settings, audit trail, health, install), Money/FX, Accounting (CoA, periods, journals, opening balances, fiscal close, fixed assets, schedules, cost centers), Treasury (cash/bank, transfers, reconciliation), Parties, Bookings and tour packages, Payments (receipts, refunds, supplier settlement), Invoices (AR/AP, PDF), Expenses, Commissions, Reports (ledger, statements, aging, CSV/XLSX/PDF), CRM (leads, assignment rules, conversion), Meta/WhatsApp integrations (fixture-tested), Imports. The React + TypeScript frontend is built on the supplied design system (`docs/design/DESIGN.md`, tokens in `web/src/index.css`); stack is Laravel, PostgreSQL, Docker Compose, CI.

**Open work:** report pack, CRM scheduler/pipeline, notifications, attachments decision, E2E/a11y/coverage gates. See `gap-register.md` sections 3 to 7.

**Still open externally (blocking acceptance):**

1. **Full Safe Way proposal / signed PRD** — the PRD is based on a summary; requirements may change.
2. **Accountant decisions** — the `ACC` items still open in `decision-implementation-matrix.md` (supplier cost recognition, commission posting, revaluation, statutory formats).
3. **Meta/WhatsApp credentials** — needed for live integration acceptance; contract tests use fixtures.

Per-module implementation gaps (not the same list as the blocking items above) are tracked in `gap-register.md`.

## Recommended next steps

1. Close the open gaps in the order given by `gap-register.md` §10 (Reports → CRM → Core → QA harness → docs → release).
2. Record the remaining accountant decisions before enabling production posting paths.
3. Supply Meta/WhatsApp credentials before integration acceptance.


## Rule reminders

- One issue → one branch → one PR; tests required; no direct commits to `main`; no force push.
- Financial postings must be balanced in transaction and base currency, immutable, and traceable to source.
- Unresolved business rules stay configurable and flagged — never guessed.
- No secrets in the repository; no deployment without human approval.
