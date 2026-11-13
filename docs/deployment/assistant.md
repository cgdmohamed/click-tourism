# In-product assistant - enabling it, settings, permissions and cost control

Stage 3 of `docs/architecture/mcp-ai-assistant-blueprint.md`, decided in ADR-0017; the model picker endpoint and the data-processing acceptance gate are ADR-0018 (Proposed). Optional, **off by default**, strictly read-only. The model provider is **OpenAI** (cloud); other providers can be added behind the `AssistantProvider` interface. Write or draft tools do not exist (stage 4 stays BLOCKED). Nothing is stored about conversations.

> **Data-processing notice (owner decision required).** Turning the assistant on sends the user's questions and the ERP data needed to answer them (after masking, see section 5) to OpenAI, a third-party cloud provider, from your server. OpenAI's own retention and abuse-monitoring rules apply to that data; check them against your customer contracts and local law before enabling. Real calls to OpenAI have **not** been verified by the team that built this (no internet or key was available): run the acceptance checks in section 8 once with your own key.

## 1. Enable it (all four steps)

1. **Key, environment only.** Put the key in `backend/.env` (never in the database, settings or git):

   ```
   OPENAI_API_KEY=sk-...
   # OPENAI_BASE_URL=https://api.openai.com/v1   # only for a proxy / compatible endpoint
   ```

   Restart the PHP workers (or `php artisan config:cache` again) so the value is read. The key is never returned by any endpoint, never written to settings or logs, and the Settings API refuses to store it.
2. **Settings** (admin, group `assistant`, Settings → Assistant tab). The tab reads `GET /api/v1/feature-flags` for `assistant_key_configured` (does the PHP environment see the key?), then `GET /api/v1/assistant/models` for the models this key's account can use (see section 3).
   1. Pick a model from the list the API returned (`recommended` is preselected). Saving it also sets `provider` = `openai`; by hand that is `provider` = `openai` plus `model` = one of the listed ids.
   2. Read the data-processing notice and accept it: this stores `data_processing_accepted_at` (ISO-8601 timestamp; empty means not accepted). This is required before the switch: without it a request that sets `enabled` = `true` is refused with 422 "Accept the data-processing notice before enabling the assistant." Acceptance may be stored first or in the same payload.
   3. Set `enabled` = `true`. Storing it writes the audit event `assistant.enabled_changed` (`{enabled: true}`), and switching it off writes `{enabled: false}`.

   All three in one call (acceptance and switch together):

   ```bash
   curl -s -X PUT "$ERP/api/v1/settings" -H "Authorization: Bearer $SPA" -H 'Content-Type: application/json' \
     -d '{"settings":[{"group":"assistant","key":"provider","value":"openai"},
                      {"group":"assistant","key":"model","value":"<model>"},
                      {"group":"assistant","key":"data_processing_accepted_at","value":"2026-11-13T09:00:00+00:00"},
                      {"group":"assistant","key":"enabled","value":"true"}]}'
   ```

3. **Permission.** Users need `assistant.use` (default roles: `admin`, `finance_manager`). On an existing installation run `php artisan roles:sync` after upgrading so the new permission reaches those roles; give it to other roles through a custom role (Roles screen). It adds no data access: the assistant only ever sees what the user's own permissions and branches allow.
4. **Check.** `GET /api/v1/feature-flags` shows `assistant_enabled: true`, `assistant_provider: "openai"`, `assistant_key_configured: true`, `assistant_accepted: true` and `assistant_model`; users with the permission then see the **Assistant** button in the header.

The panel appears only when the setting is on, the notice is accepted, a provider other than `none` is chosen **and** the key is present. Without acceptance `POST /assistant/chat` answers `403 assistant_not_accepted` (a legacy installation that was enabled before this requirement keeps working only after an administrator accepts the notice); without a key it answers `503 assistant_not_configured`; with the setting off `403 assistant_disabled`.

## 2. Rollback / kill switch

Set `assistant.provider` to `none` (or `assistant.enabled` to `false`): the button disappears and the endpoint refuses at once. Clearing `assistant.data_processing_accepted_at` also stops every chat with `403 assistant_not_accepted` without touching the key. Remove `OPENAI_API_KEY` from the environment to make sure nothing can be sent. There is no schema change and no stored conversation to clean up; `assistant.*` audit rows remain as history.

## 3. Settings (group `assistant`, admin only: `settings.update`)

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | master switch; cannot be set to `true` before the data-processing notice is accepted |
| `data_processing_accepted_at` | blank | ISO-8601 timestamp of the administrator's acceptance of the data-processing notice; blank = not accepted (ADR-0018). Not a secret |
| `provider` | `none` | `none` or `openai` |
| `model` | blank = `gpt-4o-mini` (config `ASSISTANT_DEFAULT_MODEL`) | model name sent to the provider. **UNVERIFIED default**: model names change; set the one you intend to pay for. `GET /assistant/models` lists what the key's account offers and marks a `recommended` model |
| `redact_pii` | `true` | replace phone numbers, e-mail addresses and tax ids before anything leaves the server (section 5) |
| `privacy_url` | blank | optional `https` link shown next to the data-processing notice in the panel |
| `max_tool_calls_per_turn` | not stored; env `ASSISTANT_MAX_TOOL_CALLS_PER_TURN`, default **6** | cap on tool calls per question; 1 to 10 |
| `max_output_tokens` | not stored; env `ASSISTANT_MAX_OUTPUT_TOKENS`, default **1024** | `max_completion_tokens` per model call; 64 to 8192 |
| `daily_token_budget` | not stored; env `ASSISTANT_DAILY_TOKEN_BUDGET`, default **200000** | prompt + completion tokens per **user** per day; 1000 to 100000000 |
| `rate_limit_per_minute` | not stored; env `ASSISTANT_RATE_LIMIT_PER_MINUTE`, default **20** | chat requests per user per minute; 1 to 600 |

The numeric settings have no stored default on purpose (like `mcp.daily_row_budget`): until an administrator stores a value, the environment value applies, so an operator can lower the limits in `.env`; a stored value overrides it. Values are validated by the Settings API; unknown keys (for example an attempt to store a key) are refused with 422.

Other technical limits (`backend/config/assistant.php`): 40 messages and 4000 characters per message per request, 60 s provider timeout (`ASSISTANT_PROVIDER_TIMEOUT`), 32 KB per tool result (`ASSISTANT_MAX_TOOL_RESULT_BYTES`), 8000 characters per answer.

### Choosing a model (`GET /api/v1/assistant/models`)

Permission `settings.view` (reading only; saving the model still needs `settings.update`). The endpoint asks the provider on every call, nothing is cached, and it never returns the key or the provider's error body.

```bash
curl -s "$ERP/api/v1/assistant/models" -H "Authorization: Bearer $SPA"
# {"data":{"key_configured":true,"provider":"openai","recommended":"gpt-4.1-mini",
#          "models":[{"id":"gpt-4.1-mini","recommended":true},{"id":"gpt-4o","recommended":false}]}}
```

- Only chat-capable ids are listed: embeddings, audio/TTS/transcribe/realtime, image (`dall-e`, `gpt-image`), moderation, search, codex, computer-use and the legacy `davinci`/`babbage`/`ada` models are filtered out.
- `recommended` is deterministic: the first id present from `gpt-4.1-mini`, `gpt-4o-mini`, `gpt-4.1`, `gpt-4o`, `o4-mini`, `o3-mini`, `gpt-4-turbo`, `gpt-3.5-turbo`; otherwise the newest model whose id contains `mini`; otherwise the newest model. It is `null` when the list is empty.
- Discovery only needs the key: while `assistant.provider` is still the default `none` (a fresh install) the endpoint uses the first provider whose key exists, so putting `OPENAI_API_KEY` in the environment is enough to see the models. Saving a model from the tab also stores `provider` = `openai`; the setting is never written by the discovery call itself.
- `503 assistant_not_configured` when no provider key is present in the server environment (the key is never a setting); `502 assistant_provider_error` when the provider fails or cannot be reached.

Before the switch is turned on, the data-processing notice must be accepted (`assistant.data_processing_accepted_at`); `GET /feature-flags` reports `assistant_accepted`, `assistant_key_configured` and `assistant_model` so the tab can show the state.

## 4. What the assistant can do

- Read through exactly the 15 tools of the MCP gateway (`mcp-gateway/src/schemas.js` is the single source; `backend/resources/assistant/tools.json` is its snapshot, guarded by `AssistantCatalogueParityTest`). The model cannot name another tool or route and cannot choose the branch: the branch is the user's active branch (`X-Branch-Id`).
- Each tool runs as the **signed-in user** through the normal GET route (an internal sub-request), so the route's permission check, branch scope, validation, contact masking and the daily row budget (`mcp.daily_row_budget` or `MCP_DAILY_ROW_BUDGET`, counted per user) apply as for MCP. A missing permission comes back to the model as a `forbidden` tool result, never as data. Employee statements are not available.
- Answers cite their sources: the screen shows links to the records the assistant really read (built by the server, not by the model) with an "as of" time.
- It cannot create, change, approve, post, send, pay, delete or export anything, and the answer is plain text (no HTML, images or links).

## 5. Privacy: what leaves the server

With `redact_pii=true` (default) everything sent to OpenAI is redacted first:

- what the user types: phone numbers, e-mail addresses and long digit runs (9-15 digits, e.g. tax ids) become `[PHONE_n]` / `[EMAIL_n]`; the mapping lives in memory for that single request only (so a typed number still works as a search term and is shown back to the user) and is never stored;
- tool results: phone, e-mail, mobile, WhatsApp and tax id values are masked (`***42`, `n***@***`), contact details inside free text (notes, memos) are masked too, and all free text is wrapped as untrusted data.

Still sent: the question text, names of customers, suppliers and leads, amounts, dates, invoice and booking numbers and other business data returned by the tools. If that is not acceptable, do not enable the assistant. With `redact_pii=false` the user's typed text is sent as typed (tool results stay masked while `mcp.mask_contacts` is on).

Nothing is stored server-side: the browser keeps the conversation in memory only and sends it with each request; closing the page, signing out or changing branch clears it. The audit row `assistant.chat` records the user, branch, tool names, token counts and status, never question or answer text.

## 6. Cost control

- `daily_token_budget` per user per day: when used up the user gets `429 assistant_budget_exceeded` (audited `assistant.denied`); the check also runs between the model calls of one turn.
- `rate_limit_per_minute` per user, `max_tool_calls_per_turn`, `max_output_tokens` and the result/row caps bound the work of one question.
- Token usage is returned in every response (`usage`) and summed in `assistant.chat` audit rows, so a report by user and day can be built from `audit_events`. Set a spending limit in your OpenAI account too: the installation cannot see your invoice.

## 7. Operations

| Symptom | Cause |
|---|---|
| Button missing | no `assistant.use`, setting off, notice not accepted, provider `none`, or no key (check `GET /feature-flags`) |
| `403 assistant_not_accepted` | the data-processing notice was never accepted (setting `assistant.data_processing_accepted_at` empty); an administrator accepts it in Settings → Assistant |
| `503 assistant_not_configured` | `OPENAI_API_KEY` empty in the PHP environment (restart workers / clear config cache) |
| `502 assistant_provider_error` | OpenAI unreachable, key rejected, model name unknown or provider error; the response body is deliberately not copied anywhere; check the network and the key from the server |
| `429 assistant_rate_limited` / `assistant_budget_exceeded` | limits above |

## 8. Acceptance checks with a real key (UNVERIFIED so far)

1. Enable as above in a test installation (accept the notice, then switch it on); ask "list my customers" and confirm one answer with citations and an "as of" time.
2. Ask for something your role may not see; the answer must say it is not available.
3. With a proxy or request log on the outbound side, confirm no raw phone number or e-mail address appears in the request bodies, and that `store` is `false`.
4. Confirm the model name you set is accepted and that `max_completion_tokens` is accepted by that model (older models may require `max_tokens`).
5. Call `GET /api/v1/assistant/models` with the real key and confirm the list, the filtered non-chat ids and the `recommended` choice match what the OpenAI account offers (no live call was made by the builders).
