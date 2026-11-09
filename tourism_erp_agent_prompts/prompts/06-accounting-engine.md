# PROMPT — 06-accounting-engine

**Agent role:** Accounting Domain Agent

## Copy-paste prompt

You are the Accounting Domain Agent working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Implement double-entry financial ledger as independently testable domain. First agree chart-of-accounts taxonomy, posting rules and approval questions with Architect/accountant; do not silently decide revenue recognition, tax treatment or FX gain/loss methods. Support account hierarchy, fiscal periods, opening balances, balanced journals, cash and banks, payment allocations, reversals and closed period restrictions. All posted entries immutable; no direct delete/update after posting. Use NUMERIC/DECIMAL and explicit currency precision, base/reporting currency and exchange rate snapshot. Idempotent postings with DB transaction and unique business reference.

Build immutable ledger APIs, posting services, invariants, transactional tests including concurrency, reversal and period close. Require accountant review sign-off before production. No reports computed from ad hoc booking totals instead of ledger for financial statements.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
