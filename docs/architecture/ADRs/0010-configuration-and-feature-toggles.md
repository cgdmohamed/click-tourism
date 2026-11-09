# ADR-0010 — Configuration and feature toggles

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + product

## Context

Each customer installs the same codebase with different branding, currencies, tax rules, branches, integrations, and enabled modules. Per-client code forks are forbidden.

## Decision

1. **Settings** are typed key/value records in `settings`, grouped by domain (`general`, `accounting`, `tax`, `crm`, `integrations`, `documents`). Values are validated by a settings schema; secrets are encrypted and write-only.
2. **Feature toggles** live in the same store (`features.*`) and are checked by middleware/UI. Disabling a module hides routes/menus and returns 403/404 without deleting data.
3. **Seed presets** (chart of accounts, tax, roles, sequences) are versioned data packs, applied at install, not code branches.
4. **No client names or IDs in code.** Branding comes from settings/templates.
5. **Configuration changes are audited** and require the appropriate permission.
6. **Environment variables** are limited to infrastructure (DB, Redis, mail, app key, URLs); business configuration belongs in settings.
7. **Base currency, fiscal-year start, and locale** are settings; changing base currency after postings is blocked.

## Consequences

- One codebase serves all customers.
- Settings schema and migration of setting keys need versioning discipline.
