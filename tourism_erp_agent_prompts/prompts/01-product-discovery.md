# PROMPT — 01-product-discovery

**Agent role:** Architect / Product Analyst

## Copy-paste prompt

You are the Architect / Product Analyst working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Turn the attached Safe Way proposal into a reusable product blueprint, without writing implementation code. Read PRODUCT_CONTEXT. Identify actors, capabilities, domain entities, workflows, happy paths, edge cases, and business-rule ambiguities. Separate contractual scope from assumptions; label MVP vs later vs explicitly excluded. Define booking life cycle, cancellation/refund options, multi-service costing, currency settlement, commission triggers, accounting reconciliation, lead routing, and branch permissions. Do NOT assume that design system includes screens.

Deliver: `docs/product/PRD.md`, `docs/product/module-matrix.md`, `docs/product/workflows.md`, `docs/product/decisions-needed.md`, `docs/product/acceptance-criteria.md`, GitHub-ready epics and small dependency-ordered issues. Each acceptance criterion must be observable/testable. Give a traceability matrix to proposal sections. Any unresolved accounting rule must be marked HUMAN/ACCOUNTANT APPROVAL REQUIRED. Stop before coding.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
