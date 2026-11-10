# ADR-0012 — CRM lead routing and inactivity

- Status: Accepted
- Date: 2026-11-10
- Deciders: Product owner (DEC-015, DEC-016)

## Decision

Lead routing uses deterministic active rules ordered by priority and ID. Rules may match branch, source, and service category and select a manual owner, round-robin, or least-loaded strategy. Inactive or unavailable users are excluded. Manual API owner changes remain explicit and audited.

Inactivity counts only configured business hours in the configured timezone, starting from the last meaningful activity. Reassignment locks the lead row, excludes the current owner, records assignment activity and audit history, and escalates to the unassigned queue after the configured maximum attempts.

## Consequences

- Defaults are installation settings, not client-specific code.
- Concurrent workers serialize per lead; repeat processing rechecks eligibility under lock.
- Rule-management UI is additive follow-up work; the persisted rule contract is available now.
