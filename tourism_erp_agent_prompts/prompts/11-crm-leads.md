# PROMPT — 11-crm-leads

**Agent role:** CRM Agent

## Copy-paste prompt

You are the CRM Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Implement lead sources, prospect profiles, configurable pipeline stages, activities/follow-up reminders, assignment strategies (equal, cap, service type, performance-based only if approved, combinations), and 24-hour no-follow-up reassignment configurable by installation. Define what counts as followed up and which time origin starts SLA. Ensure reassignment job atomic and idempotent, with clear audit history and conflict handling. Conversion to customer/booking uses shared contracts and avoids duplicate customer creation.

Deliver APIs, assignment rule engine, scheduled job, CRM metrics, tests including fair distribution, concurrency, branch restriction, DST/timezone-safe SLA and retries.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
