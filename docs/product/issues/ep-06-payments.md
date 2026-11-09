# EP-06 — Payments, Refunds, and Supplier Settlement

**Blocked by:** EP-03 posting engine, EP-05 bookings, DEC-005/006/007/017.

---

## TE-029 — Customer receipts, allocations, and advances

**Goal:** Collect money against bookings safely.
**Scope in:** Receipt creation in any enabled currency, cash/bank selection, FX snapshot, allocation to one/many bookings, advance handling, list/detail, receipt document data, posting via accounting contract.
**Scope out:** Gateway capture.
**Dependencies:** TE-016, TE-020, TE-026.
**API contract changes:** `/payments`, `/payments/{id}/allocations`.
**Acceptance criteria:** AC-PAY-001..005, AC-PAY-010.
**Test cases:** partial payments, overpayment → advance, cross-currency, allocation over balance blocked, concurrent allocation, closed period, branch isolation.
**Owned files:** `backend/app/Modules/Payments/Receipts`, migrations, tests.
**Reviewer:** Accountant.

---

## TE-030 — Refunds and cancellation settlement

**Goal:** Controlled refunds linked to original collections.
**Scope in:** Refund request, ceiling computation, approval thresholds/segregation, posting, link to original receipt/booking, refund document data, status tracking.
**Scope out:** Gateway refunds.
**Dependencies:** TE-029, TE-026.
**API contract changes:** `/refunds`, `/refunds/{id}/approve`.
**Acceptance criteria:** AC-PAY-006, AC-PAY-007, AC-BOOK-008.
**Test cases:** refund > collected blocked, partial refund, refund after period close, approver = creator when segregation on, cross-currency refund, duplicate request.
**Owned files:** `backend/app/Modules/Payments/Refunds`, tests.
**Reviewer:** Accountant + security.

---

## TE-031 — Supplier obligations, invoices, and payments

**Goal:** Track and settle supplier costs.
**Scope in:** Obligation creation from booking items (per DEC-002), supplier invoice matching, partial payments, supplier advances, credit notes, payment posting, AP impact, list/filter.
**Scope out:** Procurement/PO, OCR.
**Dependencies:** TE-016, TE-020, TE-026, TE-023.
**API contract changes:** `/supplier-obligations`, `/supplier-payments`.
**Acceptance criteria:** AC-PAY-008, AC-PAY-009; AP balance reconciles.
**Test cases:** overpayment → advance, partial invoice, duplicate invoice reference, disputed amount, FX difference, closed period.
**Owned files:** `backend/app/Modules/Payments/Suppliers`, tests.
**Reviewer:** Accountant.

---

## TE-032 — Idempotency and concurrency hardening

**Goal:** Make money actions safe to retry.
**Scope in:** `Idempotency-Key` middleware/store (key + actor + endpoint + request hash + response), replay semantics, conflict on payload mismatch, retention, DB transaction/locking audit across payment/refund/booking actions, load/concurrency tests.
**Scope out:** Webhook idempotency (TE-044/045).
**Dependencies:** TE-029, TE-030, TE-031.
**API contract changes:** Header convention documented in OpenAPI.
**Acceptance criteria:** AC-PAY-004, AC-PAY-005, AC-DATA-003; no double postings under concurrent retries.
**Test cases:** same key/same payload, same key/different payload, expired key, parallel requests, crash between post and response.
**Owned files:** `backend/app/Modules/Core/Idempotency`, payment tests.
**Reviewer:** Architect + security.
