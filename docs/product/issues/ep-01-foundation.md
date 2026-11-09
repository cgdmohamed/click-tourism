# EP-01 — Foundation & Platform

Prerequisite for every other epic. Follow `templates/TASK_TEMPLATE.md` per issue.

---

## TE-001 — Repository scaffold, Docker Compose, and CI

**Goal:** A clean clone boots the Laravel API, React/Vite SPA, PostgreSQL, and Redis locally, and CI runs lint + tests.
**Scope in:** Directory layout, env templates, Docker Compose (api, web, db, redis, queue, scheduler), healthcheck, CI workflow skeleton, README run instructions.
**Scope out:** Business modules, production deployment hardening (TE-054).
**Dependencies:** None.
**API contract changes:** None.
**Acceptance criteria:**
- [ ] `docker compose up` from a clean clone runs migrations and serves API + SPA.
- [x] `/health` reports app/db/redis/queue.
- [ ] CI runs formatting, static analysis, backend tests, frontend typecheck/build on PR.
- [ ] No secrets committed; `.env.example` documents every required variable.
**Test commands:** `docker compose config`, backend `php artisan test`, frontend `npm run typecheck && npm run build`.
**Owned files:** root config, `docker/`, `.github/`, `backend/` bootstrap, `web/` bootstrap.
**Reviewer / sign-off:** Architect.

---

## TE-002 — Authentication and user lifecycle

**Goal:** Secure login/logout/session management and user administration.
**Scope in:** Sanctum setup, login, logout, password reset (token), password policy, user CRUD, deactivation, last-login, rate limiting.
**Scope out:** SSO, TOTP (later).
**Dependencies:** TE-001.
**API contract changes:** `/auth/login`, `/auth/logout`, `/auth/me`, `/users`.
**Acceptance criteria:** AC-CORE-001, AC-CORE-002; deactivated user tokens revoked; login attempts rate-limited; passwords hashed.
**Test cases:** valid/invalid login, lockout, deactivated user, password reset expiry, authorization of user admin.
**Owned files:** `backend/app/Modules/Core/Auth`, `Users`, migrations, tests; `web` auth screens later.
**Reviewer:** Architect + security check.

---

## TE-003 — Roles, permissions, and policy coverage

**Goal:** Granular RBAC with deny-by-default enforcement.
**Scope in:** Roles, permissions, user-role assignment, policies, permission middleware, route coverage test.
**Scope out:** Field-level permissions (later).
**Dependencies:** TE-002.
**API contract changes:** `/roles`, `/permissions`, `/users/{id}/roles`.
**Acceptance criteria:** AC-CORE-003; every route has an explicit policy/permission; unauthorized returns 403; unauthenticated returns 401.
**Test cases:** each role matrix row, missing permission, super-admin bypass, route enumeration test.
**Owned files:** `backend/app/Modules/Core/Access`, policies, tests.
**Reviewer:** Architect.

---

## TE-004 — Branches and branch scoping

**Goal:** Branch master data and enforced branch scoping across modules.
**Scope in:** Branch CRUD, user-branch assignment, active-branch context, reusable branch scope trait/policy, audit.
**Scope out:** Inter-branch accounting entries (TE-016).
**Dependencies:** TE-003.
**API contract changes:** `/branches`, `/users/{id}/branches`, branch filter conventions.
**Acceptance criteria:** AC-CORE-004, AC-CORE-005, AC-PARTY-005; branch filters applied to every list; cross-branch access denied by default.
**Test cases:** branch A user vs branch B data for read/list/update/export; multi-branch user; deactivated branch.
**Owned files:** `backend/app/Modules/Core/Branches`, shared scope trait, tests.
**Reviewer:** Architect.

---

## TE-005 — Installation settings and feature toggles

**Goal:** Per-installation configuration without code forks.
**Scope in:** Settings store (typed), branding, base currency, timezone, locale, fiscal-year start, feature toggles, write-only secrets, validation.
**Scope out:** Per-user preferences.
**Dependencies:** TE-004.
**API contract changes:** `/settings`, `/settings/features`.
**Acceptance criteria:** AC-CORE-006, AC-CORE-007; toggles hide modules without deleting data; base-currency change blocked after postings; secrets never returned.
**Test cases:** setting validation, toggle on/off, secret redaction, audit of changes.
**Owned files:** `backend/app/Modules/Core/Settings`, migrations, tests.
**Reviewer:** Architect.

---

## TE-006 — Audit trail and redaction

**Goal:** Append-only, queryable audit of sensitive actions.
**Scope in:** Audit event model, automatic model events, explicit financial events, redaction rules, filter API, retention config.
**Scope out:** External SIEM shipping (later).
**Dependencies:** TE-005.
**API contract changes:** `/audit-events`.
**Acceptance criteria:** AC-CORE-008, AC-SEC-002; before/after redacted; append-only enforced; login/export/post/reverse captured.
**Test cases:** redaction of secrets/PII, immutability, filters, retention job.
**Owned files:** `backend/app/Modules/Core/Audit`, migrations, tests.
**Reviewer:** Architect + security.

---

## TE-007 — Document numbering sequences

**Goal:** Unique, configurable document numbers.
**Scope in:** Sequence definitions per document type/branch/year, atomic increment, prefixes, gap policy, admin UI/API.
**Scope out:** External numbering services.
**Dependencies:** TE-005.
**API contract changes:** `/sequences`, sequence metadata on documents.
**Acceptance criteria:** AC-CORE-009, AC-CORE-010; concurrency-safe; no reuse; per-branch/year reset works.
**Test cases:** parallel creation, year rollover, gap policy, disabled sequence.
**Owned files:** `backend/app/Modules/Core/Sequences`, migrations, tests.
**Reviewer:** Architect.

---

## TE-008 — Attachments

**Goal:** Access-controlled file attachments on business documents.
**Scope in:** Upload/download/delete, type/size validation, storage abstraction, authorization, audit.
**Scope out:** OCR, previews beyond images/PDF.
**Dependencies:** TE-006.
**API contract changes:** `/attachments`.
**Acceptance criteria:** AC-CORE-011; no public URLs; disallowed types rejected; branch permission enforced.
**Test cases:** upload limits, unauthorized download, orphan cleanup, malware-extension rejection.
**Owned files:** `backend/app/Modules/Core/Attachments`, tests.
**Reviewer:** Architect + security.

---

## TE-009 — Observability and health

**Goal:** Operable installation.
**Scope in:** Structured logs, request IDs, health/readiness, queue/scheduler heartbeat, failed-job view, error reporting hooks (no business data).
**Scope out:** APM vendor integration.
**Dependencies:** TE-001.
**API contract changes:** `/health`, `/ready`.
**Acceptance criteria:** AC-CORE-012; failed jobs visible; logs include request ID and exclude secrets.
**Test cases:** simulated DB/Redis failure, failed job appears, log redaction.
**Owned files:** `backend/config`, `backend/app/Modules/Core/Health`, tests.
**Reviewer:** Architect.

---

## TE-010 — Design-system integration layer

**Goal:** Wire the supplied design system into the React app as the only UI source.
**Scope in:** Tokens/theme import, component adapters, RTL setup, Storybook/preview (if part of DS), usage rules.
**Scope out:** Inventing components or screens; product screens (EP-12).
**Dependencies:** TE-001 + **design system assets supplied**.
**Status:** **BLOCKED** — design system is missing from the repository. Do not start.
**API contract changes:** None.
**Acceptance criteria:** A sample screen renders using only DS components/tokens; RTL verified; no parallel UI kit.
**Test cases:** visual smoke, RTL snapshot, token usage lint.
**Owned files:** `web/src/design-system`, `web/tailwind.config`, theme files.
**Reviewer:** Frontend lead.
