Review the existing implementation and apply the 12 product-owner decisions listed below without breaking existing functionality, accounting integrity, API contracts, or historical financial data.

This is an **incremental implementation task**, NOT a project rebuild.

## Phase 1 — Repository Audit

Before modifying any code:

1. Read `AGENTS.md` and the project documentation.
2. Locate and review `decisions-needed.md`.
3. Inspect architecture ADRs, API contracts, database migrations, models, services, controllers, frontend components, settings, and existing tests.
4. Identify which decisions are already implemented, partially implemented, or missing.
5. Check dependencies between Accounting, Bookings, CRM, Payments, Invoicing, Reports, and Branches.
6. Produce a concise gap analysis before implementation.

Do not duplicate existing functionality.

## Phase 2 — Approved Decisions

Implement the following decisions.

### DEC-020 — Company Architecture

**Decision: One legal entity per installation.**

- Each customer receives an independent self-hosted deployment.
- No SaaS multi-tenancy.
- No tenant_id architecture unless already required by existing functionality.
- Each deployment owns its database, configuration, files, and users.
- Preserve configurable company information and branding.
- Do not introduce unnecessary multi-company complexity.

Status: APPROVED_BY_PRODUCT_OWNER.

### DEC-014 — Branch Accounting

**Decision: One general ledger with branch-level accounting separation.**

- Maintain one consolidated Chart of Accounts and General Ledger.
- Support branch attribution on financial transactions.
- Support branch-filtered and consolidated financial reports.
- Support inter-branch transactions using appropriate clearing/control accounts where approved.
- Enforce branch access permissions.
- Prevent duplicate or unbalanced journal postings.

Status: PENDING_ACCOUNTANT_APPROVAL.

### DEC-025 — Negative-Margin Bookings

**Decision: Loss-making bookings require manager approval.**

- Calculate expected booking margin before confirmation.
- Require approval when the expected margin is negative.
- Prevent unauthorized confirmation.
- Store approval details, reason, approver, and timestamp.
- Recalculate margins when booking costs or selling prices change.
- Keep the zero-margin policy configurable pending accounting approval.
- Never silently bypass approval.

Status: PENDING_ACCOUNTANT_APPROVAL.

### DEC-028 — Booking Documents and Invoices

**Decision: Allow a unified booking confirmation and invoice document where legally permitted.**

- Support configurable document templates.
- A booking confirmation may also serve as an invoice only when jurisdiction-specific legal and tax requirements permit it.
- Preserve separate accounting and tax document identities where legally required.
- Avoid duplicate invoice postings.
- Maintain proper invoice numbering, taxes, customer details, and audit history.
- Do not assume one document is legally valid in every country.
- Require accountant approval of invoice recognition and tax posting policies.

Status: PENDING_ACCOUNTANT_APPROVAL.

### DEC-004 — Employee Commissions

**Decision: Configurable commission accrual rules, defaulting to full collection.**

- Support configurable commission triggers.
- Default trigger: booking fully collected.
- Allow rules based on booking, employee, branch, service, and effective date.
- Prevent duplicate accruals and payouts.
- Support cancellations, refunds, and commission reversals.
- Preserve traceability between booking, employee, and financial entry.
- Keep financial posting dependent on accountant approval.

Status: PENDING_ACCOUNTANT_APPROVAL.

### DEC-008 — Period-End FX Revaluation

**Decision: Support FX revaluation with reversing journal entries.**

- Support revaluation of eligible outstanding foreign-currency balances.
- Distinguish realized and unrealized FX gains/losses.
- Use approved exchange-rate snapshots.
- Generate balanced revaluation entries.
- Support controlled reversal in the following period.
- Prevent duplicate revaluations.
- Respect locked accounting periods.
- Require accountant-approved accounts and policies before enabling posting.

Status: PENDING_ACCOUNTANT_APPROVAL.

### DEC-015 — Lead Reassignment

**Decision: Reassign unattended leads after 24 configurable business hours.**

- Use a business calendar and configurable timezone.
- Track meaningful employee activity.
- Reset the inactivity clock only for qualifying activities.
- Allow configurable reassignment thresholds.
- Prevent reassignment races and duplicate assignments.
- Escalate unresolved leads to a manager or queue.
- Maintain complete assignment history.

Status: APPROVED_BY_PRODUCT_OWNER.

### DEC-016 — Lead Distribution

**Decision: Configurable rule-based lead distribution.**

Support:
- Branch-based assignment.
- Travel/service category.
- Employee workload.
- Employee availability.
- Deterministic rule priority.
- Manual overrides.
- Fallback assignment queue.

Keep the distribution rules configurable through the application.

Status: APPROVED_BY_PRODUCT_OWNER.

### DEC-018 — Document Numbering

**Decision: Number documents by branch, fiscal year, and document type.**

- Support configurable prefixes and numbering sequences.
- Generate numbers atomically and safely under concurrent requests.
- Prevent duplicate numbers.
- Preserve previously issued numbers.
- Never renumber posted documents.
- Define explicit handling for voided documents and numbering gaps.
- Do not assume a universal legal requirement for gapless numbering.

Status: APPROVED_BY_PRODUCT_OWNER, subject to statutory numbering requirements.

### DEC-022 — Customer and Supplier Portals

**Decision: Exclude portals from MVP.**

- Keep existing Customers and Suppliers modules.
- Do not build external customer or supplier portals.
- Preserve future extensibility.
- Do not delete already completed functionality.

Status: APPROVED_BY_PRODUCT_OWNER.

### DEC-029 — Document Languages

**Decision: Arabic and English document templates.**

- Support Arabic and English.
- Support RTL and LTR.
- Allow language selection per template/document.
- Preserve financial values and accounting data across languages.
- Support configurable company branding.
- Ensure PDF rendering supports Arabic text correctly.

Status: APPROVED_BY_PRODUCT_OWNER.

### DEC-040 — Financial Report Exports

**Decision: Native PDF, XLSX, and print support.**

- Support native PDF exports.
- Support actual XLSX files, not CSV renamed as XLSX.
- Support browser printing.
- Preserve Arabic and English formatting.
- Support date, branch, account, and cost-center filters where applicable.
- Ensure exported totals match the on-screen reports.
- Preserve accounting precision.
- Apply the same authorization rules to exports and report APIs.
- Stream or queue large exports when necessary.

Status: APPROVED_BY_PRODUCT_OWNER; **resolved by ADR-0014** (`../architecture/ADRs/0014-report-document-exports.md`); statutory layouts require accountant approval.

## Phase 3 — Decision Governance

Update `decisions-needed.md` according to these rules:

1. Record the product-owner selections with decision date `2026-11-10`.
2. Mark HUM-only decisions as DECIDED when fully resolved.
3. Mark ACC and ACC/HUM decisions as `PENDING_ACCOUNTANT_APPROVAL`.
4. Do not invent accountant names, approval references, or signatures.
5. If a mixed decision has unresolved legal/accounting implications, document those implications separately.
6. Leave all remaining decisions OPEN.
7. Update or create ADRs for the approved architecture and product behavior.
8. Preserve all historical decision records.

## Phase 4 — Safe Implementation

Implement changes incrementally.

Mandatory requirements:

- Preserve the existing Modular Monolith architecture.
- Reuse existing services and database structures.
- No destructive database migrations.
- No automatic rewriting of posted journal entries.
- No modifications to historical financial balances.
- No hardcoded client-specific accounting behavior.
- No duplicated business logic.
- No breaking API changes without compatibility handling.
- Respect the existing Design System.
- Use feature flags or guarded settings for unresolved accounting policies.
- Do not enable financial posting that depends on unapproved accounting decisions.
- Do not modify unrelated modules.

## Phase 5 — Testing

Add or update automated tests covering:

**Accounting**
- Balanced journal entries.
- Branch-separated and consolidated reporting.
- FX revaluation and reversal.
- Closed-period restrictions.
- Commission accrual idempotency.
- Negative-margin booking approval.
- Invoice numbering and duplicate prevention.

**CRM**
- Rule-based lead assignment.
- Business-hour inactivity calculation.
- Automatic reassignment.
- Employee availability.
- Concurrent assignment protection.

**Documents and Reports**
- Arabic and English PDF rendering.
- Real XLSX generation.
- Report totals matching ledger totals.
- Export permissions.
- Correct branch, year, and document-type numbering.

Run the existing test suites and identify any failures introduced by the changes.

## Phase 6 — Deliverables

Produce:

1. Decision Implementation Matrix:
   - Decision ID
   - Previous state
   - Required change
   - Final implementation status
   - Accountant approval status
   - Related files

2. Updated `decisions-needed.md`.

3. Updated ADRs and API contracts, where necessary.

4. Implemented code for safe, approved changes.

5. Automated tests and results.

6. List of blocked accounting actions.

7. Remaining open decisions.

8. Final Gap Analysis and recommended next steps.

## Execution Rules

Work on a dedicated feature branch.

Do not push directly to `main`.

Implement safe and non-blocked work without requesting repeated confirmation.

If a decision requires accountant approval, implement only the safe configuration, validation, and supporting infrastructure. Do not activate unapproved financial posting behavior.

Commit changes in logical groups and open a Pull Request for review.

**Definition of Done:** All approved, non-blocked changes implemented, related tests passing, documentation updated, and accountant-dependent items clearly flagged without unsafe assumptions.