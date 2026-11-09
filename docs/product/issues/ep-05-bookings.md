# EP-05 — Bookings

**Blocked by:** EP-03 posting contract, EP-04 parties, DEC-001/002/025.

---

## TE-025 — Booking creation and multi-service items

**Goal:** Capture a booking with any combination of tourism services.
**Scope in:** Booking CRUD (draft), customer/branch/owner/dates/pax/currency/notes, items with type (flight/hotel/transport/visa/program/other), supplier, description, dates, quantity, unit cost/sell, tax profile, attachments, list/filter/search, clone (optional).
**Scope out:** Quotation versioning, GDS/inventory APIs.
**Dependencies:** TE-022, TE-023, TE-033.
**API contract changes:** `/bookings`, `/bookings/{id}/items`.
**Acceptance criteria:** AC-BOOK-001, AC-BOOK-002; totals derived; branch scope enforced; draft deletable.
**Test cases:** missing required fields, invalid dates (end < start), zero pax, item without supplier where required, branch isolation, currency snapshot.
**Owned files:** `backend/app/Modules/Bookings/Bookings`, migrations, tests.
**Reviewer:** Product + architect.

---

## TE-026 — Lifecycle, snapshots, and cancellation policy

**Goal:** Enforce the booking state machine and freeze commercial terms.
**Scope in:** Status transitions, guards, history, FX/pricing snapshot on confirmation, cancellation policy snapshot, cancellation request/fee computation, no-show, audit/events.
**Scope out:** Refund posting (TE-030).
**Dependencies:** TE-025, DEC-001, DEC-006.
**API contract changes:** `/bookings/{id}/transition`, `/bookings/{id}/cancel`, event catalog.
**Acceptance criteria:** AC-BOOK-003, AC-BOOK-004, AC-BOOK-006, AC-BOOK-008, AC-BOOK-010.
**Test cases:** every allowed/forbidden transition, edit after confirmation, cancel from each state, policy snapshot immutability, duplicate confirmation.
**Owned files:** `backend/app/Modules/Bookings/Lifecycle`, tests.
**Reviewer:** Product + accountant.

---

## TE-027 — Pricing, costing, and profitability

**Goal:** Correct totals and margin analysis.
**Scope in:** Line/document discounts with authority limits, tax integration, totals, cost totals, commission effect, margin per booking/item/customer/agent/sales/branch, profitability query/API.
**Scope out:** Dynamic packaging, yield management.
**Dependencies:** TE-026, TE-034.
**API contract changes:** `/bookings/{id}/profitability`, `/reports/profitability` (query service).
**Acceptance criteria:** AC-BOOK-005, AC-BOOK-009, AC-COMM-005 (profit part).
**Test cases:** discount over limit, tax-inclusive/exclusive, multi-currency cost, negative margin, rounding residual.
**Owned files:** `backend/app/Modules/Bookings/Pricing`, tests.
**Reviewer:** Accountant.

---

## TE-028 — Booking documents and voucher data

**Goal:** Produce confirmation/voucher/invoice-style documents.
**Scope in:** Document data assembly (customer, items, dates, totals, terms, branding), AR/EN templates, print-ready layout contract, permissions.
**Scope out:** Final PDF rendering engine (TE-039).
**Dependencies:** TE-026, TE-039 contract, DEC-028/029.
**API contract changes:** `/bookings/{id}/documents`.
**Acceptance criteria:** AC-RPT-006 (booking documents), correct amounts and Arabic shaping.
**Test cases:** missing logo, long descriptions, multi-page, RTL/English, cancelled booking watermark.
**Owned files:** `backend/app/Modules/Bookings/Documents`, templates, tests.
**Reviewer:** Product + frontend.
