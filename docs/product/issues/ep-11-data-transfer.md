# EP-11 — Import and Export

---

## TE-047 — CSV/Excel import framework

**Goal:** Safe, validated bulk data intake.
**Scope in:** Upload/detect/map columns, templates, dry-run, row validation (types, references, money/currency, duplicates, permissions), error report, atomic commit, async batches with checkpoints, idempotency key, import history.
**Scope out:** Full legacy migration, OCR.
**Dependencies:** TE-022, TE-023, TE-013, TE-017, TE-041.
**API contract changes:** `/imports`, `/imports/{id}/preview`, `/imports/{id}/commit`.
**Acceptance criteria:** AC-DATA-001..005.
**Test cases:** missing columns, Arabic headers, BOM, Excel dates, mixed currency, duplicate policy (skip/update/error), interrupted job resume, permission/branch enforcement.
**Owned files:** `backend/app/Modules/DataTransfer/Import`, tests, fixtures.
**Reviewer:** Architect.
**Status:** done — `/imports` with templates, confirm and error report; `ImportFrameworkTest`, `ImportSanitizerTest`.

---

## TE-048 — Export framework

**Goal:** Consistent, permission-safe exports.
**Scope in:** Export job abstraction, CSV/Excel writers, async progress, download authorization, filter parity with lists/reports, audit, retention/cleanup.
**Scope out:** Scheduled distribution, data warehouse.
**Dependencies:** TE-039 (rendering), TE-047 (shared job infra).
**API contract changes:** `/exports`, `/exports/{id}/download`.
**Acceptance criteria:** AC-DATA-005, AC-DATA-006, AC-RPT-007.
**Test cases:** large export, expired download, branch leakage, concurrent exports, Unicode/Excel compatibility.
**Owned files:** `backend/app/Modules/DataTransfer/Export`, tests.
**Reviewer:** Architect.
**Status:** partial — Synchronous CSV/XLSX/PDF exports with audit exist; no `/exports` job resource, progress or retention (GAP-RPT-02).
