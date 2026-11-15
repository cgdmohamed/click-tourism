# ADR-0032 - Invite a new user by e-mail (amends ADR-0006 and ADR-0031)

- **Status:** Accepted (project owner approval 2026-11-15: "do it" to the proposal).
- **Date:** 2026-11-15

## Context

Adding a user needed the administrator to type a password and pass it on outside the system. No invitation was sent, the password was known to the administrator, and the new user was never asked to change it.

## Decision

1. **Password is optional on create.** `POST /users` without a password invites the user: the account is created with an unusable random password and an e-mail carries a link to choose their own (language = the locale chosen for the user). With a typed password nothing changes.
2. **Own token table and a week.** Invitation links use their own broker (`invites`, table `user_invite_tokens`, 7 days) so a 60-minute reset link never inherits that life. The link works once and ends any other session; it opens the same SPA page as a reset (`/reset-password?invite=1`). Audit `user.invited`, `user.invite_accepted`.
3. **No mailer, no invitation.** Without a working mailer `POST /users` with no password answers 422 `mail_not_configured` and creates nothing. If the mailer fails after the account is created, the account stays, the response says `invite_sent: false`, and the administrator can resend or set a password.
4. **Resend.** `POST /users/{user}/invite` (users.manage, throttled) for an active user who was invited and has not signed in (`invite_pending`).

## Consequences

- Contract: password optional on create, `invite_sent` on the create response, `invite_pending` on the account, the resend route. Schema: `users.invite_sent_at`, table `user_invite_tokens` (additive). Rollback: drop both; the optional password goes back to required.
- Residual risk: whoever controls the mailbox can accept the invitation within seven days.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Invite new users by e-mail; password optional on create | Project owner | architect | 2026-11-15 | Reply "do it, then check" to the invitation proposal |
