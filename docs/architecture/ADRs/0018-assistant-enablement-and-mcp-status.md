# ADR-0018 - Assistant enablement (model discovery and data-processing acceptance) and MCP status

- **Status:** Accepted for the rows in the **Approval record** below (project owner decisions 2026-11-13, granting the architect approval `AGENTS.md` and `docs/architecture/README.md` require for shared-contract changes). Signed for these rows only. Stage 4 (write/draft tools) stays blocked and is not affected.
- **Date:** 2026-11-13
- **Related:** ADR-0016 (MCP gateway), ADR-0017 (assistant provider abstraction and OpenAI), ADR-0006 (auth), ADR-0008 (API conventions), ADR-0010 (configuration and feature toggles), `docs/deployment/assistant.md`, `docs/deployment/mcp-gateway.md`, `docs/architecture/openapi.yaml`.

## Context

ADR-0017 shipped the in-product assistant behind `POST /assistant/chat`, but an administrator had to type the model id by hand and the panel could only tell them that the assistant was unusable, not why. Two gaps were reported by the owner:

1. **No model discovery.** `assistant.model` is a free-text setting. A typo, a retired model or a model the account cannot use fails only at the first chat with a generic `502 assistant_provider_error`, and the administrator has no way to see which models the account actually offers or which one to start with.
2. **No explicit consent step.** The data-processing notice was displayed by the panel, but nothing recorded that the person enabling the assistant had accepted that business data will leave the server to a third-party model provider (ADR-0017, "Privacy implications"). Flipping `assistant.enabled` to `true` was enough, so the enable switch and the consent were the same click in practice and there was no stored evidence of acceptance.

Separately, the MCP token list (ADR-0016) answers "which tokens exist for me/anyone", not "is the gateway in use on this installation". The Settings screen needs counts, the last time an MCP token was used and who used it — without exposing secrets.

## Decision

Additive only. No migration, no schema change, no new permission, no change to an existing response shape.

### 1. `GET /api/v1/assistant/models`

- Middleware: the existing authenticated group (`auth:sanctum`, `active`, `mcp.readonly`, `throttle:mcp-read`) plus `permission:settings.view`. Read-only: saving a model still needs `settings.update` through the Settings API.
- The selected provider (`assistant.provider`) fetches the list (OpenAI: `GET {base_url}/models` with the env key). `AssistantProvider` gains `models(): array`; `NoneProvider` returns `[]`.
- Discovery does not require `assistant.provider` to be chosen first: while it is still the default `none` (a fresh install) the endpoint uses the first provider whose key is present in the environment (`ProviderManager::discoveryProvider()`), so placing the key is the only setup step. The call never writes a setting; the tab saves `provider = openai` together with the chosen model.
- `App\Modules\Assistant\Support\ModelCatalogue` is a pure filter over that list (unit-testable, no I/O): non-chat families are excluded first (`embed`, `tts`, `whisper`, `transcri`, `audio`, `realtime`, `image`, `dall-e`, `sora`, `moderation`, `search`, `codex`, `computer-use`, `computer_use`, `davinci`, `babbage`, `ada`), then only the chat families `gpt-`, `o1`, `o3`, `o4`, `o5`, `chatgpt-` are kept; the provider's order is preserved.
- Recommendation is deterministic: the first id present from `PREFERRED` (`gpt-4.1-mini`, `gpt-4o-mini`, `gpt-4.1`, `gpt-4o`, `o4-mini`, `o3-mini`, `gpt-4-turbo`, `gpt-3.5-turbo`), else the newest id containing `mini`, else the newest id; ties are broken by the lexicographically smallest id so the answer never depends on the provider's ordering.
- Response `{data: {key_configured, provider, recommended, models: [{id, recommended}]}}`. Errors: `503 assistant_not_configured` (no provider key in the environment) and `502 assistant_provider_error` (provider call failed). The provider's error body is never copied into the response, logs or audit rows — it can name the account or the key.
- The credential is read from the environment only (`OPENAI_API_KEY`, via `config/services.php`); this endpoint adds no way to read, write or test it beyond "configured / not configured".

### 2. Data-processing acceptance gate

- New setting `assistant.data_processing_accepted_at`, default `''` (not accepted). It stores an ISO-8601 timestamp of when an administrator accepted the notice; it holds no user id, no IP address and no notice text (the notice itself is versioned in the panel and documented in `assistant.md`).
- Accepted values are `''` or an ISO-8601 date-time of at most 40 characters (`^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})?$`, parsed with `Carbon` inside a try/catch). Anything else is a `422` on `settings.<index>.value`.
- **Enabling requires acceptance.** In the same `PUT /settings` request that sets `assistant.enabled = true`, acceptance must already be stored or be part of the payload; otherwise the request fails with `422` and the message `Accept the data-processing notice before enabling the assistant.` keyed `settings.<index>.value`. This makes the consent a precondition of the switch, not a separate note.
- **Chat requires acceptance.** The gate order in `POST /assistant/chat` is: disabled (`403 assistant_disabled`) → not accepted (`403 assistant_not_accepted`, audited as `assistant.denied`, reason `not_accepted`) → no key (`503 assistant_not_configured`) → validation and limits. An installation that enables the assistant but has no stored acceptance (for example one created before this change, or one where the setting was cleared) is therefore refused instead of sending data.
- Enabling and disabling is audited as `assistant.enabled_changed` with `{enabled: bool}` only, written inside the settings transaction and only when the stored state actually changes.
- `GET /feature-flags` gains `assistant_key_configured` (the *selected* provider's credential, so it is `false` while `assistant.provider` is `none`), `assistant_accepted` and `assistant_model` (the effective model id), so the panel can explain *which* precondition is missing. `assistant_enabled` continues to mean "usable" (enabled and accepted and key present).

### 3. `GET /api/v1/mcp/status`

- Middleware: the authenticated group plus `permission:settings.view` (the same permission as the Settings screen). Read-only, no new permission.
- Counts every user's MCP tokens (name prefix `mcp:`, the existing convention) and returns:
  `{data: {enabled, tokens: {active, expired, total}, last_connection_at, recent}}`.
  `active` = not expired; `expired` = `total - active` (revoked tokens are deleted and not counted); `last_connection_at` = the newest `last_used_at` across MCP tokens or `null`.
- `recent` lists at most 10 tokens — never-used ones last, then newest `last_used_at` first, ties broken by newest id — each with `{id, name, state: active|expired, created_at, last_used_at, expires_at, user: {id (public id), name, email}}`. "Recent connections" means token use, not an MCP session: Laravel updates `last_used_at` on each authenticated request, and an idle gateway does not appear.
- Never returned: the token value, its hash, its abilities, request payloads or audit rows. The endpoint is a summary; the detail stays in `GET /mcp/tokens` (own tokens, or `?all=1` with `mcp.admin`) and the audit log (permission `audit.view`).
- Ordering uses portable raw SQL (`case when last_used_at is null then 1 else 0 end`) so SQLite (tests) and PostgreSQL (production) agree.

## Consequences

- The administrator can now see which models the account offers, get a deterministic starting point and understand why the assistant is unusable, without reading server logs.
- Consent is explicit, stored once and enforced both at the switch and at every chat call; the audit row makes the on/off transition traceable.
- Both endpoints are **additive contract changes**: two new `GET` routes, three new feature-flag fields, one new chat error code (`assistant_not_accepted`) and one new settings key. They are approved row by row in the Approval record below; existing clients are unaffected by the additions, and `openapi.yaml` ships with them as approved.
- No secrets are exposed: the model endpoint answers "configured or not", never the key or provider bodies; the status endpoint never returns token material or abilities.
- The filter and recommendation are heuristics over model ids. A future provider with different naming needs its own mapping; unknown ids are simply not offered (they can still be set by hand, because the settings validation remains the authority on what may be stored).
- Copying this file must not become a second source of truth for the contract: `docs/architecture/openapi.yaml` is.

## Approval record

`AGENTS.md` ("Avoid changing shared schemas/contracts without architect approval") and `docs/architecture/README.md` ("Approval required") make the rows below a precondition of merge, not of implementation. They are recorded the way ADR-0016/0017 recorded theirs: who granted it, in what role, on what date, and the work order that granted it.

| # | Contract change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | `GET /api/v1/assistant/models` (new route, response shape, `502`/`503` codes) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |
| 2 | `GET /api/v1/mcp/status` (new route, response shape) | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |
| 3 | `GET /api/v1/feature-flags`: added `assistant_key_configured`, `assistant_accepted`, `assistant_model`; `assistant_enabled` narrowed to enabled ∧ accepted ∧ key | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |
| 4 | `POST /api/v1/assistant/chat`: new `403 assistant_not_accepted`, ordered before the `503` | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |
| 5 | Setting `assistant.data_processing_accepted_at` plus the `422` enable guard | Project owner | project owner (data may leave the server) | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |
| 6 | Publishing the matching `docs/architecture/openapi.yaml` additions | Project owner | architect | 2026-11-13 | Owner decision in the implementation session: "I approve all 6 rows - record it" (branch `agent/assistant-onboarding-and-mcp-status`) |

How a future approval is recorded (the same form ADR-0016/0017 used):

1. Replace the cells with the approver's name, the role, the date and the place (a PR comment link, an issue, or a dated owner decision document). Every cell must be filled; a partial row is refused.
2. Change the status line to `Accepted for ... (project owner decisions, <date>)`, quoting what was granted and for which rows — exactly as ADR-0016 does for its contract additions.
3. `backend/tests/Feature/ContractApprovalTest.php` enforces this: it fails while a contract-changing ADR is unapproved, and it also fails if the status line says Accepted while any row is still a placeholder or missing a date. Fixing it requires the recorded approval, not a status edit.

Scope of this grant: the six additions listed above and nothing else. Any later change to these routes, shapes or codes — a new field, a changed status code, another flag — needs its own row and its own sign-off. The grant does not extend to stage 4 (write/draft tools), which remains blocked.

## Alternatives considered

- **Store a boolean `assistant.data_processing_accepted`.** Rejected: it records no when, so the audit trail "who turned it on after consent" would be incomplete, and setting it back to `false` would be indistinguishable from never accepted.
- **Require acceptance per user in the panel.** Rejected for this stage: the decision is installation-wide (one self-hosted client, one processor contract), and per-user consent would need a schema change and a UI that does not exist. Revisit if a client asks for it.
- **Return the raw provider model list.** Rejected: it is dominated by embeddings, audio and image models that cannot chat, and it exposes the account's full entitlements.
- **Put MCP status behind `mcp.admin`.** Rejected: the Settings screen already uses `settings.view` to display the MCP switch, and the payload contains no token secret. Revocation stays with the token endpoints.

## Risks

- **False negatives.** A newly named OpenAI family that does not match the prefixes is hidden from the picker. Mitigation: the list is advisory; `assistant.model` still accepts any well-formed id, and the filter is one small class with unit tests.
- **Provider availability.** `GET /models` is an extra upstream call when the Settings screen opens. It is read-only, rate-limited by the existing `mcp-read` limiter and returns `502` without leaking the body; the picker can fall back to free text.
- **Acceptance is a setting, not a signature.** An administrator with `settings.update` can still set the timestamp to an arbitrary valid date-time. The protection is procedural and audited (`assistant.enabled_changed`), not cryptographic.
- **Status counts are global.** Any user with `settings.view` sees that other users hold MCP tokens and their e-mail addresses. That is already true of the Settings screen in general and of `GET /mcp/tokens?all=1` (which additionally lists names and abilities); this endpoint returns less.

## Rollback

Set `assistant.enabled = false` (or `assistant.provider = none`) — chat stops immediately and the status/model endpoints keep answering harmlessly. Clearing `assistant.data_processing_accepted_at` to `''` disables enabling and chat on the next request. Removing the code leaves no schema or data dependency: only `assistant.*` audit rows, the two routes and the three feature-flag fields disappear, and `model_catalogue` has no persisted state.
