# ADR-0027 - Assistant actions: approve, send, post, pay, void, delete and export, each confirmed on a card (amends ADR-0023)

- **Status:** Accepted (project owner decision 2026-11-14, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). In-product assistant only. The MCP gateway and MCP tokens stay read-only.
- **Date:** 2026-11-14
- **Related:** ADR-0023 (prepare, confirm, run as the user), ADR-0022, ADR-0004 (posting), `docs/architecture/threat-model.md`.

## Context

ADR-0023 let the assistant prepare drafts and master records. It excluded approving, posting, paying, sending, deleting and exporting. The owner asked for those too ("يعمل الاعتماد، والترحيل، والصرف، والإرسال، والحذف، والتصدير") with the same rule: before anything runs, the user is shown a notice and decides whether to execute.

## Decision

1. **Same mechanism.** Every new action is a tool the model can only PREPARE. It stores a pending action and returns a card; nothing runs until the user confirms (ADR-0023 items 2, 3 and 5 apply unchanged: exactly-once, 15-minute expiry, own actions only, same branch, `assistant.enabled` master switch). Confirming replays the normal route as the user, so the route's permission, branch scope, validation, period checks, maker-checker rules and audit still apply and can still refuse (the card then shows the failure).
2. **Actions added** (tool: route, permission):
   - Send for approval: `erp_invoice_submit`, `erp_expense_submit`, `erp_document_submit`.
   - Approve: `erp_invoice_approve`, `erp_expense_approve`, `erp_document_approve`, `erp_commission_approve`, `erp_refund_approve`.
   - Issue or post: `erp_invoice_issue`, `erp_document_post`, `erp_commission_post`, `erp_journal_post`.
   - Pay: `erp_commission_pay`, `erp_payment_record` (customer receipt), `erp_supplier_payment_record`.
   - Void or delete: `erp_invoice_void`, `erp_journal_draft_delete` (drafts only).
   - Export: `erp_export_queue` (background export, ADR-0025).
3. **One record at a time, named exactly.** An action on an existing record takes its public id or its document number and is looked up in the user's active branch; none or several matches is refused before a card exists. There is no bulk action. The card names the record, its current status, its amount and one sentence on what confirming does.
4. **Accounts are named, never guessed.** Pay and receipt actions take the payment account by name; an unknown or ambiguous name is refused with the list of the branch's accounts.
5. **Second confirmation for the irreversible.** The card carries `risk` (create, approve, send, post, pay, void, delete, export). For post, pay, void and delete the screen asks a second time ("this affects the ledger or money and is hard to undo") before the request is sent.
6. **Prompt.** `system-prompt.v3.md` replaces v2: one action at a time, only for what the user asked, never state that anything is done before the card is confirmed.
7. **Not included:** deleting anything other than draft journal entries, reversing posted records, closing periods, changing roles or settings, and any action on many records at once.

## Release conditions

| Condition | Status |
|---|---|
| Owner approval | Given (record below) |
| Card with the exact record before every execution | Yes |
| Runs as the user, route rules apply | Yes (tested: approving a draft is refused by the route, the card shows it) |
| Audit | `assistant.action_proposed|executed|failed|rejected` plus the route's own rows |
| Accountant sign-off for actions that post or pay | **Not obtained.** The owner decided; record the accountant's view in `decisions-needed.md`. The kill switch (`assistant.enabled = false`) stops every pending card |

## Consequences

- A prompt injection can at worst prepare a card the user must press (twice for post, pay, void, delete). Users must read the card.
- Ledger and cash can now change because a user pressed a button the assistant prepared; the audit trail names the user and the action id.
- Rollback: `assistant.enabled = false`, or set the prompt back to v2 and remove the tools from the catalogue; no schema change (the existing `assistant_actions` table is reused). Contract change: the new tool names and the `risk` field of the card in `openapi.yaml`.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Widen the assistant's confirmed actions to approve, send, post, pay, delete and export | Project owner | architect | 2026-11-14 | Owner message "يعمل الاعتماد، والترحيل، والصرف، والإرسال، والحذف، والتصدير والتنفيذ زي ما قلت يظهر تنبيه للمستخدم اذا هياكد التنفيذ او لا" |
