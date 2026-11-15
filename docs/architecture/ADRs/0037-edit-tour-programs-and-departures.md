# ADR-0037 - Edit tour programs and departures

- **Status:** Accepted (project owner request 2026-11-15: "ليه مش قادر اعدل على البرامج والرحلات السياحية؟").
- **Date:** 2026-11-15

## Context

Programs and departures could only be created, shown, closed. A typo in a name, a new price, a wrong seat count or a wrong date meant creating a duplicate.

## Decision

1. **`PATCH /tour-packages/{package}`** (`bookings.update`, branch-scoped): name, category, execution type, description, destination, base price, estimated cost, `is_active`. A name already used in the branch is refused; the execution type cannot change once departures exist. Prices apply to new bookings only; existing bookings keep the price they were created with. Currency and code are fixed. Audited as `tour_package.updated`.
2. **`PATCH /tour-departures/{departure}`** (`bookings.update`): start date, end date, capacity. Capacity cannot go below the seats taken, the end cannot precede the start, and the start date is locked while live (not cancelled / no-show) bookings hold places. Audited as `tour_departure.updated`.
3. **`POST /tour-departures/{departure}/reopen`** (`bookings.update`): reverse of close. Audited as `tour_departure.reopened`.
4. **Inactive programs take no new bookings** (`BookingService::create` refuses). No row is deleted; deactivating is reversible.
5. **UI:** Tour packages page gets edit program, activate/deactivate, edit departure, close/reopen.

## Consequences

- Contract: three additive routes, documented in `openapi.yaml`. No schema change or migration. Rollback: remove the routes and buttons.
- Not included: assistant actions for editing programs.
