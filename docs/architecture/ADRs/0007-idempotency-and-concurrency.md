# ADR-0007 — Idempotency and concurrency

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect (DEC-027)

## Context

Payments, refunds, bookings, and webhook ingestion can be retried by clients, browsers, queues, or providers. Duplicate financial postings are unacceptable.

## Decision

1. **Business actions** that create money movement accept `Idempotency-Key`.
2. `idempotency_keys` stores: key, actor id, method, path, request hash, response status/body, resource type/id, created/expires. Unique on `(actor_id, key)`.
3. Same key + same request hash → replay stored response. Same key + different hash → `409 idempotency_conflict`.
4. Keys expire after 24h for interactive actions; webhook dedupe is permanent.
5. **Posting idempotency:** unique `(source_type, source_id, event)` on journal entries. A repeated event returns the existing entry.
6. **Webhooks:** unique `(provider, external_event_id)`; duplicate returns 200 with no side effects.
7. **Concurrency:** money mutations run in a DB transaction and lock the affected rows (`SELECT ... FOR UPDATE` on booking/payment/obligation). Allocation checks and updates happen in the same transaction.
8. **Optimistic locking:** mutable documents carry a `version`/`updated_at` check; conflicting edits return `409` rather than overwriting.
9. **Jobs:** all queued jobs are idempotent and safe to retry; max attempts + failed-job visibility.

## Consequences

- Safe retries and provider replays.
- Requires a small middleware/service and an extra table.
- Tests must cover crash-between-post-and-response.
