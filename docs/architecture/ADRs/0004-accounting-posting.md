# ADR-0004 — Accounting posting, immutability, and reversal

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + accountant (DEC-001, DEC-002, DEC-019)

## Context

Every money-moving action must produce a balanced, traceable entry. Corrections must not rewrite history. Posting must be atomic and safe under retries.

## Decision

1. **Single posting contract:** `PostingService::post(PostingRequest): JournalEntry`. All automatic and manual postings go through it.
2. A `PostingRequest` carries: date, branch, currency, FX rate/date/source, source type/id, event key, memo, and lines (`account`, `debitMinor` xor `creditMinor`, party, dimensions).
3. **Validation inside one DB transaction:**
   - debits = credits in transaction currency;
   - debits = credits in base currency (computed from the request rate);
   - accounts exist, are active and postable, and permit the currency;
   - the fiscal period is open;
   - the actor may post to the branch;
   - the source document exists;
   - the `(source_type, source_id, event)` key has not already been posted.
4. **Immutability:** posted entries and lines have no update/delete path. Model-level guards and DB-level checks (no soft delete) enforce it.
5. **Reversal:** `reverse(entry, reason, actor)` creates a new posted entry with every line inverted, `reversal_of_id` set, and the same source reference. An entry can be reversed once. Reversing a reversal is rejected; create a new correction instead.
6. **Corrections:** reversal + optional corrected entry, both linked and audited.
7. **Period control:** posting into `closed` is rejected; `soft_closed` requires an explicit permission. Reopening is an audited chief-accountant action.
8. **Base amounts:** computed and stored at posting time; reports use stored base amounts. No retroactive re-conversion.
9. **Traceability:** every entry stores `source_type`/`source_id`; every line stores `branch_id`. Orphan postings are forbidden.

## Consequences

- Ledger is reproducible and testable in isolation.
- Business modules must emit intent (event/request), not write journals directly.
- Reversals keep audit history and satisfy "no edits after posting".
