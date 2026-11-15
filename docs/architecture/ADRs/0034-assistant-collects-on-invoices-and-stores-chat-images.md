# ADR-0034 - The assistant collects on an invoice, stores the chat image with it, and can cancel a collection (amends ADR-0023, ADR-0028, ADR-0033)

- **Status:** Accepted (project owner request 2026-11-15: the accountant attaches a document and says "record this collection from customer X"; the assistant records the customer, records the collection, attaches the document with the invoice, offers the invoice for download and asks whether any detail should change).
- **Date:** 2026-11-15

## Context

The assistant could record a payment for a customer (advance or against a booking) but not collect on an invoice, never stored a chat file with a record (ADR-0028), and could not undo a collection.

## Decision

1. **`erp_invoice_collect`.** Prepares a collection on an ISSUED customer invoice (confirm card, `pay` risk, second confirmation like every money action). The route is the application's own `POST /invoices/{id}/collections` (receipt, application to the invoice, ledger entry in one transaction, the user's own permissions and idempotency). The card names the invoice, customer (with code), amount (default: the whole outstanding), account, collection date, what remains afterwards and the files that will be stored. The payment method is the account's own, else one of the requested type with no fixed account; with none set up the assistant says an administrator must add one in Settings. A foreign-currency invoice needs the exchange rate. A draft, unissued or settled invoice is refused with the reason.
2. **Chat images are stored with the invoice, only when the user confirms.** `attachments` carries the upload ids; at prepare they must be the user's own, unexpired, JPG or PNG (Word and Excel cannot be stored on a record) and the user must hold `attachments.upload`. After the collection succeeds they are stored through the normal `AttachmentService` (same checks, hashing, duplicate handling) with the category `payment_receipt`, `bank_transfer` or `other`. A file that cannot be stored is counted in the card (`attached.failed`); it never undoes the collection. The chat file itself is still deleted after a few hours.
3. **Download.** The confirmed card offers the invoice PDF through the existing `GET /invoices/{id}/pdf`. A receipt-voucher PDF does not exist yet and is not part of this decision.
4. **Change = cancel and record again.** `erp_payment_cancel` (confirm card, `void` risk, needs `payments.reverse`) cancels a recorded customer payment by its receipt number with a reason. If the receipt is applied to an invoice the same confirmation first undoes that application (`DELETE /invoice-applications/{id}`, needs `payments.create`) and then cancels, inside one transaction: a refusal at either step leaves everything as it was. After a collection or a cancellation is confirmed the assistant answers in one sentence and asks whether any detail (amount, account, date, attachment) should change; correcting means a new card.

## Consequences

- Contract: two tool names, `attached` on the action card. No schema change. Rollback: remove the two tools; stored attachments are ordinary record attachments.
- Residual risk: the model may pick the wrong open invoice when a customer has several (prompt rule 19 requires a form to choose; the card shows the invoice number and remaining amount before Confirm). Passport photos are sent to the provider like any chat file (ADR-0028).

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Assistant collects on an invoice, stores the chat image with it, offers the PDF, asks about edits, cancels a collection | Project owner | architect | 2026-11-15 | Message "... المحاسب يقوله اعملي التحصيل ده من العميل الفلاني فيقوم المساعد يعمل التحصيل ويسجل العميل ويرفق المستند ... ويسأله اذا عايز يعدل اي تفصيلة" |
