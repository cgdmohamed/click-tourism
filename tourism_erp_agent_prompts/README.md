# Tourism ERP — AI Agent Prompt Pack

**Purpose:** Develop one reusable, self-hosted Tourism ERP product deployed independently on each client's server. This pack assumes the user already has a **design system, not finished screens**.

## How to use
1. Copy `reference/PRODUCT_CONTEXT.md` and `AGENTS.md` to the root of the product repository (retain other existing instructions; reconcile conflicts).
2. Give the Architect Agent `prompts/01-product-discovery.md`, then `02-design-system-audit.md` and `03-architecture-contracts.md` in order. Attach the Safe Way proposal and point it to your actual design-system files/repo. The archive does **not** contain the design system itself.
3. Run `04-foundation.md`, then `05-core-platform.md`.
4. Split modules by owned files/worktrees with approved contracts. Accounting comes before financial posting/settlements. CRM and customer/supplier catalog can run in parallel after foundation.
5. For each issue: Implementation Agent → QA (`16-qa.md`) → Reviewer (`17-reviewer.md`) → human approval → merge. Use `templates/TASK_TEMPLATE.md` and `templates/PR_TEMPLATE.md`.
6. Run `18-release-deployment.md` and `19-clean-install-acceptance.md` before first customer deployment.

## Recommended execution order
01 → 02 → 03 → 04 → 05 → [06 accounting + 08 customers/suppliers + 11 CRM] → 07 bookings/payments (after financial contracts) → 09 reports + 10 commissions + 12 integrations + 13 exports → 14/15 UI integration → 16/17 QA & review → 18/19 release.

**Caution:** Parallelism begins after architecture/contracts are agreed. Branches and PRs are not a substitute for integration tests. UI pages must be built from the supplied design system once it is available.
