# Epics and Delivery Backlog — Tourism ERP

Status: Draft. **No implementation is authorized by this document alone.** Each issue becomes one branch + one PR after prerequisites in `decisions-needed.md` and `docs/architecture/` are satisfied.

## Delivery rules (from `AGENTS.md` and PRODUCT_CONTEXT)

1. One issue = one branch = one PR, scoped to owned files. No direct commits to `main`, no force push.
2. Tests are mandatory: happy path, validation/failure, authorization/branch scope, financial invariant, idempotency/concurrency, RTL/responsive where applicable.
3. Contracts (`docs/architecture/openapi.yaml`, event catalog, DB conventions) change only with architect approval; migrations must have verified rollback.
4. Financial business rules require accountant approval; unresolved items stay configurable and are marked `UNVERIFIED`/blocked, never guessed.
5. Definition of Done: functional change + tests + docs + no regression + accepted contracts. Unverifiable items are reported as `UNVERIFIED`.
6. UI work is blocked until the design system is supplied and audited (step 02). TE-010 and TE-049..TE-052 are blocked.

## Epic overview

| Epic | Name | Phase | Issues | Exit criteria |
|---|---|---|---|---|
| EP-01 | Foundation & platform | 0–1 | TE-001..TE-010 | App boots, auth/RBAC/branches/settings/audit/sequences work, CI green. |
| EP-02 | Money & FX | 1 | TE-011..TE-012 | Exact money type + FX snapshots proven by tests. |
| EP-03 | Accounting core | 2 | TE-013..TE-021 | Balanced immutable ledger, CoA, periods, opening balances, cash/bank, expenses, close. |
| EP-04 | Parties | 3 | TE-022..TE-024 | Customers/suppliers/payees + statements. |
| EP-05 | Bookings | 3 | TE-025..TE-028 | Multi-service bookings, lifecycle, pricing, profitability, cancellation. |
| EP-06 | Payments & settlement | 3 | TE-029..TE-032 | Receipts, allocations, advances, refunds, supplier settlement, idempotency. |
| EP-07 | Tax & commissions | 4 | TE-033..TE-035 | Configurable tax + commission accrual/approval/payout. |
| EP-08 | Reporting | 4 | TE-036..TE-040 | Financial/management reports reconcile; PDF/CSV export. |
| EP-09 | CRM | 5 | TE-041..TE-043 | Leads, pipeline, assignment, 24h reassignment, conversion, metrics. |
| EP-10 | Integrations | 5 | TE-044..TE-046 | Meta + WhatsApp ingestion/sending, credentials, webhook log/replay. |
| EP-11 | Data transfer | 5 | TE-047..TE-048 | CSV/Excel import/export with validation and async jobs. |
| EP-12 | Frontend | 6 | TE-049..TE-052 | Shell, i18n/RTL, module screens, print layouts (blocked on DS). |
| EP-13 | QA & release | 6 | TE-053..TE-054 | Full test suite, clean install, backup/restore, release docs. |

## Dependency order (critical path)

```text
TE-001
 └─ TE-002 ─ TE-003 ─ TE-004 ─ TE-005 ─ TE-006 ─ TE-007 ─ TE-008 ─ TE-009
      └─ TE-011 ─ TE-012
            └─ TE-013 ─ TE-014 ─ TE-015 ─ TE-016 ─ TE-017 ─ TE-018 ─ TE-019 ─ TE-020 ─ TE-021
                  └─ TE-022 ─ TE-023 ─ TE-024
                        └─ TE-025 ─ TE-026 ─ TE-027 ─ TE-028
                              └─ TE-029 ─ TE-030 ─ TE-031 ─ TE-032
                                    └─ TE-033 ─ TE-034 ─ TE-035
                                          └─ TE-036 ─ TE-037 ─ TE-038 ─ TE-039 ─ TE-040
                                                └─ TE-041 ─ TE-042 ─ TE-043
                                                      └─ TE-044 ─ TE-045 ─ TE-046
                                                            └─ TE-047 ─ TE-048
                                                                  └─ TE-049 ─ TE-050 ─ TE-051 ─ TE-052
                                                                        └─ TE-053 ─ TE-054
```

Parallelizable after contracts approval: `TE-013..TE-021` (accounting), `TE-022..TE-024` (parties), `TE-041..TE-043` (CRM) can proceed in parallel once EP-01/EP-02 are stable.

## Issue files

- `issues/ep-01-foundation.md` — TE-001..TE-010
- `issues/ep-02-money-fx.md` — TE-011..TE-012
- `issues/ep-03-accounting.md` — TE-013..TE-021
- `issues/ep-04-parties.md` — TE-022..TE-024
- `issues/ep-05-bookings.md` — TE-025..TE-028
- `issues/ep-06-payments.md` — TE-029..TE-032
- `issues/ep-07-tax-commissions.md` — TE-033..TE-035
- `issues/ep-08-reporting.md` — TE-036..TE-040
- `issues/ep-09-crm.md` — TE-041..TE-043
- `issues/ep-10-integrations.md` — TE-044..TE-046
- `issues/ep-11-data-transfer.md` — TE-047..TE-048
- `issues/ep-12-frontend.md` — TE-049..TE-052
- `issues/ep-13-qa-release.md` — TE-053..TE-054

## Milestones

| Milestone | Contents | Demo |
|---|---|---|
| M0 — Platform ready | EP-01, EP-02 | Login, roles, branches, settings, audit, currencies. |
| M1 — Ledger ready | EP-03, EP-04 | Post balanced journal, reverse, close period, customer/supplier statements. |
| M2 — Revenue ready | EP-05, EP-06, EP-07 | Booking → payment → cost → commission → tax, all traceable to ledger. |
| M3 — Reporting ready | EP-08 | GL/TB/IS/BS/aging reconcile and export branded PDF. |
| M4 — Growth ready | EP-09, EP-10, EP-11 | Meta/WhatsApp leads routed and converted; import/export. |
| M5 — Launch ready | EP-12, EP-13 | Arabic RTL UI, full QA, clean install and restore rehearsal. |
