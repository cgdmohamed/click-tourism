# ADR-0036 - The assistant sells trips, asks with dates and numbers, and is measured (amends ADR-0023, ADR-0029)

- **Status:** Accepted (project owner request 2026-11-15: improve the assistant's overall level, after a conversation where "اعمل رحلة عمرة بقيمة 35 الف" was answered with a list of written questions).
- **Date:** 2026-11-15

## Context

The assistant had no tool for tour packages or departures, so a request to create an Umrah trip fell back on the booking tool and its questions (dates, adults, children, notes), ignored "next month", and asked for optional details. It also had no way to ask for a date, and no way to be measured: a prompt change could only be judged by hand.

## Decision

1. **Trips.** `erp_tour_package_create` (a package for sale: name, kind, destination, selling price and expected cost per person; a package with the same name is refused so no twin is made) and `erp_tour_departure_create` (a dated departure with seats, found by package id, code or exact name; only a fixed-departure package takes one). Both are confirmation cards using the application's own routes and `bookings.create`. After a package is confirmed the assistant continues with its departure by itself, as it does after creating a customer.
2. **Date and number fields** in the assistant's form (`erp_ask_user`), so a missing date is one click on a calendar and a quantity a number, not a written question.
3. **Prompt version 6** (the previous file is renamed; `config/assistant.php` selects it): trips are packages, not bookings; amounts are whole units (35,000 is 3,500,000 minor); relative dates are resolved from today's date and stated; what the assistant can and cannot do is listed so it does not promise more.
4. **Measuring.** `php artisan assistant:eval` runs the scenarios of `resources/assistant/evals/scenarios.json` against the configured provider as a real user inside rolled-back transactions and scores the tools called, the card and its values, forms and refusals. It is run by hand (it costs tokens), before and after changing the prompt, the model or the tools.

## Consequences

- Contract: two tool names, two form field types. No schema change. Rollback: remove the tools and revert the prompt file.
- Residual risk: the model may still choose a booking for a trip request; the scenarios exist to show that, and the card names the kind of record before Confirm.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Trip tools, date/number form fields, prompt v6, assistant evals | Project owner | architect | 2026-11-15 | Message "حسن المستوى العام للمساعد في فرع جديد" with the conversation about the Umrah trip |
