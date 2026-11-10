# Prevent Double Revenue Booking Invoice

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P0  
**Depends on:** 02 (policy gate)

## Mission
Prove and eliminate any double revenue/AR recognition across Booking and Invoice paths without rewriting historical balances.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Trace `BookingService::postRevenue`, `InvoiceService::issue`, `PaymentService`, invoice-booking relationship, journal `source_type/source_id/event`, cancellation and credit notes.
2. Model mutually exclusive recognition flows: booking operational document vs invoice legal/financial instrument, or recognition clearing/reclassification as approved. Specify decision matrix for unified vs separate documents; do NOT choose tax treatment without accountant approval (DEC-001, DEC-028).
3. Implement a common business-event accounting identity (or equivalent posting orchestration) to detect duplicate economic recognition across different source types, with database uniqueness where appropriate and safe retry behavior.
4. Ensure customer AR, revenue, taxes, advances, and invoice outstanding are reconciled; distinguish posting of booking service delivery from issuance of invoice. Guard unresolved paths rather than double-posting.
5. Implement a READ-ONLY historical duplicate-risk detector that flags suspicious pairs for accountant review; never auto-reverse production entries.
6. Update OpenAPI, ADR-0004, invoices/bookings service boundaries and reporting as needed.

## Acceptance tests and validation
- Integration: booking confirmation → issue invoice → trial balance counts revenue and AR **once** under approved policy.
- Cancel/credit note/refund/partial payment and repeated requests reconcile without duplicates.
- Concurrent retries, old records and closed periods handled safely.
- Tests run in PostgreSQL as well as SQLite where possible.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
