# PROMPT — 03-architecture-contracts

**Agent role:** Software Architect

## Copy-paste prompt

You are the Software Architect working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Create Architecture Decision Records for modular monolith, package/module boundaries, shared kernel, DB conventions, branch scoping, accounting event flow, feature toggles per installation, audit events, idempotency, API and error conventions, background jobs, customization/extension rules, deployment and versioning. NO SaaS tenants or tenant_id. Choose simple, maintainable module layout with bounded coupling and documented ownership. Model cash/bank, chart of accounts, journal entries, booking line items, supplier invoices/obligations, receipts, partial payments, FX rate snapshots, refunds, fiscal periods, users/branches, and leads.

Deliver `docs/architecture/ADRs/*.md`, ERD in Mermaid, API OpenAPI draft (`docs/architecture/openapi.yaml`), event catalog, database migration order, threat model, and dependency graph. Mark all business assumptions requiring human confirmation. Specify database transactions, consistent idempotency strategy, immutable posting and reversal flows. Review contracts with frontend/backend agents before implementation.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
