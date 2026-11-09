# ADR-0002 — Database conventions and identifiers

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect

## Context

Financial data must be exact, auditable, portable between SQLite (local tests) and PostgreSQL (production), and safe under concurrency.

## Decision

1. **Primary keys:** `bigint unsigned` auto-increment internally; public-facing identifiers use a `ulid` column (`public_id`) with a unique index. API routes accept ULIDs.
2. **Foreign keys:** explicit `*_id` bigint columns with indexes; `ON DELETE RESTRICT` for anything financial or referenced.
3. **Timestamps:** `created_at`, `updated_at` in UTC. Business dates use `date` columns. Soft deletes only on non-financial master data; posted financial rows are never deleted.
4. **Money:** stored as `bigint` **minor units** (`amount_minor`) plus a `char(3)` currency. FX rates stored as `numeric(20,10)` and handled as decimal strings. See ADR-0003.
5. **Enums:** string columns with application-level enums (portable, readable), validated at the boundary. No DB enum types.
6. **JSON:** only for snapshots/payloads (`meta`, `payload`, `snapshot`) that are not queried relationally; never for money or core relations.
7. **Indexes:** every FK; every `branch_id + date` reporting path; unique `(source_type, source_id, event)` for posting idempotency; unique `(provider, external_event_id)` for webhooks.
8. **Naming:** snake_case plural tables, snake_case columns, `*_minor` for money, `*_at` for timestamps, `*_date` for dates.
9. **Migrations:** forward-only in production; every migration must have a documented rollback or an explicit "irreversible, backup required" note.
10. **No `tenant_id`.** One installation = one legal entity.

## Consequences

- SQLite is usable for fast tests because no PG-specific types are required.
- BIGINT minor units avoid floating point and decimal-cast ambiguity.
- ULIDs in URLs prevent enumeration without exposing internal counts.
