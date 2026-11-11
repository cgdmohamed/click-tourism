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

Still open from this ADR: Sanctum token expiry/abilities for service accounts,
login lockout, TOTP 2FA, and DEC-017 segregation of duties (maker ≠ approver).

