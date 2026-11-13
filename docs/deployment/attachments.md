# Document attachments and automatic reading

## What it does
Customers, suppliers, bookings, invoices, payments and expenses carry documents (PDF, JPEG, PNG, WebP up to 10 MB). Users with `attachments.upload` upload several files at once, classify them (passport, visa, booking confirmation, supplier invoice, payment receipt, bank-transfer confirmation, travel document, contract, other) and add notes; anyone allowed to see the record can preview and download them. Passports, visas and travel documents need `attachments.sensitive`.

## After upgrading
1. `php artisan migrate`, then `php artisan roles:sync` so roles receive the new `attachments.*` permissions.
2. Files live in `storage/app/private/attachments` (disk `ATTACHMENTS_DISK`, default `local`). **Include this directory in backups** together with the database; a restored database without it shows documents that cannot be opened.
3. Optional: `ATTACHMENTS_MAX_KB` (default 10240), `ATTACHMENTS_MAX_FILES` (10), `ATTACHMENTS_EXTRACTION_MAX_KB` (8192).
4. PHP and nginx must allow the upload size: `upload_max_filesize` and `post_max_size` of at least 12M, and `client_max_body_size` of at least 12m.

## Automatic reading (optional, off by default)
Settings > Assistant: accept the data-processing notice, save the provider key, then turn on "Automatic document reading". Users with `attachments.extract` then see a reading action on supported documents. The document itself is sent to the provider. The result is a proposal shown next to the original; unclear or missing values are marked. Nothing is created or posted by it: confirming stores the reviewed values with the document, and an invoice or payment is entered through its normal form. If reading fails the user keeps working by hand.

## Limits and honest caveats
- No antivirus scan is performed. PDFs with active content (JavaScript, launch actions, embedded files) are refused.
- Reading is UNVERIFIED against the live provider (PDF input and JSON answers follow the documented shapes).
- Deleted documents disappear from the record but the file is kept for audit.
- An `APP_KEY` change makes stored proposals unreadable.
