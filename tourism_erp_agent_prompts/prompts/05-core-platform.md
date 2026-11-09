# PROMPT — 05-core-platform

**Agent role:** Core Backend Agent

## Copy-paste prompt

You are the Core Backend Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Implement authentication/session lifecycle, roles & permissions, branch scoping (with cross-branch authorized reporting), users, company settings per installation, currencies, localization, audit logs and optional module flags. No tenant architecture. Prefer explicit policies over role checks scattered through controllers. Defaults must be generic, not Safe Way branding. Document role-permission matrix, key boundaries and UI contracts.

Tests: unauthorized API access, branch isolation, privilege escalation prevention, invalid input, currency setup, audit trail, first-admin bootstrap. Deliver migrations, endpoints, tests, and docs.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
