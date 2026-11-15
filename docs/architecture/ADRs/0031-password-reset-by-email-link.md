# ADR-0031 - Password reset by e-mailed link (amends ADR-0006)

- **Status:** Accepted (project owner question 2026-11-15: why the login flow has no reset password).
- **Date:** 2026-11-15
- **Related:** ADR-0006 (auth), ADR-0026 (the server mailer).

## Context

A forgotten password could only be fixed by an administrator through `PATCH /users/{id}`. The login screen had no way to ask for help.

## Decision

1. **Link by e-mail.** `POST /auth/forgot-password` sends a one-time link (Laravel's password broker; 60 minutes; one use) to the SPA page `/reset-password`. The answer is identical for an unknown, disabled or known account, so the form cannot be used to find accounts. Throttled to 5 per minute.
2. **Reset.** `POST /auth/reset-password` takes the e-mail, token and a new password (12 characters or more, confirmed). A disabled account cannot be reset. Success deletes every token of the user (all sessions end) and audits `user.password_reset_by_link`.
3. **Only when mail works.** With the `log`, `array` or `null` mailer the endpoint answers 503 `password_reset_unavailable`, the login page hides the link (`password_reset_available` in the public branding payload), and the screen tells the user to ask an administrator. The isolated demo has no mailer, so it does not offer it.
4. The administrator reset in the users screen stays.

## Consequences

- Contract: two public endpoints and one boolean on `/branding` (additive). No schema change (uses the framework's `password_reset_tokens`). Rollback: remove the routes and the link.
- Residual risk: whoever controls the mailbox controls the account; the link expires, works once, and ends other sessions.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Password reset by e-mailed link | Project owner | architect | 2026-11-15 | Question "ليه ... الفلو ناقص فيه زي reset password" |
