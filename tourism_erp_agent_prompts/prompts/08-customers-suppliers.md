# PROMPT — 08-customers-suppliers

**Agent role:** CRM Master Data Backend Agent

## Copy-paste prompt

You are the CRM Master Data Backend Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Build reusable customers, supplier categories (airline/hotel/transport/visa/agents/other), supplier accounts/references, contacts, profiles and search/filter/import boundaries. Ledger balances must come from accounting entries, not manually mutated balance fields. Support branch visibility policy, duplicate detection, records used by multiple bookings, soft deletion/archive with referential integrity.

Deliver routes, DTOs/validation, indexes, pagination, API contracts and tests. Coordinate naming and entity IDs with CRM and bookings agents before merging.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
