# ADR-0003 — Money, currency, and FX representation

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + accountant (DEC-007, DEC-010)

## Context

The product handles EGP, SAR, USD, and configurable currencies, with manual FX rates per transaction and FX differences. Floating point is forbidden. SQLite and PostgreSQL must behave identically in tests and production.

## Decision

1. **Money is an integer count of minor units** plus a currency code: `Money { amountMinor: int, currency: string }`. The currency defines the exponent (EGP/SAR/USD = 2; KWD = 3; etc.).
2. Database columns: `*_minor bigint` and `currency char(3)`. No `float`/`double` anywhere.
3. **FX rate** is stored as `numeric(20,10)` and passed around as a decimal string. Conversions use `bcmath` with explicit scale and half-up rounding at the defined step.
4. **Base currency** is an installation setting. Every posting stores both transaction-currency amounts and base-currency amounts, plus the rate and rate date used.
5. **Rate snapshot:** a document captures `fx_rate`, `fx_rate_date`, `fx_rate_source` at issue/confirmation. Posted history never re-reads the current rate.
6. **Rounding:** per line, half-up to the currency exponent; any residual is posted to the configured rounding account so the document balances exactly. The policy is versioned in settings.
7. **Realized FX:** settlement at a different rate posts the difference to configured `fx_gain`/`fx_loss` accounts. Unrealized revaluation is off in MVP (DEC-008).
8. **API serialization:** `{ "amount": "1234.50", "currency": "EGP", "amountMinor": 123450 }` — clients may use the decimal string; the minor integer is authoritative.

## Consequences

- Exact arithmetic on every platform; no float leakage.
- Slightly more code at boundaries; mitigated by a `Money` value object and cast.
- Reports must aggregate in base currency using stored base amounts, never by re-converting today.
