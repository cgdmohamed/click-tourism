# ADR-0030 - Assistant input guard and a simple flow (amends ADR-0023)

- **Status:** Accepted (project owner request 2026-11-15: keep the system simple, the assistant should help and not add steps, and a protection layer so a user cannot run outside commands or skip the assistant's controls).
- **Date:** 2026-11-15
- **Related:** ADR-0023/0027 (prepare, confirm, run), ADR-0029 (forms).

## Context

The assistant can prepare actions that change the ledger. The model could be told, by the user or by pasted text, to ignore its rules, reveal its instructions, run a command or act "without confirmation". It cannot do those things (no shell, no network, only allowlisted tools, the user's own permissions, a confirmation card), but such requests should not reach the model at all, and the assistant must not make routine work longer.

## Decision

1. **Input guard.** `InputGuard` refuses, before any provider call, the plain forms of: overriding or revealing the rules, running a system command or SQL, and skipping the confirmation, approval or permission check (English and Arabic). The user gets one short refusal; the audit row has status `blocked_input`; no tokens are spent. Questions about the data that merely contain such words ("invoices posted without approval") pass.
2. **The guarantees do not depend on the guard.** The tool allowlist, the user's permissions and branch, the confirmation card (second confirmation for post, pay, void, delete) and maker-checker on approvals stay as they are. The guard is a first filter, not the control.
3. **Simple flow.** Prompt v5 rules 13 and 14: answer briefly; ask only for what cannot be found or decided; use sensible defaults and say so; one form per answer with at most two fields; never ask for optional details. A form with a single question sends as soon as the option is picked, with no extra button.

## Consequences

- No contract change. Rollback: remove the guard call in `ChatService` and prompt rules 13 and 14.
- Residual risk: a rephrased attempt passes the guard; it still meets the allowlist, the permissions and the card. A false positive blocks a legitimate message; the user can reword it.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Input guard before the provider; simple-flow prompt rules | Project owner | architect | 2026-11-15 | Message "ركز على سهولة النظام ... وكمان محتاج طبقة حماية ان المستخدم مينفذش اوامر خارجية او تخطي للمساعد" |
