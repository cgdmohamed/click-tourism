# Architecture — Tourism ERP

Status: Draft for architect approval. Produced by step `03-architecture-contracts`.

## Decisions index

| ADR | Title | Status |
|---|---|---|
| 0001 | Modular monolith and module boundaries | Proposed |
| 0002 | Database conventions and identifiers | Proposed |
| 0003 | Money, currency, and FX representation | Proposed |
| 0004 | Accounting posting, immutability, and reversal | Proposed |
| 0005 | Branch scoping and branch accounting | Proposed |
| 0006 | Authentication and authorization | Proposed |
| 0007 | Idempotency and concurrency | Proposed |
| 0008 | API and error conventions | Proposed |
| 0009 | Domain events, jobs, and scheduler | Proposed |
| 0010 | Configuration and feature toggles | Proposed |
| 0011 | Deployment, versioning, and rollback | Proposed |

## Supporting artifacts

- `ERD.md` — Mermaid entity relationship diagram.
- `event-catalog.md` — domain events and consumers.
- `migration-order.md` — safe migration sequence and rollback notes.
- `threat-model.md` — assets, threats, mitigations.
- `dependency-graph.md` — module dependency rules.
- `openapi.yaml` — API contract (v1). It must list every `/api/v1` route (method + path, parameter names ignored); `backend/tests/Feature/OpenApiCoverageTest.php` fails when a route is missing from it or documents an operation with no route. Add new endpoints here in the same PR.

## Generated codes

Users never type master-data codes. `App\Support\CodeGenerator` fills a blank `code`/`internal_code` on create with the next free `PREFIX-NNN` (EMP-0001, CC-001, BR-01, EXP-001, src-001/stg-001 for lead sources/stages, RULE-001, FAC-001), skipping codes typed or imported earlier, serialized by a PostgreSQL advisory lock with a unique-violation retry. A supplied code is still accepted and validated, so imports and existing clients keep working. Document numbers keep `SequenceService` (ADR-0013). Exceptions: chart-of-accounts codes are suggested from the parent's sibling pattern (`GET /accounts/next-code`) and may be overridden, and currency codes are ISO 4217 and picked from a catalogue (or typed under "Other") because they cannot be invented.

## Non-negotiables

- No `tenant_id`. One installation = one legal entity (MVP).
- Money never uses floats. All postings balanced in transaction and base currency.
- Posted journals immutable; corrections only via linked reversals.
- Every endpoint authorized by policy; branch scope enforced server-side.
- UI consumes only the approved design system (still missing — UI work blocked).

## Approval required

- `DEC-001..014`, `DEC-017..019`, `DEC-027` from `docs/product/decisions-needed.md`.
- Architect sign-off on module boundaries and the OpenAPI draft before implementation of shared contracts.
