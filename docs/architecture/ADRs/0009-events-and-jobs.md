# ADR-0009 — Domain events, jobs, and scheduler

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect

## Context

Posting, commissions, reassignment, notifications, and integrations must happen reliably without coupling modules or blocking HTTP requests.

## Decision

1. **Domain events** are in-process Laravel events named in the past tense (`BookingConfirmed`, `PaymentRecorded`, `RefundIssued`, `LeadAssigned`). They carry IDs + minimal immutable data, not Eloquent models.
2. **Transactional outbox:** events that must trigger side effects are written to `outbox_events` in the same DB transaction as the business change. A dispatcher publishes them after commit (at-least-once).
3. **Consumers** are idempotent and keyed by `(event_id, consumer)`. Failures retry with backoff; exhausted jobs go to `failed_jobs` with an admin view.
4. **Queue:** Redis-backed Laravel queues. Queues: `default`, `accounting`, `integrations`, `reports`, `notifications`. Accounting postings from business events may run synchronously inside the request when the user expects immediate balance changes; otherwise queued with a visible status.
5. **Scheduler:** Laravel scheduler runs reassignment (hourly), report/export cleanup, failed-job review, backup hooks, integration retries.
6. **Notifications** are queued and deduplicated by `(notifiable, type, subject)`.
7. **Event catalog** (`event-catalog.md`) is the contract; payload changes require versioning (`event_version`).

## Consequences

- Reliable side effects without distributed transactions.
- At-least-once delivery requires idempotent consumers (ADR-0007).
- Outbox adds a table and dispatcher job.
