# EP-12 — Frontend Shell and Module Screens

**Status:** the earlier BLOCKED note is obsolete: the design system is in the repository (`docs/design/DESIGN.md`) and screens are built on it. Per-issue status is on each TE below.

---

## TE-049 — Application shell, navigation, i18n, and RTL

**Goal:** The frame every screen lives in.
**Scope in:** Layout shell, sidebar/topbar, breadcrumbs, global search, branch switcher, locale switcher (AR/EN), user menu, notifications, route guards, error boundaries, i18n infrastructure, `dir` handling, responsive breakpoints, DS adapters.
**Scope out:** Module-specific screens.
**Dependencies:** TE-010 (design system), TE-003, TE-005.
**API contract changes:** None (consumes existing APIs).
**Acceptance criteria:** AC-UI-001, AC-UI-002, AC-UI-003, AC-UI-007.
**Test cases:** locale switch persists, RTL mirroring, route guard by permission, 360/768/1280 layouts, keyboard nav.
**Owned files:** `web/src/shell`, `web/src/i18n`, `web/src/routes`, tests.
**Reviewer:** Frontend lead.
**Status:** done — App shell, i18n, RTL, global search (`SearchTest`).

---

## TE-050 — Screens wave 1: platform, parties, accounting

**Goal:** Deliver core operational screens.
**Scope in:** Login, users/roles, branches, settings, audit, currencies/FX, CoA, journal entries, opening balances, cash/bank/transfers, expenses, customers, suppliers, statements.
**Scope out:** Booking/payment/CRM screens.
**Dependencies:** TE-049, TE-013..TE-024.
**API contract changes:** None (consumes existing APIs).
**Acceptance criteria:** AC-UI-004..008; each screen uses DS components only; forms/validation/permissions per API.
**Test cases:** list/filter/pagination, create/edit validation, permission-denied, RTL, responsive, E2E happy paths.
**Owned files:** `web/src/features/{platform,parties,accounting}`.
**Reviewer:** Frontend lead + product.
**Status:** done — Pages for users, roles, settings, audit, FX, accounts, journal, periods, opening balances, treasury, expenses, customers, suppliers.

---

## TE-051 — Screens wave 2: bookings, payments, tax, commissions, reports, CRM

**Goal:** Deliver commercial and reporting screens.
**Scope in:** Bookings list/detail/form/cancellation, receipts/allocations, refunds, supplier obligations/payments, tax profiles, commission rules/accruals/payouts, report hub/viewer/export, leads board/detail/pipeline config, integrations/webhook log, import/export center.
**Scope out:** Customer/supplier portals.
**Dependencies:** TE-049, TE-025..TE-048.
**API contract changes:** None (consumes existing APIs).
**Acceptance criteria:** AC-UI-004..008; report filters match exports; financial figures formatted with currency/locale.
**Test cases:** booking lifecycle UI guards, payment allocation UI, report filter parity, CRM drag/drop stage with keyboard alternative, RTL/responsive.
**Owned files:** `web/src/features/{bookings,payments,tax,commissions,reports,crm,integrations,data}`.
**Reviewer:** Frontend lead + product.
**Status:** partial — Bookings, payments, commissions, reports, tax and leads pages exist; no lead detail/pipeline or webhook-log screens (GAP-CRM-03, 05).

---

## TE-052 — Print and PDF layouts

**Goal:** Print-ready financial and booking documents in AR/EN.
**Scope in:** Print CSS/layout components, document templates, branding, page breaks, headers/footers, Arabic fonts/shaping, preview.
**Scope out:** Server-side rendering engine (TE-039).
**Dependencies:** TE-049, TE-039.
**API contract changes:** None.
**Acceptance criteria:** AC-RPT-006, AC-UI-007.
**Test cases:** Arabic shaping, long tables, multi-page totals, logo missing, grayscale print.
**Owned files:** `web/src/print`, `resources/views/pdf`.
**Reviewer:** Frontend lead + accountant.
**Status:** done — Invoice PDF and report PDF/print layouts, AR/EN (`InvoiceDocumentTest`, `ReportDocumentRenderingTest`).
