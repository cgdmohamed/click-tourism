# ADR-0033 - Assistant attachments: JPG, PNG, Word and Excel, by picker, drop or paste (amends ADR-0028)

- **Status:** Accepted (project owner request 2026-11-15: the chat should take drag and drop and pasted images, accept only Word, Excel and JPG/PNG images, preview them, allow several and removal before sending, and show a small picture instead of the file name that opens larger on click).
- **Date:** 2026-11-15

## Context

ADR-0028 let a user attach a receipt as PDF, JPEG, PNG or WebP through a paperclip button, up to three files, shown as names in brackets.

## Decision

1. **Accepted types.** JPEG and PNG images, Word (`.docx`) and Excel (`.xlsx`). PDF, WebP, legacy `.doc`/`.xls` and everything else are refused, on the screen and at the server, where the type is read from the bytes. Up to five files of 10 MB per message.
2. **Word and Excel are turned into text by the server.** The provider cannot read them. `OfficeText` opens the file as a zip (nothing is executed), refuses macro-enabled files, any DOCTYPE or entity declaration, too many entries or too large an uncompressed size, and extracts the paragraphs (Word) or three sheets of 200 rows (Excel), at most 20,000 characters. The text is masked like the typed message and goes to the provider as untrusted data.
3. **Three ways in.** The paperclip, drag and drop onto the chat (with a hint while dragging) and paste (a pasted screenshot becomes a file).
4. **Previews.** Each attached image shows as a small picture with a remove button before sending; Word and Excel show as labelled chips. A sent message shows the same pictures instead of the bracketed names, and a click opens the image full size. The previews are local object URLs and are not stored; a conversation opened later shows the names.

## Consequences

- Contract: types and the per-message limit (3 to 5) on `/assistant/uploads` and `/assistant/chat`. No schema change. Rollback: restore the previous type list.
- Receipts as PDF are no longer accepted by the chat (they still are on a record's own attachments). Users send a photo or screenshot instead.
- Residual risk: a crafted docx/xlsx is bounded by the checks above and never executed; extracted text is untrusted data like any attachment.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Chat attachments: JPG, PNG, Word, Excel; drop and paste; previews | Project owner | architect | 2026-11-15 | Message "المساعد الذكي الشات يقبل drag and drop ويقبل لصق الصور ويمنع ملفات غير word + excel + images jpg+png ..." |
