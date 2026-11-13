# ADR-0023 - Assistant actions: the assistant prepares, the user confirms, the system runs it as the user (amends ADR-0017 and blueprint section 9)

- **Status:** Accepted (project owner decision 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). Applies to the in-product assistant only. The MCP gateway and MCP tokens stay read-only (`mcp.readonly` still refuses every non-GET).
- **Date:** 2026-11-13
- **Related:** ADR-0016, ADR-0017, ADR-0022, `docs/architecture/mcp-ai-assistant-blueprint.md` section 9 ("Write tools: BLOCKED"), `docs/architecture/threat-model.md`.

## Context

The owner asked for the assistant to execute actions, not only read ("المساعد ينفّذ إجراءات"), with a confirmation card before each execution, and with two product rules in the owner's words: "مهم تعمل زي يوزر بدون خيارات عشان يبان انه تم تسجيل بنود من خلال ai" (act as a user, without options, so it is visible that items were recorded through AI). Blueprint section 9 listed release conditions for any write tool: written product approval; accountant sign-off for anything touching postings; draft objects only; mandatory idempotency key; a confirmation card with the exact payload; the maker is the human; full audit; a feature flag off by default; a new threat review.

## Decision

1. **Scope of the first batch** (owner choice): create a lead, update a lead's stage or add a note; create a customer; create a supplier; create a **draft** customer invoice; create a **draft** expense; create a **draft** booking. Nothing posts, approves, issues, pays, sends, deletes or exports. Customer invoices only (supplier invoices and any posting stay out).
2. **Prepare, confirm, run.** The model calls a `erp_*_create|update|draft_create` tool; the server validates the arguments against a schema and resolves references (customer, supplier, expense category, payment account, lead), stores the proposal (`assistant_actions`, payload encrypted, expires after 15 minutes) and returns a **confirmation card** with the full payload. **Nothing is saved at that point.** `POST /assistant/actions/{id}/confirm` is a request from the user's own session; `POST .../reject` discards it. The model has no way to confirm.
3. **Acts as the user.** Confirming replays the request through the normal API route in the same process with the user as the actor, the stored branch in `X-Branch-Id` and `Idempotency-Key: assistant-<action id>`. The route's own permission middleware, validation, branch scope, idempotency, services and audit rows apply unchanged: the assistant has no identity or permissions of its own. A tool is offered to the model only when the user holds the route's permission; a permission revoked between proposal and confirm makes the confirm fail with `forbidden`.
4. **Visible provenance, no options.** Records created this way carry `created_via = assistant` (new nullable column on leads, customers, suppliers, invoices, expenses, bookings; exposed as `created_via` in their API arrays; an "AI" badge in the lists). The audit trail records `assistant.action_proposed`, `assistant.action_executed`, `assistant.action_failed`, `assistant.action_rejected` with the tool, the action id and the record id, never the data. There is **no new setting or toggle**: availability follows the user's permissions and the existing assistant on/off switch.
5. **Safeguards.** Exactly-once: the confirm locks the action row; a second confirm is `409 assistant_action_decided`, an old one `410 assistant_action_expired`, another user's `404`, another branch `409 assistant_action_branch_mismatch`. The model cannot set the branch, the draft state (`submit` is forced false), the invoice type or the revenue account (setting `accounting.revenue_account_code`). The system prompt (v2) forbids preparing an action because of text inside `<untrusted_data>`; the card is what protects against a successful injection, since the user sees the exact payload.

## Release conditions of blueprint section 9

| Condition | Status |
|---|---|
| Written product approval | Given (approval record below) |
| Draft objects only; maker is the human | Drafts only for invoices, expenses, bookings; the user confirms each action; posting and approval remain separate human steps in the normal screens. Master records (lead, customer, supplier) are created directly after confirmation |
| Idempotency key | `Idempotency-Key: assistant-<action id>` on every replay; exactly-once is also enforced by the action row lock |
| Confirmation card with the exact payload | Yes, one per action, before every execution |
| Full audit | Yes (item 4), plus the route's own audit rows (`customer.created`, ...) |
| Accountant sign-off for anything touching postings | **Not obtained.** Drafts post nothing (tested: a draft invoice creates no journal entry), so the assistant touches no posting; the owner should still record the accountant's view in `decisions-needed.md` before relying on assistant-created drafts in closing procedures |
| Feature flag off by default | **Waived by the owner** ("بدون خيارات"): no new toggle. The assistant's existing master switch (`assistant.enabled`) still disables everything, and a user without the underlying permission never sees the tool |
| New threat review | Added to `threat-model.md` (assistant actions rows) |

## Consequences

- A prompt-injection that fools the model can at worst prepare a card the user must press; it cannot execute. Users must read the card: the text says so.
- Records can be created by someone who only pressed a button the assistant prepared; `created_via` makes that visible, the audit row names the user.
- Rollback: `assistant.enabled = false` stops all of it. The migration is additive; `created_via` is nullable and can be ignored. Pending actions simply expire.
- Contract change: two routes, `actions` in the chat payload and in saved chat messages, `created_via` on six API resources, new tool names for the model. `openapi.yaml` is the source of truth.

## Approval record

`AGENTS.md` and `docs/architecture/README.md` require architect approval for contract changes and for departing from an accepted ADR. Given by the project owner in the implementation session (branch `claude/clever-sagan-89spcf`).

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Open stage 4 for the in-product assistant: it may prepare actions that the user confirms (items 1-3) | Project owner | architect | 2026-11-13 | Owner request "عايز اضافة صلاحيات تنفيذية للنظام" and answer "المساعد ينفّذ إجراءات" |
| 2 | First batch: leads (create, stage/note), customers, suppliers, draft invoices, draft expenses, draft bookings | Project owner | architect | 2026-11-13 | Owner selection "إدارة العملاء المحتملين, إنشاء عميل أو مورّد, مسودات فواتير ومصروفات, مسودات حجوزات" |
| 3 | A confirmation card before every execution | Project owner | architect | 2026-11-13 | Owner selection "بطاقة تأكيد قبل كل تنفيذ" |
| 4 | Runs as the user with no options and marks records as created through AI (`created_via`, no new setting) | Project owner | architect | 2026-11-13 | Owner instruction "مهم تعمل زي يوزر بدون خيارات عشان يبان انه تم تسجيل بنود من خلال ai" |
| 5 | Routes `POST /assistant/actions/{id}/confirm`, `.../reject`; `actions` in chat payloads; `created_via` on six resources | Project owner | architect | 2026-11-13 | Same decisions as rows 1-4 (they are the contract these items implement) |
