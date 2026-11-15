# ADR-0035 - Receipt voucher (سند قبض) PDF (amends ADR-0014)

- **Status:** Accepted (project owner request 2026-11-15: "ابنِ سند القبض PDF", after the assistant collection flow offered only the invoice for download).
- **Date:** 2026-11-15

## Context

ADR-0014 renders invoices and credit notes server-side. A customer receipt had no document of its own: the accountant had nothing to hand to the customer or file with the transfer.

## Decision

1. **`GET /payments/{payment}/pdf`** (permission `payments.view`, branch-scoped, throttled like the other exports, `language` = ar, en or bilingual, default from `documents.default_language`) returns the receipt voucher as a PDF attachment. Audited as `payment.exported` (no content).
2. **Same look as the invoice.** `ReceiptDocument` extends `InvoiceDocument` and reuses its letterhead (logo, company name and details from Settings), interface fonts, Arabic shaping and bilingual labels. The shared rendering moved into `InvoiceDocument::render()`; nothing about the invoice output changed.
3. **Content:** voucher number (RV-...), who it was received from (name, customer code, phone), date, payment method, receiving account (name, bank, last four), linked booking, the amount received (and, for a foreign-currency receipt, the stored rate and the base-currency equivalent), what it was applied to (each invoice with its amount, the booking, or "unallocated advance"), and prepared-by / received-by signature lines. A cancelled receipt carries a cancelled stamp and a not-valid note.
4. **Where it appears:** the receipts list row menu, and the assistant's confirmed collection card (one button per receipt the collection created).
5. **Not included:** the amount in words, numbering of printed copies, e-mailing the voucher.

## Consequences

- Contract: one GET route, `receipts` on the assistant action card. No schema change. Rollback: remove the route and the buttons.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Receipt voucher PDF | Project owner | architect | 2026-11-15 | Message "ابنِ سند القبض PDF" |
