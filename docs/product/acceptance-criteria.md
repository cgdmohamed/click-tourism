# Acceptance Criteria — Tourism ERP

Status: Draft. Every criterion is observable and testable by QA or automated tests. Criteria map to FRs in `PRD.md`; the delivery backlog is in `epics.md` / `issues/`.

Format: **AC-&lt;module&gt;-&lt;n&gt;**. Unless stated, "user" means an authenticated user with the stated permission.

## Core platform

| ID | Criterion |
|---|---|
| AC-CORE-001 | A valid email/password returns a session/token; an invalid password returns 401 without revealing whether the email exists; 5 failed attempts within the window are rate-limited. |
| AC-CORE-002 | A deactivated user cannot authenticate; an existing token is rejected on the next request. |
| AC-CORE-003 | Every protected endpoint returns 401 without a token and 403 when the permission is missing; there is an automated test enumerating routes for policy coverage. |
| AC-CORE-004 | A user assigned to branch A cannot list, read, create, or update branch B records; the API returns 403/404 consistently, and lists exclude branch B. |
| AC-CORE-005 | A super-admin can switch the active branch; data written thereafter carries the selected branch; audit records the branch. |
| AC-CORE-006 | Installation settings persist base currency, timezone, locale, fiscal-year start, and branding; changing them is audited; changing base currency after postings exists is blocked. |
| AC-CORE-007 | Enabling/disabling a feature toggle hides the module in navigation and returns 403/404 from its endpoints without deleting data; re-enabling restores access. |
| AC-CORE-008 | Every create/update/delete/post/reverse/login/export writes an append-only audit event with actor, timestamp (UTC), request ID, and redacted before/after. |
| AC-CORE-009 | Document sequences produce unique numbers per type/branch/year; concurrent creation does not duplicate a number. |
| AC-CORE-010 | A document number, once used, is never reused; the configured gap policy is observable in tests. |
| AC-CORE-011 | Attachments reject disallowed type/size; download requires permission; files are not publicly listable. |
| AC-CORE-012 | `/health` returns 200 with DB/Redis/queue status; it exposes no secrets. |

## Money and FX

| ID | Criterion |
|---|---|
| AC-MONEY-001 | Creating a monetary value from a float is impossible through the public API/domain type (static analysis/test proves no float casts). |
| AC-MONEY-002 | Amounts round half-up at line level; a 3-line 33.335 split produces totals that equal the document total with an explicit rounding line when needed. |
| AC-MONEY-003 | A foreign-currency document stores rate, rate date, transaction amount, and base amount; later rate changes do not alter stored values. |
| AC-MONEY-004 | Zero or negative FX rates are rejected; a missing rate for a foreign-currency document is rejected with a clear error. |
| AC-MONEY-005 | Settlement at a different rate posts the computed realized FX gain/loss and the AR/AP balance closes to zero. |

## Accounting

| ID | Criterion |
|---|---|
| AC-FIN-001 | Chart of accounts supports nesting to at least 5 levels; a child inherits the parent type; a non-postable parent rejects direct posting. |
| AC-FIN-002 | Deactivating an account with postings is blocked; deactivating an unused account removes it from new-entry selection but preserves history. |
| AC-FIN-003 | A journal entry with unequal debits/credits is rejected in transaction currency and independently in base currency. |
| AC-FIN-004 | Posting a balanced entry into an open period succeeds; the same into a closed period is rejected with period details. |
| AC-FIN-005 | A posted entry cannot be edited or deleted via any endpoint (direct or bulk); attempts return 409/403. |
| AC-FIN-006 | Reversal creates a new posted entry with all sides inverted and `reversal_of_id` set; a second reversal of the same entry is rejected. |
| AC-FIN-007 | A reversal into a closed period requires the authorized correction flow and is audited with a reason. |
| AC-FIN-008 | Opening-balance import posts a balanced batch once; re-posting the same batch is rejected; totals reconcile to the imported file. |
| AC-FIN-009 | Cash/bank transfer posts two legs in one transaction; an interrupted transfer leaves no partial posting. |
| AC-FIN-010 | A cross-currency transfer posts both legs and the FX difference to the configured gain/loss accounts. |
| AC-FIN-011 | Expense approval above threshold requires an approver different from the creator when segregation is enabled. |
| AC-FIN-012 | AR/AP aging totals equal the control-account balances in the trial balance for the same date/branch. |
| AC-FIN-013 | Period close rolls P&L to retained earnings and blocks postings; reopening requires the configured permission and reason and is audited. |
| AC-FIN-014 | Every automatic posting carries `source_type`/`source_id`; a report can trace any ledger line back to its business document. |
| AC-FIN-015 | Deleting a business document never orphans a posted journal entry; the operation is blocked or the entry is reversed. |

## Customers and suppliers

| ID | Criterion |
|---|---|
| AC-PARTY-001 | Customer create/update validates required fields, normalized phone/email, and duplicate detection; the duplicate warning is actionable. |
| AC-PARTY-002 | A customer with postings cannot be deleted, only deactivated; deactivated customers cannot be selected for new bookings. |
| AC-PARTY-003 | Supplier categories include flight, hotel, transport, visa, agent, other; category filters work in lists and reports. |
| AC-PARTY-004 | Customer/supplier statements show opening balance, documents, allocations, and closing balance for a date range, matching the ledger. |
| AC-PARTY-005 | Branch-scoped users cannot see parties owned by other branches unless granted cross-branch permission. |
| AC-PARTY-006 | Merging duplicate parties reassigns references and is fully audited; no postings are lost. |

## Bookings

| ID | Criterion |
|---|---|
| AC-BOOK-001 | A booking with customer, branch, owner, dates, pax, and ≥1 item can be created in draft; missing required fields return field-level errors. |
| AC-BOOK-002 | Each service type (flight/hotel/transport/visa/program/other) can be added with supplier, dates, quantity, cost, sell, and tax profile. |
| AC-BOOK-003 | Only allowed lifecycle transitions succeed; an illegal transition returns 409 and does not change state. |
| AC-BOOK-004 | Confirmation freezes the FX rate and pricing snapshot; subsequent rate changes do not alter the confirmed booking. |
| AC-BOOK-005 | Booking totals equal the sum of item totals minus discounts plus tax; margin = sell − cost − commission (per policy). |
| AC-BOOK-006 | A confirmed booking cannot be deleted; cancel/close paths are available instead. |
| AC-BOOK-007 | Editing sell/cost after confirmation requires permission and creates an audit record; existing postings are not silently altered. |
| AC-BOOK-008 | Cancellation computes fee/refund per the snapshotted policy; refund above the ceiling is rejected. |
| AC-BOOK-009 | Profitability by booking, item, customer, agent, sales user, and branch matches the underlying documents for a fixed dataset. |
| AC-BOOK-010 | Booking confirmation is idempotent: repeating the action returns the same result and does not double-post. |

## Payments and refunds

| ID | Criterion |
|---|---|
| AC-PAY-001 | A receipt can be recorded in any enabled currency; it stores the FX snapshot and posts to the selected cash/bank account. |
| AC-PAY-002 | Partial payments accumulate; the booking balance decreases correctly and the booking closes only when settled. |
| AC-PAY-003 | Allocation sum cannot exceed the payment amount; overpayment is stored as a customer advance and appears on the statement. |
| AC-PAY-004 | Replaying a payment request with the same `Idempotency-Key` returns the original receipt and creates no second posting. |
| AC-PAY-005 | Two concurrent allocations cannot overdraw the same booking balance (row lock/transaction test). |
| AC-PAY-006 | A refund cannot exceed net collected; a valid partial refund posts and updates the balance. |
| AC-PAY-007 | A refund above the approval threshold requires a different approver when segregation is enabled. |
| AC-PAY-008 | Supplier obligation from a booking cost is created per policy; supplier payment settles it and posts AP correctly. |
| AC-PAY-009 | A supplier payment without an obligation is recorded as an advance and can be applied later. |
| AC-PAY-010 | Payment/refund/supplier-payment documents render in AR/EN with the installation branding and correct amounts. |

## Tax

| ID | Criterion |
|---|---|
| AC-TAX-001 | Tax profiles support rate, inclusive/exclusive, effective dates, and sales/purchase accounts; invalid rates are rejected. |
| AC-TAX-002 | A booking item/expense snapshots the tax rate and amount at the time of the document; later profile edits do not change history. |
| AC-TAX-003 | Tax-inclusive and tax-exclusive calculations produce the expected base/tax/total for a table of known cases. |
| AC-TAX-004 | Tax summary by period/branch reconciles to the tax control accounts in the trial balance. |
| AC-TAX-005 | A zero-rated or tax-exempt profile produces no tax posting and is reported separately. |

## Commissions

| ID | Criterion |
|---|---|
| AC-COMM-001 | A rule can be scoped to booking/customer/agent/sales user/branch with basis and effective dates; overlapping rules resolve by the documented precedence. |
| AC-COMM-002 | An accrual is created once per rule/basis document at the configured trigger; replay does not duplicate it. |
| AC-COMM-003 | An approved accrual cannot be edited; cancellation creates a reversal/adjustment with audit. |
| AC-COMM-004 | Payout posts `Dr payable / Cr cash-bank` and marks the accrual paid; the payable report reaches zero. |
| AC-COMM-005 | Commission reports by booking/customer/agent/sales/branch/period match accrual and payout records. |

## Reports

| ID | Criterion |
|---|---|
| AC-RPT-001 | Trial balance debits equal credits for any period/branch; totals match the sum of posted lines. |
| AC-RPT-002 | Income statement and balance sheet reconcile: net income flows to equity; assets = liabilities + equity for the same date. |
| AC-RPT-003 | GL detail opening + movements = closing for each account. |
| AC-RPT-004 | AR/AP aging buckets sum to the control-account balance and expose drill-down to documents. |
| AC-RPT-005 | Every report respects branch scope; a branch-A user receives no branch-B rows, including in exports. |
| AC-RPT-006 | PDF exports contain branding, period, filters, page numbers, and correct Arabic shaping/RTL; CSV/Excel exports match on-screen filters. |
| AC-RPT-007 | A large export runs asynchronously, shows progress, and produces a complete file only on success. |

## CRM

| ID | Criterion |
|---|---|
| AC-CRM-001 | A lead can be created manually or imported with source, branch, stage, contact, and consent fields; validation errors are row/field specific. |
| AC-CRM-002 | Assignment rules route a new lead to the expected owner for a table of branch/source/load cases. |
| AC-CRM-003 | A lead with no activity for the configured window (default 24h) is reassigned by the scheduler, notified, and audited; activity resets the clock. |
| AC-CRM-004 | Reassignment respects the business calendar/timezone and never assigns to a deactivated user. |
| AC-CRM-005 | Stage transitions are configurable; won/lost stages behave consistently in pipeline metrics. |
| AC-CRM-006 | Converting a lead creates/links a customer and optionally a booking exactly once; a second conversion is blocked. |
| AC-CRM-007 | Duplicate leads by normalized phone/email within the configured window are flagged, not silently duplicated. |
| AC-CRM-008 | Pipeline metrics by stage/owner/source/branch/period match the lead data. |
| AC-CRM-009 | Opt-out leads are excluded from outbound messaging. |

## Integrations

| ID | Criterion |
|---|---|
| AC-INT-001 | A Meta webhook with an invalid signature is rejected with 403 and logged; a valid one creates a routed lead. |
| AC-INT-002 | Replaying the same Meta event ID returns 200 and creates no duplicate lead/message. |
| AC-INT-003 | A malformed webhook payload is logged with the error, does not crash the endpoint, and can be replayed after a fix. |
| AC-INT-004 | WhatsApp inbound messages are persisted, linked to the lead/conversation, and appear in the timeline in order. |
| AC-INT-005 | Outbound WhatsApp send uses the configured template/text, records the provider message ID, and retries on transient failure without duplicate sends. |
| AC-INT-006 | Integration credentials are write-only in the UI/API after save; they never appear in logs, audit snapshots, or API responses. |
| AC-INT-007 | Disabling an integration stops processing and returns the documented response without deleting history. |

## Import / export

| ID | Criterion |
|---|---|
| AC-DATA-001 | A template can be downloaded; an import with a missing required column fails validation with a clear report and commits nothing. |
| AC-DATA-002 | A dry-run reports row-level errors without writing data. |
| AC-DATA-003 | A valid import commits atomically; re-running the same batch with the same idempotency key does not duplicate records. |
| AC-DATA-004 | Arabic headers/content, BOM, Excel dates, and mixed currencies are handled or rejected with a precise message. |
| AC-DATA-005 | Large imports/exports run in the queue with progress and downloadable results. |
| AC-DATA-006 | Exports never include rows outside the actor's branch scope or permissions. |

## UI / i18n / accessibility

| ID | Criterion |
|---|---|
| AC-UI-001 | The app shell renders navigation, branch switcher, locale switcher, search, and user menu on desktop and mobile. |
| AC-UI-002 | Switching to Arabic sets `dir="rtl"`, mirrors layout/navigation, and renders Arabic text without missing glyphs; switching back restores LTR. |
| AC-UI-003 | All user-visible strings come from translation files; a scan finds no hardcoded UI strings. |
| AC-UI-004 | Lists paginate/filter server-side; empty, loading, error, and permission-denied states are distinct and actionable. |
| AC-UI-005 | Forms show field-level validation, associate errors with inputs, and preserve input on failure. |
| AC-UI-006 | Core flows are keyboard operable and pass an automated accessibility check (WCAG 2.1 AA target). |
| AC-UI-007 | Layouts are usable at 360, 768, and 1280 px without horizontal overflow of primary actions. |
| AC-UI-008 | Screens use only supplied design-system components/tokens; a review checklist confirms no parallel UI kit. |

## Security and non-functional

| ID | Criterion |
|---|---|
| AC-SEC-001 | No secret appears in the repository, build artifacts, logs, or API responses (secret scan passes). |
| AC-SEC-002 | Sensitive fields are redacted for unauthorized actors in API responses, audit snapshots, and exports. |
| AC-SEC-003 | Rate limiting, secure headers, and TLS-only cookies/tokens are verified by configuration tests. |
| AC-NFR-001 | Reference-scale seed data meets the p95 API target in the performance test. |
| AC-NFR-002 | Backup → restore on a clean host reproduces data and reports identically; migration rollback is verified. |
| AC-NFR-003 | All timestamps are stored in UTC and displayed in the configured timezone across DST boundaries. |
| AC-NFR-004 | A clean install completes from documented steps with no manual database edits. |
