# ADR-0013 — Fiscal-year document numbering

- Status: Accepted
- Date: 2026-11-10
- Deciders: Architect + product owner (DEC-018)

## Decision

Document sequences are unique per branch, containing fiscal year, and document type. Allocation remains transactionally locked and uses configurable prefixes. The sequence year is the start year of the fiscal-year record containing the document date; calendar year is the compatibility fallback when no fiscal-year record contains that date.

Issued numbers are immutable. Voids and reversals do not reuse a number, and gaps are allowed and traceable. Existing sequence rows and historical documents are not renumbered.
