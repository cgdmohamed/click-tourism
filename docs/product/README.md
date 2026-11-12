# Product Documentation — Index

Produced by step `01-product-discovery`. Implementation is in progress: see `gap-register.md` for what is shipped, what is still missing, and what is blocked. Planning documents below that predate implementation may describe the original no-code baseline.

| Document | Purpose | Status |
|---|---|---|
| `PRD.md` | Product blueprint: actors, scope, requirements, business rules, NFRs, assumptions. | Draft — human review |
| `module-matrix.md` | Module ownership, boundaries, dependencies, phases, feature toggles. | Draft |
| `workflows.md` | Booking/payment/refund/supplier/commission/CRM state machines and posting flows. | Draft — accounting items need approval |
| `decisions-needed.md` | 30 numbered decisions with options, owners, and blocking impact. | Open |
| `acceptance-criteria.md` | 112 observable/testable criteria. | Draft |
| `traceability-matrix.md` | Source scope → requirements → modules → issues → criteria. | Draft |
| `gap-register.md` | Verified list of remaining implementation, QA, and documentation gaps with evidence, severity, and remediation order. | Living |
| `epics.md` | 13 epics, critical path, milestones, delivery rules. | Draft |
| `multi-user-ux-roadmap.md` | Multi-user collaboration (concurrency, notifications, approvals inbox, assignment, activity feed) and UX epics; role/branch test plan; single legal entity per install. | Proposed - planning only |
| `../architecture/mcp-ai-assistant-blueprint.md` | Read-only MCP tool catalog, per-user token model, threat cases, staged acceptance; write tools BLOCKED. | Proposed - design only |
| `issues/` | 54 dependency-ordered, PR-sized issues using `TASK_TEMPLATE.md`. | Draft |

## What exists vs. what is missing

**Exists:** the prompt pack, a summarized source scope (`reference/SOURCE_SCOPE.md`), the product context, and a working application: Laravel API with modular monolith domains, React + TypeScript frontend built on the supplied design system (`docs/design/DESIGN.md`, tokens in `web/src/index.css`), PostgreSQL, Docker Compose, and CI.

**Still missing (blocking):**

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
