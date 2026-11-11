# Migration Order — Tourism ERP

Status: Draft. Forward-only in production. Each step must be tested with a rollback or an explicit "backup required" note.

## Order

| # | Migration group | Tables | Notes |
|---|---|---|---|
| 01 | Laravel base | `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs`, `personal_access_tokens` | Framework defaults. |
| 02 | Access | `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie. |
| 03 | Core | `branches`, `user_branch`, `settings`, `sequences`, `audit_events`, `attachments` | Branch before any operational table. |
| 04 | Money | `currencies`, `fx_rates` | Seed EGP/SAR/USD. |
| 05 | Accounting | `accounts`, `fiscal_years`, `fiscal_periods`, `journal_entries`, `journal_lines`, `opening_batches`, `opening_batch_lines` | Journal FKs after accounts/periods. |
| 06 | Parties | `customers`, `suppliers`, `payees`, `contacts` | Party tables before bookings/payments. |
| 07 | Tax | `tax_profiles` | Referenced by bookings/expenses. |
| 08 | Bookings | `bookings`, `booking_items`, `booking_status_history`, `cancellation_policies` | Depends on parties, tax, branches, money. |
| 09 | Payments | `payments`, `payment_allocations`, `refunds`, `supplier_obligations`, `supplier_payments`, `supplier_payment_applications`, `transfers` | Depends on bookings/accounts. Applications link one payment/advance to one or more obligations. |
| 10 | Commissions | `commission_rules`, `commission_accruals`, `commission_payouts`, `commission_payout_lines` | Depends on bookings/payees. |
| 11 | CRM | `lead_sources`, `lead_stages`, `leads`, `lead_activities`, `assignment_rules` | Independent of ledger. |
| 12 | Integrations | `integration_settings`, `webhook_events`, `whatsapp_messages` | Depends on CRM. |
| 13 | Data transfer | `import_batches`, `import_row_errors`, `export_jobs` | Depends on target modules. |
| 14 | Reliability | `idempotency_keys`, `outbox_events`, `event_consumptions` | Cross-cutting. |
| 15 | Reporting | `saved_reports` (later), indexes | Add reporting indexes after data model stabilizes. |
| 16 | Decision support (2026-11-10) | Additive fields/indexes on `users`, `leads`, `assignment_rules`, and `bookings` | CRM routing and negative-margin approval metadata only; tested up/down/up, no historical financial rows rewritten. |

## Rollback rules

- Steps 01–05: reversible via `down()`; verify on a copy.
- Steps 06–10: reverse only before production data exists; otherwise restore from backup.
- Any migration that drops/renames a column or rewrites financial data is **irreversible** and requires a backup gate + release approver sign-off.
- Seed data (CoA, roles, currencies, sequences) is idempotent and safe to re-run.

## Index checklist

- `journal_entries (branch_id, entry_date)`, `(source_type, source_id, event)` unique, `(fiscal_period_id)`.
- `journal_lines (account_id, journal_entry_id)`, `(party_type, party_id)`.
- `bookings (branch_id, status, start_date)`, `(customer_id)`.
- `payments (branch_id, paid_at)`, `(customer_id)`.
- `payment_allocations (booking_id)`, `(payment_id)`.
- `supplier_obligations (supplier_id, status)`.
- `supplier_payments (branch_id, paid_at)`, `(supplier_id)`, unique `idempotency_key` when set.
- `supplier_payment_applications (supplier_obligation_id)`, `(supplier_payment_id)`.
- `leads (branch_id, stage_id, owner_user_id)`, `(phone)`, `(email)`.
- `webhook_events (provider, external_event_id)` unique.
- `idempotency_keys (actor_id, key)` unique.

### Pending: document-number uniqueness

`bookings`, `payments`, `invoices` (`branch_id, series, number`),
`accounting_schedules` and `fixed_asset_depreciation_runs` already have a
database uniqueness guarantee on their numbers. These tables still rely only on
the `SequenceService` row lock:

- `journal_entries`, `refunds`, `transfers`, `expenses`, `accounting_documents`.

Before adding a unique index on `(branch_id, number)` to them, run the
read-only audit on the client installation:

```bash
php artisan accounting:audit-document-numbers          # prints a report; exits non-zero on duplicates
php artisan accounting:audit-document-numbers --json   # machine-readable
```

It never renumbers or deletes anything. Duplicate numbers on posted documents
are an accountant decision (which document keeps the number), so the index
migration is intentionally not written until the audit is clean on that
installation. The per-branch sequence lock means new documents are already
unique; the audit finds legacy rows issued before the branch code was part of
the number.
