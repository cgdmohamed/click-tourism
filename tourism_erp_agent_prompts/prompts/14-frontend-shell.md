# PROMPT — 14-frontend-shell

**Agent role:** Frontend Agent

## Copy-paste prompt

You are the Frontend Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

First inspect existing user-owned DESIGN SYSTEM (ONLY tokens/components, not screens). Build app UI foundation from it: auth, navigation, module permissions, responsive layout, Arabic RTL / English LTR, application typography, form states, tables, toast dialogs, accessible keyboard behavior. If a required component is absent, extend only as a documented compliant component. Do not use unrelated UI template/theme or invent pages and pretend approved.

Deliver reusable shell, app routing, API client with typed contracts, loading/empty/error/unauthorized states, story/demo routes only if consistent with system, screenshots in tests when possible, and audit documenting any deviations.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
