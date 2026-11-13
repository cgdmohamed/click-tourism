# ADR-0021 - Document attachments and AI-assisted extraction (GAP-CORE-03)

- **Status:** **Accepted** (project owner decision 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). The requirement itself (attachments with AI-assisted extraction and mandatory review) was approved the same day.
- **Date:** 2026-11-13
- **Related:** ADR-0017/0018/0019 (assistant provider, data-processing notice, key in Settings), ADR-0005 (branch scope), `docs/deployment/attachments.md`, `docs/architecture/openapi.yaml`, `docs/architecture/threat-model.md`.

## Context

The module matrix listed attachments but nothing existed. The approved requirement asks for private documents on customers, bookings, invoices, payments, expenses and suppliers, with optional automatic reading whose result is reviewed by a person before anything is saved.

## Decision

1. **Storage:** files are written to a private disk (`ATTACHMENTS_DISK`, default `local`, never `public`) under random names (`attachments/YYYY/MM/<ulid>.<ext>`). There is no public URL: files are streamed by `GET /attachments/{id}/download|preview` with `nosniff`, a sandbox CSP and `Cache-Control: private, no-store`.
2. **Checks on the bytes:** PDF, JPEG, PNG, WebP only, detected from the content (not the name or the declared type), at most 10 MB and 10 files per request, images must decode, PDFs containing `/JavaScript`, `/JS`, `/Launch`, `/OpenAction`, `/AA` or `/EmbeddedFile` are refused. No antivirus scan is performed (none is installed); this is stated in the deployment guide.
3. **Access:** a document is reachable only by a user who may view its record (permission and branch) and holds `attachments.view|upload|delete|extract`. Passports, visas and travel documents are personal data: they need `attachments.sensitive`, are hidden from lists for everyone else, and cannot be classified into by users without it.
4. **No duplicates:** the same bytes for the same record return the stored document (`duplicate: true`) under a row lock.
5. **Delete is soft:** the document leaves the record; the file stays for audit. Uploads, previews, downloads, edits and deletes are audited; extraction audit entries carry field names, never values.
6. **Extraction is a proposal.** `POST /attachments/{id}/extraction` asks the provider (the OpenAI key from ADR-0019) to read a fixed list of fields per category (`ExtractionSchema`; a passport yields five identity fields only). It needs the new setting `attachments.extraction_enabled` (default false), the accepted data-processing notice and a key. Values that are missing, unreadable or in the wrong format are flagged, never guessed. Proposed and confirmed values are encrypted at rest. A provider failure answers `status: failed` and the user continues by hand.
7. **Review is mandatory and creates nothing.** `POST /attachment-extractions/{id}/confirm` stores the reviewer's values with the document; it creates no invoice, payment, customer change or journal entry and approves nothing. Records are created through their own forms and rules; a confirmed supplier-invoice extraction can open the invoice form pre-filled, and the user still chooses the supplier and saves it like any manual entry.
8. **Contract:** new tables `attachments` and `attachment_extractions`; new routes under `/attachments` and `/attachment-extractions`; permissions `attachments.view|upload|delete|extract|sensitive`; setting `attachments.extraction_enabled`; feature flag `attachments_extraction_enabled`.

## Consequences

- Residual risks: no malware scanning; the provider receives the whole document when extraction is used (the notice says so); an `APP_KEY` change makes stored proposals unreadable (they are only proposals; confirmed values are lost too, the documents themselves are unaffected).
- Provider behaviour is unverified: the PDF `file` content part and `json_object` response follow the documented Chat Completions shapes but were not run against the live API.
- Rollback: turn the setting off; the tables are additive and can be dropped; the permissions can be removed from roles.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Tables `attachments`, `attachment_extractions` and the file storage rules (items 1, 2, 4, 5) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0021 (PR #78) |
| 2 | Permissions, access rules and personal-data handling (item 3) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0021 (PR #78) |
| 3 | Extraction: setting, routes, review rule and data sent to the provider (items 6, 7) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "اوافق" in answer to the request to approve ADR-0021 (PR #78) |
