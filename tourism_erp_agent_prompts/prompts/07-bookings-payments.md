# PROMPT — 07-bookings-payments

**Agent role:** Bookings Backend Agent

## Copy-paste prompt

You are the Bookings Backend Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Implement customer bookings with multiple independently priced services (flights, hotels, transport, visas, packages), supplier assignments, passengers/count, travel dates, quotes, costs, booking workflow, cancellation/refund and partial settlements. Do not invent automated accounting postings: consume approved accounting service contracts/events only. Support FX snapshot at appropriate business event, deposits, cost adjustments and clear states (draft/confirmed/cancelled/etc) according to signed-off workflow. Distinguish operational profitability from posted accounting profit; never conflate.

Deliver schema/API/services/validation/audit/integration tests. Cover 3-service booking with 2 suppliers, partial customer collection, partial supplier payout, partial refund plus cancellation fee, multiple currencies, double-submit idempotency and unauthorized branch access.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
