# EP-02 — Money & FX

---

## TE-011 — Money value object, DB conventions, and rounding

**Goal:** Exact money handling everywhere.
**Scope in:** Money value object (integer minor units + currency), casts/validation, `NUMERIC(19,4)` columns, rounding service, rounding account role, serialization format, static-analysis rule banning floats for money.
**Scope out:** Currency conversion itself (TE-012).
**Dependencies:** TE-005.
**API contract changes:** Money serialization convention (`{amount, currency, scale}` or decimal string — architect decides in ADR).
**Acceptance criteria:** AC-MONEY-001, AC-MONEY-002, AC-MONEY-005 (rounding part).
**Test cases:** 0.1+0.2, half-up boundaries, negative amounts, multi-line residual, DB round-trip precision.
**Owned files:** `backend/app/Modules/Money`, shared casts, tests; architecture ADR for money.
**Reviewer:** Architect + accountant (rounding).

---

## TE-012 — Currencies, FX rates, and snapshots

**Goal:** Multi-currency with manual per-transaction rates.
**Scope in:** Currency CRUD (EGP/SAR/USD seeded, more configurable), base currency, FX rate entry (rate, date, source), historical lookup, snapshot value object, API.
**Scope out:** Automated rate feeds, period-end revaluation (DEC-008).
**Dependencies:** TE-011.
**API contract changes:** `/currencies`, `/fx-rates`; FX snapshot shape on documents.
**Acceptance criteria:** AC-MONEY-003, AC-MONEY-004; rate date/rate/source stored; base currency immutable after postings.
**Test cases:** missing rate, zero/negative rate, historical rate selection, snapshot immutability, cross-currency validation.
**Owned files:** `backend/app/Modules/Money/Currency`, migrations, tests.
**Reviewer:** Architect + accountant (DEC-007).
