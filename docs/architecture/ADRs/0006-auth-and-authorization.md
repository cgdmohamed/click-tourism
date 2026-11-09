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
