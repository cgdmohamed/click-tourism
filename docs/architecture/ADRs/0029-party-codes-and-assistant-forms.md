# ADR-0029 - Unique codes for customers and suppliers, and a form the assistant uses to ask (amends ADR-0023)

- **Status:** Accepted (project owner approval 2026-11-15: "تمام موافق ابدا بيهم" to the proposals of a unique customer/supplier code and evals, with a form of radio buttons and checkboxes so the user chooses).
- **Date:** 2026-11-15
- **Related:** ADR-0023/0027 (prepare, confirm, run), ADR-0028 (attached receipts).

## Context

Asked to "record an invoice for Ahmed", the assistant could pick the first Ahmed. Customers and suppliers had no value that tells two people with the same name apart (phone and tax id are masked from the model), and the assistant could only ask in prose.

## Decision

1. **Codes.** `customers.code` and `suppliers.code` (`C-0001`, `S-0001`): unique, generated on creation by `CodeGenerator`, never mass-assigned, searchable in the lists and the header search, returned by the API. Existing rows are numbered by id in the migration.
2. **Cards print the code.** Confirmation cards show `Name · C-0007`. A lead has no code: a card for a lead whose name is shared says so.
3. **The assistant asks with a form.** A new tool `erp_ask_user` lets the model show a form of up to 4 fields (`radio`, `checkbox`, `text`; 2 to 8 options each). It changes nothing. The server cleans every text, limits sizes and returns the form as `form` in the chat response; the answer returns as the user's next message. Prompt v5 rule 12 requires it when a name or number is ambiguous or a required detail is missing.

## Consequences

- Contract: `code` on Customer and Supplier, `form` on the chat response (additive). Migration is additive with a unique index. Rollback: drop the two columns; remove the tool.
- A saved conversation does not keep the form (the user's answer is kept); an unanswered form disappears on reload.
- Residual risk: the model may still skip the form; the card showing the code is the deterministic check before Confirm.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Unique party codes, `erp_ask_user` form, assistant evals | Project owner | architect | 2026-11-15 | Reply "تمام موافق ابدا بيهم وخلي في الresponse يكون في select زي radio + checkbox" |
