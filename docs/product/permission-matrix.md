# Permission Matrix — Multi-User Foundation (2026-11-12)

Status: Implemented by work order 01 (multi-user foundation). Companion to `docs/architecture/ADRs/0005-branch-scoping.md` and `0006-auth-and-authorization.md`.

Source of truth: `backend/database/seeders/RolePermissionSeeder.php` (constants `PERMISSIONS` and `ROLES`), re-applied on every upgrade by `php artisan roles:sync`. This document is a snapshot — the seeder wins on any mismatch.

## 1. Rules

1. **Permission names are an API contract.** `routes/api.php` checks them by name, so changes are additive-only and require API contract review.
2. **Deny by default.** Every `/api/v1` route requires authentication, an active account, and a named permission (or role) — ADR-0006.
3. **Permissions say what, branch scope says where.** A user can only act inside their assigned branches regardless of permissions (ADR-0005). The user directory (`users.view`) is the one global list: users are records, branches are assignments.
4. **`branches.view_all` is the only branch-scope widener** (admin only). Every other role sees only its assigned branches in `/branches` and in scoped queries.
5. **Privilege ceiling.** A manager may only assign roles whose permission set stays inside their own privileges (`You may not assign privileges you do not hold.`), and only branches they are assigned to (`You may not assign a branch you are not assigned to.`). The same subset rule governs custom role creation. An admin therefore always holds every permission including `users.manage` and `roles.manage`.
6. **System roles are immutable through the API** (`This role is a system role and cannot be modified or deleted.`) and are re-synced by `roles:sync`. Only installation-defined custom roles (via `roles.manage`) can be updated or deleted; a role still assigned to users cannot be deleted.
7. **The last active administrator cannot be deactivated or demoted** (`The last active administrator cannot be deactivated or demoted.`), and nobody can deactivate their own account (`You cannot deactivate your own account.`).
8. **Deactivation keeps tokens but blocks them.** The `active` middleware rejects tokens of deactivated users at the door; tokens are not deleted, so reactivation restores sessions.
9. **`is_available_for_leads` is a user flag, not a permission.** It narrows lead-assignment pickers (`/users/assignable`) independently of role.
10. **`primary_branch_id` must be within the branch assignment** (`The primary branch must be one of the assigned branches.`); when a branch set changes without naming a primary, an out-of-set primary is cleared.

## 2. Role catalogue

| Role | Permissions | Intent |
|---|---|---|
| `admin` | 86 (all) | Full installation control, including user/role management and `branches.view_all`. |
| `finance_manager` | 72 | Accountant scope plus posting own drafts, approval rights, and read-only visibility of the user/role directory (`users.view`, `roles.view`). |
| `senior_accountant` | 71 | Accountant scope plus posting/deleting own or branch journal drafts (maker-checker override, audited). |
| `accountant` | 69 | Full accounting operations: CoA, periods, journals, parties, payments, reports — without user management or draft posting. |
| `supervisor` | 29 | Sales scope plus staff visibility, exports, and the user directory (`users.view`). |
| `sales` | 26 | Customer-facing pipeline: parties, bookings, receipts, leads — no accounting setup. |
| `reservations` | 21 | Bookings and parties without accounting power; leads without conversion or rule management. |
| `cashier` | 12 | Collections, refunds, and transfers at the counter. |
| `viewer` | 18 | Read-only baseline across operational reports. |

`finance_manager`, `senior_accountant`, and `supervisor` are defined by extension (`@accountant` / `@sales`) in the seeder; the counts above are the expanded sets.

## 3. Matrix

`✓` = granted. Derived mechanically from the seeder constants (86 permissions × 9 roles).

### Branches & settings

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `branches.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `branches.create` | ✓ | — | — | — | — | — | — | — | — |
| `branches.view_all` | ✓ | — | — | — | — | — | — | — | — |
| `settings.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `settings.update` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `accounting.approve_policy` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Chart of accounts

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `accounts.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `accounts.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `accounts.update` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `accounts.post` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Fiscal periods

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `periods.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `periods.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `periods.post_soft_closed` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `periods.close` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `periods.reopen` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Journal

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `journal.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `journal.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `journal.reverse` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `journal.post_own_drafts` | ✓ | ✓ | ✓ | — | — | — | — | — | — |
| `journal.delete_drafts` | ✓ | — | ✓ | — | — | — | — | — | — |

### Customers

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `customers.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| `customers.update` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |

### Suppliers

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `suppliers.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| `suppliers.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| `suppliers.update` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |

### Supplier obligations & payments

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `supplier_obligations.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| `supplier_obligations.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `supplier_payments.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | ✓ |
| `supplier_payments.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `supplier_payments.reverse` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Bookings

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `bookings.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| `bookings.create` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `bookings.update` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `bookings.transition` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `bookings.cancel` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `bookings.approve_margin` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `bookings.override_credit_limit` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Payments

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `payments.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `payments.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `payments.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | ✓ | — |
| `payments.reverse` | ✓ | ✓ | ✓ | ✓ | — | — | — | ✓ | — |

### Refunds

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `refunds.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `refunds.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Reports

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `reports.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reports.export` | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | — |

### Leads (CRM)

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `leads.view` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | ✓ |
| `leads.create` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `leads.update` | ✓ | — | — | — | ✓ | ✓ | ✓ | — | — |
| `leads.convert` | ✓ | — | — | — | ✓ | ✓ | — | — | — |
| `leads.manage_rules` | ✓ | — | — | — | ✓ | ✓ | — | — | — |

### Cash accounts & transfers

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `cash_accounts.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `transfers.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | ✓ | ✓ |
| `transfers.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | ✓ | — |
| `transfers.reverse` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Expenses

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `expense_categories.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `expenses.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `expenses.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| `expenses.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Opening balances

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `opening_balances.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `opening_balances.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `opening_balances.post` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `opening_balances.reverse` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Invoices

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `invoices.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `invoices.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `invoices.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Cost centers

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `cost_centers.view` | ✓ | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| `cost_centers.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Employees

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `employees.view` | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
| `employees.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Documents

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `documents.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `documents.create` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `documents.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Bank reconciliation

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `reconciliation.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `reconciliation.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `reconciliation.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Schedules

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `schedules.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `schedules.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `schedules.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Fixed assets

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `assets.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| `assets.manage` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| `assets.approve` | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |

### Users & roles (this work order)

| Permission | admin | finance_manager | senior_accountant | accountant | supervisor | sales | reservations | cashier | viewer |
|---|---|---|---|---|---|---|---|---|---|
| `users.view` | ✓ | ✓ | — | — | ✓ | — | — | — | — |
| `users.manage` | ✓ | — | — | — | — | — | — | — | — |
| `roles.view` | ✓ | ✓ | — | — | — | — | — | — | — |
| `roles.manage` | ✓ | — | — | — | — | — | — | — | — |

## 4. Known follow-ups

- **DEC-017 (segregation of duties) is open.** Approvals are separated by *permission*, not by identity: a user holding both create and approve rights for a document can approve their own request (e.g. `refunds.create` + `refunds.approve` on the same actor). Enforcing maker ≠ approver remains a decision to close — see `decisions-needed.md` and the 2026-11-11 accountant sign-off packet.
- **Sanctum token expiry and abilities are not yet enforced.** ADR-0006 calls for a dedicated ability/token for integration service accounts and login lockout; only deactivation and password-change revocation block tokens today.
- **Role sets are provisional pending owner review.** The implemented set (2026-11-12) supersedes the illustrative list in ADR-0006 §3 — see the ADR-0006 addendum. Naming and set tuning are expected during pilot onboarding.
- **TOTP 2FA** is a later enhancement per ADR-0006; no permission gates it yet.
