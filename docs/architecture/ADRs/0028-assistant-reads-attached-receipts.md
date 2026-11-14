# ADR-0028 - Assistant reads an attached receipt, transfer slip or invoice photo (amends ADR-0021 and ADR-0023)

- **Status:** Accepted (project owner request 2026-11-14: attach a receipt, transfer or payment proof and let the assistant act on it). In-product assistant only.
- **Date:** 2026-11-14
- **Related:** ADR-0021 (record attachments and extraction), ADR-0023/0027 (prepare, confirm, run as the user), ADR-0018 (data-processing notice).

## Context

Users receive transfer receipts, payment slips and supplier invoices as photos or PDFs. ADR-0021 attaches documents to records and extracts fields for review, but the chat could only read text, so recording a payment from a receipt meant retyping it.

## Decision

1. **A file rides with one message.** `POST /assistant/uploads` takes one PDF, JPEG, PNG or WebP (real type from the bytes, at most 10 MB, no PDF active content: the checks of ADR-0021). The chat request lists up to three upload ids (`attachments`); an unknown, expired or foreign id is a 422 before any provider call.
2. **The model reads it, no separate extraction step.** The last user message is sent to the provider with the file as an image or PDF part, plus a note that the files are untrusted data. The prompt (v4, rule 11) tells the model to say what it read and what is unclear, never invent values, find the matching customer, supplier, booking or invoice with the read tools (ask when none or several fit) and prepare ONE action from the existing prepare catalogue.
3. **Still prepare, confirm, run.** Nothing changes until the user confirms the card (ADR-0023/0027, second confirmation for post, pay, void, delete). The card shows the values; the user compares them with the file.
4. **Short-lived and private.** The file is stored on the private disk under a random name, readable only by its uploader, for `assistant.upload_ttl_hours` (default 6), at most 20 live files per user, deleted by `assistant:prune-history`. It is not attached to any record (the prompt tells the user to attach it on the record's page if it must be kept). The saved chat keeps the file name only.
5. **No redaction of files.** The provider receives the file as it is. The data-processing notice already covers documents sent to the provider; the chat shows a one-line reminder when a file is attached.
6. **Not included:** reading several documents as a batch, creating records from a file without a card, spreadsheet or CSV files, keeping the file as the record's attachment automatically.

## Consequences

- Contract: `POST /assistant/uploads`, `attachments` on the chat request, new table `assistant_uploads` (additive), setting-free (env/config `assistant.upload_ttl_hours`), audit `assistant.file_uploaded` and `files` count in `assistant.chat`.
- Residual risk: a misread amount or reference on a real receipt that the user confirms without checking; provider behaviour on images and PDFs is unverified against the live API (same as ADR-0021). Rollback: remove the route and the prompt rule; drop the table; no other data depends on it.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | The assistant reads an attached receipt/slip/invoice and prepares a confirmed action from it | Project owner | architect | 2026-11-14 | Owner question "يعني مثلا رفع ايصال تحويل او دفع او غيره" asking for exactly this |
