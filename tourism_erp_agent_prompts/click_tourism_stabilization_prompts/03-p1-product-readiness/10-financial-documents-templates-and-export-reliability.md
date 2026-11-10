# Financial Documents Templates and Export Reliability

**Repository:** `cgdmohamed/click-tourism`  
**Execution:** Codex / coding agent, one task per independent feature branch and pull request.  
**Priority:** P1  
**Depends on:** 01, 04, 07

## Mission
Bring PDFs and XLSX to controllable commercial-document quality without inventing legal rules.

## Required reconnaissance (before code)
1. Read `AGENTS.md`, `docs/product/decisions-needed.md`, `docs/product/decision-implementation-matrix.md`, relevant ADRs, OpenAPI contracts and test fixtures.
2. Inspect **actual code on the current branch**, not README statements. Produce a short gap report of present vs absent vs uncertain features, file-level change plan and risk assessment.
3. Verify the latest merged changes and existing DB schema; preserve public routes, stable IDs, historical journal entries and existing permissions.
4. Never infer accountant approval from a product-owner decision. Leave ACC items `PENDING_ACCOUNTANT_APPROVAL`; enforce guardrails on financial posting where policy remains unapproved.

## Implementation instructions
1. Review `ReportDocument.php`, `ReportController`, document templates, `Invoices.tsx`, `Reports.tsx`, current CSV-to-XLSX/PDF conversion and 50k synchronous cap.
2. Implement report/document-specific headers, company branding, branch, period, currency, sign-off footer where configured, page breaks, Arabic shaping and bilingual RTL/LTR review.
3. Validate that XLSX contains actual numeric cells with spreadsheet-safe strings, dates and money formatting; prevent CSV/Excel formula injection on every export path.
4. Enforce exactly the same branch scope and export permissions as on-screen reports; audit exports with provenance without leaking PII.
5. Handle large datasets safely with streaming or queued exports where justified, with an authorized expiring download endpoint and cleanup.
6. Mark tax invoice legal templates `PENDING_ACCOUNTANT_APPROVAL`; do not claim Egypt e-invoice/ZATCA compliance without implementation and certification.

## Acceptance tests and validation
- PDF Arabic and English visual snapshot / render tests across multi-page reports.
- XLSX numeric totals equal ledger totals exactly.
- Unauthorized exports rejected; downloads cannot leak branch records.

## Deliverables and stop conditions
- Open a PR with a clear summary, changed files, backward-compatibility and migration notes, rollback plan, test results (with actual commands/output), and unresolved decisions.
- Update only the relevant ADRs / `docs/product/` docs / OpenAPI and tests. Do not overwrite other agents' work.
- No direct push to `main`, no destructive migration, no `migrate:fresh` on customer data, no fake green tests, no production deployment without explicit approval.
- If a safe design depends on unclear financial policy, deliver a guarded implementation and list the blocked behavior; do **not** auto-post it.
