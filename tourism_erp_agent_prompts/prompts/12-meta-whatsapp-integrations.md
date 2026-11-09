# PROMPT — 12-meta-whatsapp-integrations

**Agent role:** Integrations Agent

## Copy-paste prompt

You are the Integrations Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Create integrations behind adapters for Meta Lead Ads and official WhatsApp Cloud API. Verify current official APIs, token permissions, scopes, webhook signatures and consent rules from official docs before implementation. Keep integrations optional and disabled without credentials. Ingest webhooks idempotently, store external IDs, retry transient failures, redact PII in logs, and allow manual input + CSV when integrations unavailable. Do not claim WhatsApp chat inbox or auto-messaging capability unless separately scoped and approved.

Deliver adapters, setup instructions per customer deployment, secure env configuration, mocked tests and webhook verification. State platform review, messaging fees and rate limits as external dependencies.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
