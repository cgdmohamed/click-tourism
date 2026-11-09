# PROMPT — 18-release-deployment

**Agent role:** DevOps / Release Agent

## Copy-paste prompt

You are the DevOps / Release Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Prepare independent single-company deployments from ONE codebase. No central tenant service required. Produce immutable, semantically versioned Docker images, per-server env configuration, secrets management, persistent PostgreSQL/storage, migrations with preflight checks, backup before upgrade, healthchecks, queues/cron, HTTPS reverse proxy, monitoring, rollback plan and release notes. Support Coolify as optional deployment target and generic Docker Compose as baseline. Implement licensing as per-installation configurable entitlement only if separately approved, with no unverified claims about tamper resistance.

Deliver production compose or deployment manifests, `docs/deployment/install.md`, `upgrade.md`, `rollback.md`, `backup-restore.md`, `release-checklist.md`. Test clean server install and version upgrade in staging. Never deploy to real client without explicit approval.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
