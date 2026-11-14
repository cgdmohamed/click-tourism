# ADR-0026 - Scheduled report e-mail (GAP-RPT-06)

- **Status:** Accepted (project owner request 2026-11-14: the e-mail uses the SMTP settings of the server environment).
- **Date:** 2026-11-14
- **Related:** ADR-0025 (background exports), ADR-0014 (export formats), `docs/deployment/coolify.md`.

## Context

GAP-RPT-06 was listed as out of scope for TE-039 (scheduled delivery). The owner asked for reports to be e-mailed and said the sending depends on the SMTP configuration already present in the environment (`MAIL_*`).

## Decision

1. **Schedules** (`report_schedules`, migration `2026_11_30_000100`): owner, branch, report (the same 13 keys as ADR-0025), format, fixed filters, an optional **period preset** (`yesterday`, `previous_week`, `previous_month`, `month_to_date`, `year_to_date`, `last_30_days`) that sets `from`, `to` and `as_of` at each run, frequency daily / weekly (1-7) / monthly (1-28), 1 to 10 recipient addresses.
2. **Runs at 06:00 server time** (the scheduler command `reports:send-scheduled` runs hourly at :05 and sends what is due). A run produces the file through the ADR-0025 export path **as the user who created the schedule** (their permissions and branch), mails it with the file attached, then deletes the job and the file: no copy is kept.
3. **SMTP from the environment only.** There is no SMTP setting in the application. While `MAIL_MAILER` is `log`, `array` or `null` nothing is attempted: the run records `mail_not_configured` (not counted as a failure) and `GET /feature-flags` exposes `mail_configured` so the screen says so.
4. **Nothing is mailed unless the export succeeded.** A failed export, a deactivated owner or a lost permission records `failed` with a short message; five consecutive failures pause the schedule (re-activating resets the count). Mail transport errors never put SMTP details in the stored message.
5. **API** (permission `reports.schedule`, owner-only; admin and finance manager hold it by default): `GET/POST /report-schedules`, `PATCH/DELETE /report-schedules/{id}`, `POST /report-schedules/{id}/run` (send now, same code path). The mail body contains no report data; the data is the attachment.
6. **Audit:** `report_schedule.created|updated|deleted|sent|not_sent` (ids and counts, never addresses or content); the export itself is audited as `report.exported`.

## Consequences

- **Data leaves the system by e-mail** to addresses chosen by a user with `reports.schedule`. The permission is limited to admin and finance manager, the screen warns that recipients receive the file, and the audit trail records who created each schedule and every send. Residual risk: a mistyped or compromised address.
- Needs a running scheduler (Laravel `schedule:run` every minute or `schedule:work`) and valid SMTP variables; without them schedules are stored but nothing is sent.
- Rollback: pause or delete the schedules, or set `MAIL_MAILER=log`. The table and routes are additive.
- Contract change: five routes, one resource, one feature-flag field, one permission. `openapi.yaml` is the source of truth.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Scheduled report e-mail using the environment's SMTP settings, with the new permission `reports.schedule` | Project owner | architect | 2026-11-14 | Owner instruction in the implementation session: "ارسال التقارير بالبريد يعتمد على اعداد SMTP الموجود في env" |
