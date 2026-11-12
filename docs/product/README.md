# Product Documentation — Index

Produced by step `01-product-discovery`. **No application code has been written.**

| Document | Purpose | Status |
|---|---|---|
| `PRD.md` | Product blueprint: actors, scope, requirements, business rules, NFRs, assumptions. | Draft — human review |
| `module-matrix.md` | Module ownership, boundaries, dependencies, phases, feature toggles. | Draft |
| `workflows.md` | Booking/payment/refund/supplier/commission/CRM state machines and posting flows. | Draft — accounting items need approval |
| `decisions-needed.md` | 30 numbered decisions with options, owners, and blocking impact. | Open |
| `acceptance-criteria.md` | 112 observable/testable criteria. | Draft |
| `traceability-matrix.md` | Source scope → requirements → modules → issues → criteria. | Draft |
| `epics.md` | 13 epics, critical path, milestones, delivery rules. | Draft |
| `multi-user-ux-roadmap.md` | Multi-user collaboration (concurrency, notifications, approvals inbox, assignment, activity feed) and UX epics; role/branch test plan; single legal entity per install. | Proposed - planning only |
| `../architecture/mcp-ai-assistant-blueprint.md` | Read-only MCP tool catalog, per-user token model, threat cases, staged acceptance; write tools BLOCKED. | Proposed - design only |
| `issues/` | 54 dependency-ordered, PR-sized issues using `TASK_TEMPLATE.md`. | Draft |

## What exists vs. what is missing

**Exists:** the prompt pack, a summarized source scope (`reference/SOURCE_SCOPE.md`), and the product context.

**Missing (blocking):**
1. **Full Safe Way proposal / signed PRD** — the PRD is based on a summary; requirements may change.
2. **Design system** — no tokens, components, Figma export, fonts, or CSS. Step `02-design-system-audit` and all UI issues (TE-010, TE-049..TE-052) are blocked.
3. **Accountant decisions** — 18 `ACC` decisions (revenue recognition, FX, tax point, commission treatment, closing, etc.).
4. **Meta/WhatsApp credentials** — needed for live integration acceptance; contract tests can use fixtures.

## Recommended next steps

1. Human review of this PRD and `decisions-needed.md`; record decisions.
2. Supply the design system, then run `02-design-system-audit.md`.
3. Run `03-architecture-contracts.md` (ADRs, ERD, OpenAPI, event catalog, migration order, threat model).
4. Approve contracts, then start `04-foundation.md` / `05-core-platform.md` (TE-001..TE-010).

## Rule reminders

- One issue → one branch → one PR; tests required; no direct commits to `main`; no force push.
- Financial postings must be balanced in transaction and base currency, immutable, and traceable to source.
- Unresolved business rules stay configurable and flagged — never guessed.
- No secrets in the repository; no deployment without human approval.
