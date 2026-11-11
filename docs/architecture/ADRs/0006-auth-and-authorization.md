# ADR-0006 — Authentication and authorization

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + security

## Context

The system is a self-hosted web app with ~10 users and flexible roles. It contains financial and personal data. Every endpoint must be authorized.

## Decision

1. **Authentication:** Laravel Sanctum personal access tokens for the SPA/API. Password hashing with bcrypt/argon2. Login rate limiting and lockout. Session revocation on deactivation/password change. TOTP 2FA is a later enhancement.
2. **Authorization:** `spatie/laravel-permission` for roles/permissions, combined with Laravel policies for resource-level rules. Deny by default.
3. **Roles (seeded, editable):** Owner, Accountant, Chief Accountant, Sales Manager, Sales Agent, Treasurer, Branch Manager, Auditor, Administrator.
4. **Branch scope:** enforced by policy + query scope (ADR-0005), not by client-supplied filters.
5. **Segregation of duties:** configurable; when enabled, the creator cannot approve their own refund/expense/commission payout. Enforced in the approval service, not only the UI.
6. **Service accounts:** integration endpoints use a dedicated ability/token, cannot access financial routes, and are rate-limited.
7. **Secrets:** integration credentials encrypted at rest with the app key, write-only in API responses, redacted in logs/audit.
8. **Audit:** login success/failure, permission changes, approvals, postings, reversals, exports.
9. **Transport:** TLS terminated at the reverse proxy; secure cookies/tokens; CORS restricted to the SPA origin.

## Consequences

- Fine-grained roles without code changes.
- Policy coverage test required for every route.
- Spatie tables are part of the shared schema and need migration review.

## Addendum 2026-11-12 — implemented role set

Work order 01 (multi-user foundation) implemented the role/permission model. The
illustrative list in §3 (Owner, Accountant, Chief Accountant, Sales Manager, Sales
Agent, Treasurer, Branch Manager, Auditor, Administrator) is superseded by the
following nine seeded system roles, defined in `RolePermissionSeeder` and re-applied
by `php artisan roles:sync`. The full permission matrix (86 permissions × 9 roles)
lives in `docs/product/permission-matrix.md`.

| Role | Purpose |
|---|---|
| `admin` | All permissions, including user/role management and `branches.view_all`. |
| `finance_manager` | Accountant scope plus posting own drafts and approval rights; read-only user/role directory. |
| `senior_accountant` | Accountant scope plus posting/deleting journal drafts (maker-checker override, audited). |
| `accountant` | Full accounting operations without user management or draft posting. |
| `supervisor` | Sales scope plus staff visibility, exports, and the user directory. |
| `sales` | Customer-facing pipeline without accounting setup. |
| `reservations` | Bookings and parties without accounting power. |
| `cashier` | Collections, refunds, and transfers at the counter. |
| `viewer` | Read-only baseline. |

Refinements applied with this set:

- **Roles are seeded system data, not editable defaults.** The nine system roles are
  immutable through the API and re-synced on every upgrade; installations may create
  additional custom roles (`roles.manage`) whose permission sets may not exceed the
  creator's own privileges.
- **Privilege ceiling.** Users may only assign roles, permissions, and branches
  within their own holdings — a manager can never mint a superior account.
- **User lifecycle guards.** The last active administrator cannot be deactivated or
  demoted, self-deactivation is refused, and deactivation keeps tokens on record
  while the `active` middleware rejects them.
- **Users are global records with per-user branch assignments** (ADR-0005), plus an
  optional `primary_branch_id` that must stay inside the assignment.

## Addendum 2026-11-12 — work order 02 (token lifetime + maker ≠ approver)

Work order 02 closes the two items this ADR left open after work order 01:
Sanctum token expiry and DEC-017 segregation of duties.

### Token lifetime

- **`SANCTUM_TOKEN_EXPIRATION`** (minutes, default `43200` = 30 days) is enforced by
  the Sanctum guard at check time: a token whose `created_at` is older than the
  window is rejected with 401 even though the row still exists. `createToken()`
  deliberately does not set `expires_at`; the window is a single config knob.
- A daily `sanctum:prune-expired --hours=168` (02:45) deletes rows of tokens that
  have been dead for over a week, keeping the table small without destroying
  recent evidence. The scheduler heartbeat (`/api/v1/health`) shows if it stalls.
- **Password reset revokes tokens.** An administrator resetting a target's
  password through `PATCH /users/{id}` deletes all of the target's tokens (audit
  `user.tokens_revoked`, reason `password_reset`), so stolen bearer tokens die
  with the credential. Self-service changes keep the actor's current token —
  both `PATCH /auth/me` and `PATCH /users/{id}` behave the same way.
- Token **abilities** for service/integration accounts remain open; the Meta/
  WhatsApp webhooks use signed payloads, not personal access tokens.

### Segregation of duties (DEC-017) — maker ≠ approver

DEC-017 was closed by the project owner on 2026-11-11 with the reference behaviour
(Odoo/Zoho): approval rules are **per document type** and enforced in the service,
never only in the UI. Every rule is a setting; the installations below reflect the
shipped defaults.

| Document | Setting | Default | Maker field |
|---|---|---|---|
| Journal draft posting | `journal.post_own_drafts` permission | permission-based | `created_by` |
| Expense | `expenses.segregation` | on | `created_by` / `submitted_by` |
| Voucher (accounting document) | `documents.segregation` | on | `submitted_by` |
| Invoice | `invoices.segregation` | **on (new)** | `created_by` |
| Bank reconciliation | `treasury.reconciliation_segregation` | **on (new)** | `created_by` |
| Employee advance | `employees.segregation` | **on (new)** | `created_by` |
| Refund | `accounting.refund_segregation` | off (owner ruling) | `requested_by` |
| Booking negative margin | unconditional | on | `margin_requested_by` |

`documents.segregation` existed in code but was missing from the settings DEFAULTS;
it is now visible and editable like the rest. The new defaults follow the expense
and voucher precedent (on), while refunds stay off per the closed DEC-017 ruling —
**flagged for owner review in the PR**, since a one-person installation must turn
them off to operate.

The maker≠approver check compares the actor against the maker before any state
change; violations throw a `DomainException` (HTTP 422) inside the same database
transaction, so nothing is partially approved. The demo seeder approves seeded
invoices as a second seeded user (`admin@example.com`) instead of weakening the
rule.

Still open from this ADR: token abilities for service accounts, login lockout,
and TOTP 2FA.

