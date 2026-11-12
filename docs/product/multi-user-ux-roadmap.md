# Multi-User Collaboration and Frontend UX Roadmap

Status: **Proposed (planning only; no behavior change).** Date: 2026-11-12.
Source work order: `tourism_erp_agent_prompts/.../04-next-phase-readiness/12-multi-user-collaboration-and-frontend-ux-roadmap.md`.
Related: `permission-matrix.md`, ADR-0005, ADR-0006, ADR-0007, `docs/design/DESIGN.md`, `decisions-needed.md`, `docs/architecture/mcp-ai-assistant-blueprint.md`.

## 1. Product boundary: multi-user is not SaaS

- Each installation is **one legal entity** on its own server (`reference/PRODUCT_CONTEXT.md`, DEC-020). It has many users and many **branches**, which are operational and reporting dimensions of the same ledger (DEC-014), not tenants.
- No `tenant_id`, no cross-customer data, no shared control plane, no per-client code forks, no customer portals (DEC-022). Everything below ships as one codebase behind installation settings/feature flags.
- Collaboration features stay inside the installation: in-app notifications (no SMS/WhatsApp/email delivery in scope), no external sharing.

## 2. Gap inventory (verified on the current branch)

| Area | Present | Absent or weak | Evidence |
|---|---|---|---|
| Users and roles | User CRUD, activate/deactivate, 9 system roles, custom roles, privilege ceiling, last-admin guard | Invitation/provisioning flow (admin sets password), no self-service reset | `routes/api.php` users/roles; `UserService`; `permission-matrix.md` sections 1-2 |
| Branch access | Server-side `X-Branch-Id` validated against assignments; SPA branch switcher shown only when >1 branch; `BranchIsolationTest` | One branch per request; no "all my branches" aggregates; no dedicated branch-manager role (needs a custom role) | `BranchScope.php`; `web/src/components/Layout.tsx`; `web/src/auth/AuthProvider.tsx` |
| Session/token | Sanctum bearer; logout deletes token; password change revokes tokens; deactivated users blocked by `active` | 30-day default expiry (`SANCTUM_TOKEN_EXPIRATION=43200`), abilities unused, token in `localStorage`, no "my sessions" list, login throttle only by IP (`throttle:10,1`) | `config/sanctum.php`; `AuthController`; `web/src/lib/api.ts` |
| Concurrency (money) | `lockForUpdate` in services, `Idempotent` middleware on money actions | - | `PaymentService`, `InvoiceService`, `BookingService`, etc.; `Idempotent.php` |
| Concurrency (documents) | - | **No optimistic locking**: ADR-0007 section 8 is still "Proposed"; no `version`/`If-Match`/`409` stale handling found in controllers or migrations, so `PATCH` is last-write-wins | `ADRs/0007-*.md`; `PATCH` routes for customers, suppliers, bookings, invoices, leads |
| Audit | `audit_events` with actor, before/after, ip, request id | No generic read endpoint (only `fixed-assets/{asset}/audit-trail`); no UI timeline | `AuditService`; `FixedAssetController::auditTrail` |
| Activity | `LeadActivity` model for leads | No timeline for customers, bookings, invoices | `app/Modules/CRM/Models/LeadActivity.php` |
| Notifications | Outbox table (`OutboxEvent`) and event catalog | No user-facing notification store, bell, or polling; nothing in `web/src` | `docs/architecture/event-catalog.md` |
| Approvals | Many approval endpoints (expenses, invoices, accounting documents, refunds, margin, reconciliations, schedules, assets) | No unified inbox; approvers must open each module; DEC-017 open (only `refund_segregation` flag exists) | `routes/api.php`; `FeatureFlagController` |
| Assignment | `owner_user_id` on leads and bookings, `/users/assignable`, assignment rules (backend), 24h reassignment | Rules screen missing (noted in decision matrix); booking reassignment endpoint absent; picker gated by `leads.update` only | `decision-implementation-matrix.md`; `UserController::assignable` |
| Comments/mentions, presence | - | Not present | - |
| UI baseline | React + Tailwind tokens, i18n with `dir` switching (`i18n/index.tsx`), responsive shell (`lg:` sidebar, mobile drawer), global search, shared `ui.tsx` | Few ARIA attributes in shared UI (`aria-` appears 8 times in `ui.tsx`); no standard empty/error/loading component set audited; filters not URL-persisted; no data refresh strategy beyond React Query defaults | `web/src/components/ui.tsx`, `Layout.tsx`, `GlobalSearch.tsx` |

## 3. Epics and priorities

Order: **A Foundation fixes -> B Multi-user workflows -> C UX enhancements**. A items are prerequisites for B.

### Epic A - Foundation fixes (P1)

| ID | Item | Why | Contract impact |
|---|---|---|---|
| A1 | Optimistic locking (`row_version` integer column + `ETag`/`If-Match`, `409 stale_version`) on mutable documents: customers, suppliers, bookings, invoices (draft/submitted), leads, expenses, accounting documents, employees, tour packages | Prevents silent lost updates; implements ADR-0007 section 8 | Additive. Phase 1 optional header + warning, phase 2 required. **Needs architect approval** (shared schema + API). |
| A2 | Token hardening: default expiry <= 12h for SPA, ability checks, `GET/DELETE /auth/tokens` for own sessions, admin revoke-all for a user, login lockout per account+IP | Multi-user installs share risk; ADR-0006 follow-up | Additive; needs architect approval. **Partly done for MCP tokens only (ADR-0016, 2026-11-12):** ability `mcp:read`, `mcp.readonly` middleware, `/mcp/tokens` create/list/revoke, 8-24 h expiry. SPA token expiry, `/auth/tokens`, admin revoke-all and lockout remain open. |
| A3 | Generic audit read API `GET /audit-events` (filters: entity_type, entity_id, actor, date) behind new permission `audit.view`, with redaction of sensitive fields | Needed by activity feed and compliance | New permission is additive-only (permission-matrix rule 1). |
| A4 | Route coverage test: every `/api/v1` route (excluding the documented public list) declares a permission; every permission appears in matrix | Deny-by-default guarantee as users grow | Test only. |
| A5 | Branch UX fixes: switching branch clears cached queries; header always shows active branch; multi-branch users get explicit "all my branches" report option only where reports support it | Prevents cross-branch confusion | UI + optional report param; check with architect. |
| A6 | DEC-017 segregation of duties: keep **PENDING_ACCOUNTANT_APPROVAL**. Until closed, UI only shows a non-blocking "created by you" badge on approval items; enforcement beyond `refund_segregation` is not implemented. | Never infer accountant approval | None. |

### Epic B - Multi-user workflows (P2)

| ID | Item | Design notes |
|---|---|---|
| B1 | Conflict handling UX | On 409 show "changed by <user> at <time>", offer reload and field-level compare; never auto-merge financial fields. Depends on A1. |
| B2 | Notification center | New `notifications` table (user_id, type, entity ref, data, read_at, created_at), written by services/outbox consumers for: assigned to you, approval requested for you, your item approved/rejected, mention. In-app bell with polling every 60s via React Query (no websockets, no external channels). Branch and permission filtered at read time. |
| B3 | Approvals inbox | `GET /approvals` read-only aggregator returning pending items the caller can approve (`expenses.approve`, `invoices.approve`, `documents.approve`, `refunds.approve`, `bookings.approve_margin`, `reconciliation.approve`, `schedules.approve`, `assets.approve`), filtered by branch scope; actions call the existing endpoints (idempotent). No new approval logic. |
| B4 | Assignment | Reassign booking owner (`PATCH` field with audit), reassign lead owner, workload view per user; picker permission for bookings; assignment-rules management screen (backend exists). |
| B5 | Activity feed | Per-record timeline (customer, booking, invoice, lead) from `audit_events` via A3 plus `LeadActivity`; user-facing text, redacted before/after, localized AR/EN. Global feed filtered by branch for supervisors. |
| B6 | Comments and mentions (P3) | Internal notes on booking/customer/invoice; `@user` creates notification (B2). Only after B2 and A3. Plain text only (XSS surface, threat model). |
| B7 | Soft presence (P3, optional) | "N people viewing" via 30s heartbeat on detail pages. Informational only; locking guarantee comes from A1. |

### Epic C - UX enhancements (P2-P3, incremental, no SPA rewrite)

Use `docs/design/DESIGN.md` tokens and `web/src/components/ui.tsx`; do not introduce a parallel UI kit.

| ID | Item | Role focus |
|---|---|---|
| C1 | Standard state components: loading skeleton, empty, error-with-retry, forbidden; applied page by page | all |
| C2 | Table/filter pattern: URL-persisted filters, saved per-user default filters (localStorage, per-viewer convenience only), sticky header, column priority on mobile | accountant, sales |
| C3 | Accessibility pass: labels, focus order, `aria-*` on dialogs/menus/toasts, keyboard shortcuts for global search, visible focus ring, RTL/LTR icon mirroring | all |
| C4 | Booking agent flow: multi-step booking form with save-draft, inline validation, totals sidebar, keyboard-friendly item entry | reservations, sales |
| C5 | Accountant flow: period/close checklists surfaced on dashboard, journal/document review queue sorted by age (links to B3) | accountant, finance_manager |
| C6 | Manager/branch-manager dashboard: branch pipeline, overdue follow-ups, pending approvals count, per-user workload | supervisor, finance_manager, custom branch roles |
| C7 | Sales flow: lead list kanban/table toggle, quick-assign, follow-up due indicators | sales, supervisor |
| C8 | Mobile pass at 360-768 px for list and detail pages used in the field (leads, bookings, payments) | sales, cashier |
| C9 | Role-aware navigation: hide modules the role cannot open (already based on `can`) plus explanatory forbidden page | all |

## 4. Role and branch-isolation test plan

Fixtures: two branches (A, B); users: admin, finance_manager, accountant, senior_accountant, supervisor, sales (A), sales (B), reservations (A), cashier (A), viewer (A), one user assigned to A and B, one deactivated user. Extend `backend/tests/Feature/BranchIsolationTest.php`, `ApiAccessTest.php`, `RoleManagementTest.php`, `UserManagementTest.php`, `AccountantRoleTest.php`; run with `php artisan test` (CI: `.github/workflows/ci.yml`).

| ID | Test | Expected |
|---|---|---|
| R1 | Route x role matrix generated from `RolePermissionSeeder` constants | 200/403 equals `permission-matrix.md` for every route (A4) |
| R2 | Sales (A) reads/updates/converts records in branch B (customers, suppliers, bookings, payments, leads, journal entries) | 403/404 on every verb; list endpoints return zero B rows |
| R3 | `X-Branch-Id` of unassigned branch | 403 "not assigned to the requested branch" |
| R4 | Multi-branch user switches A->B->A | data sets disjoint; no cache bleed in UI (Vitest on branch switch) |
| R5 | Only `branches.view_all` (admin) sees all branches in `/branches` | other roles see assigned only |
| R6 | Privilege ceiling: manager assigning a role/branch beyond own | 403 messages from permission-matrix rule 5 |
| R7 | Deactivated user token, mid-session | next request 403 `account_disabled`; reactivation restores |
| R8 | Last-admin deactivate/demote, self-deactivate | rejected |
| R9 | Export/search/dashboard endpoints respect branch and permission | foreign rows absent from `search`, `dashboard/summary`, reports and exports |
| R10 | Approvals inbox (B3) as each role | shows only items the role can approve in assigned branches |
| R11 | Notification feed (B2) | user never receives or reads another user's or foreign-branch notifications |
| R12 | Audit read API (A3) | requires `audit.view`; branch-filtered; sensitive fields redacted |

Concurrency tests (backend feature tests with sequential simulation of racing requests, plus a DB-level parallel test where PHPUnit allows):

| ID | Test | Expected |
|---|---|---|
| K1 | Two PATCHes to one booking with the same `If-Match` | first 200, second 409 `stale_version`; stored values equal first writer |
| K2 | PATCH without `If-Match` after phase 2 | 428 `precondition_required` |
| K3 | Approve same invoice/expense twice (two users, same time) | one posting; second gets idempotent replay or 409 state error; exactly one balanced journal entry |
| K4 | Same `Idempotency-Key` replay on payment/refund/supplier payment | one record, `Idempotent-Replayed: true` |
| K5 | Two users allocate the same payment to different invoices exceeding payment | total applied never exceeds payment amount (row lock) |
| K6 | Edit while another user changes booking status | edit rejected with 409; UI shows conflict dialog (B1) |
| K7 | Lead reassignment race (manual vs 24h job) | single owner, both actions audited, one notification per final owner |
| K8 | Editing a posted/closed-period document | still blocked regardless of version |

## 5. Task breakdown with acceptance tests

One task = one branch = one PR (AGENTS.md). Sizes: S <= 1 day, M 2-3 days, L ~1 week. All tasks need tests; schema/API changes need architect approval and a rollback note (additive migrations only, no destructive change, no `migrate:fresh` on customer data).

| Task | Epic | Depends | Size | Acceptance tests |
|---|---|---|---|---|
| T-A4 Route/permission coverage test | A | - | S | R1 passes; adding a route without permission fails CI |
| T-A1a ADR-0007 update + `row_version` migration (nullable default 1) + ETag on GET + optional `If-Match` | A | architect OK | M | K1 (when header present), existing tests unchanged; migration reversible |
| T-A1b Enforce `If-Match` (setting `concurrency.require_if_match`, default off) + SPA sends header | A | A1a | M | K2, K6; Vitest for header propagation |
| T-A2 Token hardening (expiry setting, own-token list/revoke, lockout) | A | architect OK | M | R7; expired token 401; revoked token 401; lockout after N failures |
| T-A3 `audit.view` + `GET /audit-events` + redaction | A | architect OK | M | R12; no secrets/PII in payload snapshot test |
| T-A5 Branch switch cache reset + active-branch indicator | A | - | S | R4 Vitest |
| T-B1 Conflict dialog | B | A1b | M | K6 e2e/Vitest; no field auto-merge |
| T-B2 Notifications (table, service, bell, polling) | B | A3 optional | L | R11; mark read; event for assignment and approval request |
| T-B3 Approvals inbox endpoint + page | B | B2 optional | L | R10; approving from inbox goes through existing endpoints; K3 |
| T-B4 Assignment (booking owner endpoint, picker permission, rules screen) | B | A1 | M | R2 for assignment; K7; audit entries present |
| T-B5 Activity timeline component for customer/booking/invoice/lead | B | A3 | M | timeline matches audit rows; AR/EN; branch filtering R2 |
| T-B6 Comments/mentions | B | B2, A3 | M | mention notifies; plain text only (XSS test) |
| T-B7 Soft presence | B | A1 | S | heartbeat expires; no effect on write authorization |
| T-C1 State components | C | - | M | each page shows loading/empty/error/forbidden; Vitest snapshot |
| T-C2 Filters/tables pattern | C | C1 | M | URL round-trip test; mobile column priority |
| T-C3 Accessibility pass | C | - | M | axe/lint checks in CI; manual keyboard script for 5 core flows; RTL visual check |
| T-C4..C8 Role flows (booking, accountant, manager, sales, mobile) | C | C1, B3-B5 as noted | M each | role walkthrough scripts with measurable steps (see section 6) |
| T-C9 Role-aware navigation/forbidden page | C | - | S | R1-aligned nav test per role |

## 6. UI flow audit scripts (acceptance basis for Epic C)

For each role perform the task on desktop (1280 px) and mobile (390 px), in Arabic (RTL) and English, with keyboard only once:

- **Booking agent (reservations):** find customer -> create booking with 3 items -> save draft -> reopen. Pass: <= 7 screens/steps, no lost input on validation error, totals visible throughout.
- **Sales:** open new lead -> log contact -> assign -> convert to booking. Pass: follow-up due state visible in list.
- **Accountant:** review approvals -> approve expense/document -> check period checklist. Pass: pending items reachable in <= 2 clicks from dashboard.
- **Manager / branch manager:** see branch pipeline, overdue follow-ups, pending margin approvals, reassign a lead. Pass: all on one dashboard.
- **Cashier:** record collection and print receipt on mobile width.

## 7. Backward compatibility, migration and rollback

- All changes are additive: nullable/defaulted columns, new tables (`notifications`), new endpoints, new permissions (`audit.view`), optional headers. Public routes, stable `public_id`s, historical journals and existing permission names are unchanged.
- `roles:sync` applies new permissions; admin automatically receives all.
- Rollback: feature settings (`concurrency.require_if_match`, notifications flag) default off; migrations include `down()`; no data backfill is required for rollback.
- Production deployment of any task requires human approval (AGENTS.md).

## 8. Open decisions

1. Architect approval of A1 (versioning contract), A2 (token/session policy), A3 (`audit.view`).
2. DEC-017 segregation-of-duties enforcement: **PENDING_ACCOUNTANT_APPROVAL**; the roadmap does not enforce beyond existing flags.
3. Whether a built-in `branch_manager` system role is wanted or custom roles suffice (permission sets are provisional per permission-matrix section 4).
4. Notification retention period and whether email delivery is ever in scope (currently no).
5. Whether comments (B6) and presence (B7) are in the product scope at all (P3, optional).
