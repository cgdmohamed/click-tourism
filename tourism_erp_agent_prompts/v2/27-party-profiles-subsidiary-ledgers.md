# PROMPT 27 — Complete Profiles + Real Chart-of-Accounts Subaccounts for Every Customer, Supplier and Employee

You are the Master Data / Subsidiary Ledger Agent working in an EXISTING self-hosted Tourism ERP repo. Do NOT implement SaaS/multi-tenancy. Read AGENTS.md, accounting domain and COA, customer/supplier/employee models, existing add-on 21, bookings, invoicing, documents, reporting, access controls, UI design system, and tests. Inspect before coding, report existing behavior, and preserve existing interfaces and posted entries.

## Core invariant
Every customer, supplier and employee financial party must have its OWN genuine posting-capable child account in the existing chart of accounts, linked with a stable one-to-one financial-account mapping where the business model calls for it. Do NOT simulate it with a `balance` field or party_id-only report. Each account has a unique code and parent, appears in COA and general ledger, and posts through the common immutable ledger. Allow non-login employee records; distinguish users from employees. Do NOT place all employee assets and liabilities under one incorrect parent: employee advances/custody and employee payables may need different accounts, explicitly agreed with accountant. A party may require more than one account per accounting role; use an explicit mapping model and document rules instead of forcing incompatible accounting categories.

## Required behavior
- Configurable COA templates/parent mappings for receivables (customers), payables (suppliers), employee advances/custody and employee payable balances; choose parent per party role and classification, with account-code generator safely handling concurrency.
- Automatic subaccount provisioning at party onboarding (within DB transaction) or controlled activation. Prevent orphan/multiple conflicting primary mappings; migrate/backfill existing parties safely without rewriting posted journal entries; review current ledgers first.
- Complete Customer 360 profile: names AR/EN, contacts, classification, billing address, tax IDs when relevant, branch/owner, credit terms and limits if enabled, documents, notes, interaction history, lead-to-customer link, bookings, invoices, receipts, refunds, outstanding invoices, account code and full statement. Permissions on personal data and branch boundaries.
- Complete Supplier 360 profile: categories, addresses, vendor references, contracts/docs, invoices/bills, credit notes, payments, bookings/services linked, bank data guarded by permissions, account code, full statement.
- Complete Employee 360 FINANCIAL profile: identity/staff record (not an HR/payroll suite), job/branch, supervisor, optional linked user, employee subaccount mappings, advance/custody issuance/settlement, commissions, approved deductions/payables, financial activity and full statement. Do NOT imply full payroll is included.
- Real account statements for each party: posting date, document date, source, reference, booking/invoice/receipt links, debit, credit, running balance, currency/base currency, period filter, opening and closing balance, PDF/Excel export. Decide and document sign convention; base all posted balances on ledger, not manually maintained totals.
- Drilldown from party profile -> subaccount -> posted journal -> source financial document, and reverse links from journals to party.
- Maintain branch visibility, soft archiving not deletion when ledger activity exists, unique IDs, duplicate detection, and an auditable merge process if needed (do not merge ledger accounts silently).
- Reconcile subsidiary party statements to each posting subaccount and parent account control balances, with tests and mismatch diagnostics.

## Required test scenarios
Customer creation creates linked posting account; supplier and employee creation as appropriate; parallel creation unique codes; invoice and receipt update party statement/GL identically; partial settlement; opening balance; cancellation via reversing entries; FX; archived party history; permissions; existing-data backfill; parent roll-up equals children and total TB (subject to documented grouping/mapping rules).

## Deliverables
Audit findings; agreed account mapping and data model; migrations/backfill; APIs; React profile and statement views using existing design system; tests; reconciliation sample fixture; changed-file list and risks. Stop for accountant sign-off on classifications and opening-balance migrations. Do not manufacture new journal entries to force balances to match.
