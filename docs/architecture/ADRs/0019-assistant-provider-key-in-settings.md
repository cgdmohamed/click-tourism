# ADR-0019 - Assistant provider key entered from Settings (amends ADR-0017 §2)

- **Status:** **Accepted** (project owner decision 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require). Amends ADR-0017 §2 ("Secrets", environment only) for the rows in the Approval record below only.
- **Date:** 2026-11-13
- **Related:** ADR-0017 (assistant provider abstraction and OpenAI), ADR-0018, ADR-0010 (configuration and feature toggles), `docs/architecture/threat-model.md`, `docs/deployment/assistant.md`, `AGENTS.md` ("Never store secrets").

## Context

ADR-0017 §2 made `OPENAI_API_KEY` environment-only: never stored in `settings`. The project owner reported that this is unsuitable for the people who run the product: the client's administrator has no access to the server environment, so enabling the assistant depends on someone editing `.env` and restarting workers. The owner's words in the implementation session: "الاعتماد على وجود key داخل env غير مناسب للمستخدم" (relying on a key in the environment is not suitable for the user).

`AGENTS.md` says "Never store secrets". Read with ADR-0017, the intended meaning is: no secret in the repository or in plain, readable application data. This ADR proposes the narrow exception below and asks for it to be approved explicitly, rather than treating encryption as a loophole.

## Decision (proposed)

1. An administrator with `settings.update` may save the provider key with `PUT /api/v1/assistant/key` and remove it with `DELETE /api/v1/assistant/key`.
2. **Storage:** one row in `settings` (`assistant.openai_api_key`), `is_secret = true`, value encrypted with Laravel `Crypt` (AES-256-CBC/GCM keyed by `APP_KEY`). It is the only secret stored this way; the Settings API still refuses every other `assistant.*` key, including this one.
3. **Write-only:** no endpoint, resource, log line, audit payload or export returns the key. `GET /settings` returns `null` for secret rows; `GET /feature-flags` adds only `assistant_key_source` (`settings` | `env` | `none`).
4. **Precedence:** a saved key wins over `OPENAI_API_KEY`; deleting it falls back to the environment. Installers who prefer environment-only can leave the setting empty, and nothing changes for them.
5. **Audit:** `assistant.key_saved` / `assistant.key_removed` record who and the source before and after, never the value. The save route is throttled (10/min).
6. If `APP_KEY` changes the saved key cannot be decrypted; it is treated as absent (never as a credential) and must be entered again.

## Consequences

- Residual risk: a database dump combined with `APP_KEY` discloses the key. Both are already the root of trust for sessions and Sanctum tokens; the key is also revocable at the provider in one step.
- A user with `settings.update` can replace or delete the key but cannot read it back.
- Rollback: delete the `assistant.openai_api_key` row (or `DELETE /assistant/key`); the environment key, if present, applies. No schema change.
- Contract change: two routes, one feature-flag field. `openapi.yaml` is the source of truth for the shapes.

## Approval record

`AGENTS.md` and `docs/architecture/README.md` require architect approval for contract changes and for departing from an accepted ADR. Given by the project owner in the implementation session (branch `claude/clever-sagan-89spcf`, PR #76).

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Amend ADR-0017 §2: store the provider key encrypted in `settings` (items 1-6 above) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "أوافق على الخيار الأول وسجّل الموافقة" (store the key encrypted from Settings) (PR #76) |
| 2 | `PUT`/`DELETE /api/v1/assistant/key` and `assistant_key_source` in `GET /feature-flags` | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "أوافق على الخيار الأول وسجّل الموافقة" (store the key encrypted from Settings) (PR #76) |
