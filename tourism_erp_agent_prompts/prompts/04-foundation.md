# PROMPT — 04-foundation

**Agent role:** Platform Engineering Agent

## Copy-paste prompt

You are the Platform Engineering Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Inspect repo and existing design system; scaffold only absent infrastructure. Configure Laravel API, React/TypeScript/Vite app, PostgreSQL, Redis, Docker Compose, env examples, migrations, minimal API health endpoint, frontend app shell using existing design system, lint/typecheck/Pest/Playwright basics, GitHub Actions CI, local dev instructions. Avoid creating competing design tokens or custom UI kit. Configure secrets exclusively via environment and avoid copying credentials.

Deliver runnable local setup, compose services, healthcheck, CI and smoke test, `README-DEVELOPMENT.md`, and known caveats. Prove `docker compose up`, migration, app boot, and tests, or clearly say why verification blocked. Do not begin domain modules.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
