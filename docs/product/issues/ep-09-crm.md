# EP-09 — CRM and Leads

**Blocked by:** DEC-015/016/024.

---

## TE-041 — Leads, sources, stages, and activities

**Goal:** Capture and work leads through a configurable pipeline.
**Scope in:** Lead CRUD, sources, configurable stages (won/lost) and loss reasons, priority, consent/opt-out, activity timeline (call/note/message/stage change), follow-up dates, reminders, list/board/filters, duplicate detection.
**Scope out:** Marketing automation, lead scoring.
**Dependencies:** TE-004, TE-005, TE-022 (for conversion later).
**API contract changes:** `/leads`, `/lead-sources`, `/lead-stages`, `/lead-activities`.
**Acceptance criteria:** AC-CRM-001, AC-CRM-005, AC-CRM-007, AC-CRM-009.
**Test cases:** required fields, stage config changes, duplicate phone/email, consent enforcement, branch scope, Arabic content.
**Owned files:** `backend/app/Modules/CRM/Leads`, migrations, tests.
**Reviewer:** Product.
**Status:** partial — Lead create/update/list with sources/stages as seed data; no sources/stages API, lead detail or activity timeline (GAP-CRM-02, 03).

---

## TE-042 — Assignment rules and 24-hour reassignment

**Goal:** Route leads predictably and prevent them from going stale.
**Scope in:** Assignment rule engine (branch/source/round-robin/load/queue), manual reassignment with reason, assignment history, inactivity clock, scheduler job, business-calendar/timezone handling, max attempts, notifications, audit.
**Scope out:** ML routing, external CRM sync.
**Dependencies:** TE-041, DEC-015/016.
**API contract changes:** `/assignment-rules`, `/leads/{id}/assign`, scheduler job.
**Acceptance criteria:** AC-CRM-002..004.
**Test cases:** rule precedence, no eligible owner, deactivated owner, activity resets clock, timezone/DST boundary, repeated reassignment cap, idempotent job run.
**Owned files:** `backend/app/Modules/CRM/Assignment`, jobs, tests.
**Reviewer:** Product + architect.
**Status:** partial — Assignment rules API/UI; `LeadService::reassignStale()` has no scheduler or command (GAP-CRM-01, P0).

---

## TE-043 — Lead conversion and pipeline metrics

**Goal:** Convert leads without duplication and measure sales.
**Scope in:** One-way conversion to customer and optionally booking, link fields, duplicate conversion guard, conversion audit, pipeline metrics by stage/owner/source/branch/period, dashboard query.
**Scope out:** Reverse conversion/unmerge.
**Dependencies:** TE-041, TE-042, TE-025.
**API contract changes:** `/leads/{id}/convert`, `/reports/pipeline`.
**Acceptance criteria:** AC-CRM-006, AC-CRM-008.
**Test cases:** convert twice blocked, convert without customer data, branch mismatch, metrics after stage change, cancelled conversion rollback.
**Owned files:** `backend/app/Modules/CRM/Conversion`, tests.
**Reviewer:** Product.
**Status:** partial — `/leads/{id}/convert` works once; no pipeline metrics or duplicate detection (GAP-CRM-04, 05).
