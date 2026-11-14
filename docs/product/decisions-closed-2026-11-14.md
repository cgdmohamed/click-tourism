# Decisions closed - 2026-11-14

Recorded from the project owner's answers in the implementation session (branch `claude/clever-sagan-89spcf`). These are **owner** decisions. Where a decision was marked accountant-gated, no named accountant's sign-off is recorded by this file.

| # | Decision | Owner's words | Effect |
|---|---|---|---|
| DEC-008 | No FX rate source and no automatic revaluation | "لا حاجه لمصدر سعر الصرف" | Realized differences only; the revaluation screens exist but no rate source is configured |
| DEC-004 | Commission triggers: accepted as recommended (configurable, default fully collected) | "اوافق على التوصية" | No change to code; accountant sign-off on accounts and reversals still to be recorded |
| DEC-014 | Branches are independent, no inter-branch posting | "كل فرع مستقل بذاته لا ترحيل بين الفروع" | Supplier payment from another branch's cash account stays refused |
| DEC-025 | Negative margin: warning only | "تنبيه فقط" | Setting `accounting.negative_margin_requires_approval` now defaults to `false` (migration `2026_11_28_000100` switches a stored `true`); the booking form and list warn; the approval flow still exists if the setting is turned on |
| DEC-028 | Booking and invoice remain separate | "منفصلين" | None |
| DEC-036 | Voucher tax is recorded as document metadata only, no automatic posting of tax lines; corrections by a linked reversal voucher; no in-transit account | "اختار أ" (option A of the explanation) | Current behaviour kept; accountant sign-off not recorded here |
| ADR-0023 | Accountant's view on assistant-created drafts (invoices, expenses) | "نعم" | Recorded as the owner's yes; drafts post nothing and a person submits and approves them in the normal screens |

Deferred by the owner: OAuth for MCP (claude.ai and ChatGPT connectors), malware scanning of attachments, live trials of the MCP clients (after the project is complete).
