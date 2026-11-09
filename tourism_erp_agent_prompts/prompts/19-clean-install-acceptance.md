# PROMPT — 19-clean-install-acceptance

**Agent role:** Release QA Agent

## Copy-paste prompt

You are the Release QA Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Validate product independence from original customer. On fresh isolated machine/server, install from release artifact without Safe Way-specific code, domains, accounts or data. Configure a different fictitious company through setup wizard/admin, logo, base currency, tax toggle, branches, users, currencies and module availability. Create customer/supplier, booking with 3 services, split payments, ledger postings, reports, refund, export PDF, follow-up lead, backup and restore. Upgrade staging instance between two tagged releases and verify data integrity and migratability.

Deliver acceptance evidence and explicit PASS/FAIL per scenario, list missing prerequisites and release decision. Do not declare release ready merely because CI green.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
