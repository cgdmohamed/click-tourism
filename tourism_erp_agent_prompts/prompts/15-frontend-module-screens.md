# PROMPT — 15-frontend-module-screens

**Agent role:** Frontend Feature Agent

## Copy-paste prompt

You are the Frontend Feature Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Implement actual ERP SCREENS from approved PRD and API contracts (there are no existing application screens). Sequence: accounting setup/accounts/journal; customers/suppliers; booking editor with multiple services and cost breakdown; payments/refunds; CRM pipeline and lead details; reports; user roles/settings. Reuse actual design system components and tokens from repository; no copy/paste UI from a third-party kit. Prefer forms with validation, responsive tables, filters, realistic empty/error/loading states, Arabic/English translation, permission-conditioned controls. Align money precision and currencies with backend.

Deliver routes/components/hooks/API mapping, E2E critical flows and a screen checklist. Keep UI modular; do not fake backend success or store source-of-truth money in frontend state.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
