# PROMPT 26 — Customer & Supplier Invoices (AR/AP)

You are the Invoicing and Accounting Integration Agent working on an EXISTING self-hosted Tourism ERP product (Laravel API, React UI, PostgreSQL). The product has one codebase deployed separately to each customer's server. This is not SaaS and must not introduce tenancy.

## First: inspect, do not assume
Read AGENTS.md, product specification, existing DB schema/migrations, Accounting Engine, Bookings, Customers, Suppliers, Payments, reports, permissions, UI design-system tokens, and tests. Report what exists, what is missing, and compatibility constraints BEFORE making changes. Preserve existing APIs and posted journal data; do not create a second ledger, balance table or accounting logic. If relevant features already exist, extend them without duplicating.

## Objective
Implement a complete invoice lifecycle for customer invoices (accounts receivable), supplier invoices (accounts payable), credit notes, and their integration with bookings, supplier costs, payment allocation, and immutable double-entry posting.

## Core requirements
- Customer sales invoices: draft, validate, approve if configured, issue/post, partial payment, fully settled, overdue, cancelled only if unposted; credit note/reversal for posted documents.
- Supplier purchase invoices/bills: vendor invoice number, issue/due dates, currency, billable services, supporting documents, tax/discount lines as configurable policies, partial settlement, vendor credit notes.
- Line items can link to bookings and booking service components (air, hotel, transport, visas, packages); allow unlinked manual expense/revenue invoices according to permissions.
- Separate invoice header and invoice lines. Store immutable issue-time currency/exchange-rate/tax/price snapshots for posted documents. Use DECIMAL/NUMERIC, never floating-point.
- Configurable unique per-series and branch document numbering, idempotent issuance and posting, concurrency-safe sequences; prevent reissuing same business document through retries.
- Generate PDF printable invoices consistent with existing design system, with logo, branch/company, bill-to, line items, totals, outstanding amount and dates. Support AR/EN and RTL/LTR. Invoice templates must remain generic and configurable, not client hardcoded.
- Payment allocation can settle one or multiple invoices; support partial settlements, overpayments as unapplied credits according to approved rules, receipts, refunds and multi-currency differences.
- Posted invoice creates accounting entries ONLY through existing Accounting Engine contracts and posts to actual customer/supplier subaccounts. No invoice-local truth for ledger balances. Tax accounting rules and revenue recognition must be confirmed by finance product owner/accountant; do not silently assume service delivery date equals invoice revenue date.
- Maintain audit history, permissions, edit locks, period-close checks, and no hard deletion of financial documents; support draft edit and void/reversal workflow.
- Expose searchable/filterable invoices UI (by branch, counterparty, payment status, issue/due date), create/edit/details/print, allocations and attached booking links.

## Required tests
- Booking with 3 services & 2 suppliers -> 1 customer invoice + 2 supplier invoices; posting balances debits/credits and subaccounts.
- Partial receipt/payment, multiple allocations, discount/tax rounding, duplicate request, concurrent numbering, credit note and refund, closed period, unauthorized branch, supplier duplicate invoice number policy, different currencies.
- Financial statement and counterparty statement reconciliation after each operation.

## Delivery
1. Inspection findings and gap analysis; 2. proposed schemas/API/events and posting mapping for approval; 3. implement narrow changes; 4. migrations, UI, tests, docs; 5. execute tests and list exact outcomes; 6. list changed files, compatibility/backfill steps, unresolved risks.
Do not mark complete without verified tests. Do not change production or modify existing posted journal records. If accounting treatment is ambiguous, document it and require accountant approval.
