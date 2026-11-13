# ADR-0017 - Assistant provider abstraction and OpenAI

- **Status:** Accepted for stage 3 (project owner decisions, 2026-11-13). Stage 4 (write/draft tools) stays BLOCKED and is not approved.
- **Date:** 2026-11-13
- **Related:** ADR-0016 (MCP gateway and assistant, stages 1-2), ADR-0005 (branch scoping), ADR-0006 (auth), ADR-0008 (API conventions), `mcp-ai-assistant-blueprint.md` (sections 4-11), `threat-model.md` section 6, `docs/deployment/assistant.md`.

## Context

ADR-0016 shipped a read-only MCP gateway and deliberately integrated no model provider. Stage 3 of the blueprint is the in-product, bilingual assistant. The owner decided: the provider is **OpenAI** for now (cloud), behind an abstraction so others can be added; **no transcripts are stored** server-side; write/draft tools remain **BLOCKED** (the assistant is strictly read-only); contact masking stays on by default.

## Decision

1. **Provider abstraction.** `App\Modules\Assistant\Contracts\AssistantProvider` (`name`, `isConfigured`, `chat`). Implementations: `none` (assistant disabled, nothing leaves the server) and `openai` (Laravel `Http` client, Chat Completions with function/tool calling). `ProviderManager` picks the one named by the setting `assistant.provider`; unknown names fall back to `none`. A new provider is one class plus one entry in `ProviderManager::PROVIDERS` and the settings validation.
2. **Secrets.** *(Amendment proposed in ADR-0019: the key may also be saved encrypted from Settings; not in force until that ADR is approved.)* `OPENAI_API_KEY` is read only from the environment (`config/services.php`). It is never stored in `settings`, returned, or logged; the Settings API refuses unknown `assistant.*` keys. The base URL (`OPENAI_BASE_URL`) is an environment value too, not a database setting, so an administrator cannot redirect the key to another host; it exists for proxies and for tests (`Http::fake`). Provider failures raise a `ProviderException` with a generic message: the response body is never copied into responses, logs or audit rows.
3. **Settings group `assistant`** (admin only, through the existing Settings API with validation): `enabled` (default `false`), `provider` (default `none`), `model` (blank = `gpt-4o-mini`, UNVERIFIED), `redact_pii` (default `true`), `privacy_url`; numeric limits `max_tool_calls_per_turn` (default 6), `max_output_tokens` (1024), `daily_token_budget` per user (200,000), `rate_limit_per_minute` per user (20) have **no stored default**: their defaults come from `config/assistant.php` / env and a stored setting overrides them (the same rule as `mcp.daily_row_budget`).
4. **Endpoint.** `POST /assistant/chat`, permission **`assistant.use`** (new; admin and finance_manager by default; additive). Stateless: the body carries the conversation (`user`/`assistant` messages only; `system` and `tool` roles are refused), `locale` `ar|en`, optional `X-Branch-Id`. Response `{data: {answer, citations[{tool, entity, id, route, as_of}], usage{prompt, completion, total}, truncated}}`. Error codes: `403 assistant_disabled`, `503 assistant_not_configured`, `429 assistant_budget_exceeded`, `429 assistant_rate_limited` (named limiter `assistant-chat`), `502 assistant_provider_error`. `GET /feature-flags` adds `assistant_enabled`, `assistant_provider`, `assistant_redact_pii`, `assistant_privacy_url`.
5. **Server-side tool loop.** The model may call only the 15 read tools of the MCP catalogue. The catalogue has one source, `mcp-gateway/src/schemas.js`; `backend/resources/assistant/tools.json` is generated from it (`npm run export:catalogue`) and `AssistantCatalogueParityTest` fails when the snapshot, the gateway export or `McpReadOnly::ALLOWED` diverge. The model cannot choose the branch (`branch_id` is removed from the schemas offered). Each call is validated against the schema, then executed as an **internal GET sub-request authenticated as the current user** through the router, so the route's own middleware applies unchanged: permission, `BranchScope`, validation. The sub-request is built only from the validated arguments (no body, nothing else the model produced). A missing permission is returned to the model as the tool's `forbidden` result. Contact masking (`McpReadOnly::mask`), the per-user daily row budget (same resolution as the MCP middleware), the report limiter, the untrusted-data envelope (a PHP port of `mcp-gateway/src/sanitize.js`) and truncation apply to every result. Tool names outside the catalogue are rejected as tool results; calls per turn are capped, after which the model is called once more with tools disabled.
6. **Redaction (AT-12).** With `redact_pii=true`, text typed by the user is stripped of phone numbers, e-mail addresses and long digit runs (placeholders `[PHONE_n]`, `[EMAIL_n]`) and tool results are masked structurally (contact keys, contact patterns in free text, e-mail domain). The placeholder map exists in memory for one request, only to render the user's own values back and to resolve them in tool arguments; it is never persisted.
7. **Output.** The answer is reduced to plain text (HTML, markdown images/links and URLs removed). Citations are built by the server from the tool calls actually made (one per successful call, plus rows whose id the answer names); the model cannot add one. The web client turns them into internal links only.
8. **No transcripts.** Nothing about a conversation is stored. The audit rows are `assistant.chat` (user, branch, tool names, call and error counts, token counts, status, provider, model; no content, no PII) and `assistant.denied` (reason).
9. **System prompt** is a versioned file, `backend/resources/assistant/system-prompt.v1.md` (bilingual-aware): read-only, answers only from tool results, always cites and states `as_of`, says when data is missing, refuses writes, treats record text as data, answers in the user's language.

### Data flow

```
Browser panel (conversation in memory only)
   | POST /assistant/chat  {messages, locale}  Bearer = the user's own login token, X-Branch-Id
   v
Laravel  permission assistant.use -> limiter assistant-chat -> enabled? key present? token budget?
   |  redact user text (memory-only map)  --->  OpenAI Chat Completions  (HTTPS, key from env)
   |                                              | tool_calls (names + JSON arguments)
   |  <---------------------------------------- v
   |  allowlist + schema check -> internal GET /api/v1/... as the SAME user
   |      (permission middleware, BranchScope, validation)  -> mask + envelope + truncate
   |  tool result (redacted)  --->  OpenAI ... (repeat up to the cap)  ---> final text
   v
sanitise text, build citations, audit assistant.chat (no content)  -> {answer, citations, usage, truncated}
```

## Privacy implications (the owner accepts these by enabling it)

- Questions and the business data needed to answer them (customer, supplier and lead names, amounts, dates, document numbers, free-text notes with contact details masked) are sent to OpenAI, a third-party cloud processor, from the customer's server. Masking removes phone numbers, e-mail addresses and tax ids on known fields and patterns; it is best effort for free text.
- OpenAI's retention and abuse-monitoring terms apply outside our control (`store: false` is sent, but providers may keep requests for a period). The customer is responsible for its contracts and legal basis. The panel shows a data-processing notice and an optional link (`assistant.privacy_url`).
- Nothing is stored by the ERP, so there is nothing to export or delete on our side.

## Consequences

- Additive: one permission, one settings group, one config file, one route, one resources folder; no migration. The gateway code is unchanged apart from an export script.
- The assistant never runs with more rights than the user: removing a permission or branch assignment removes the assistant's access at once.
- UNVERIFIED: the OpenAI request/response shapes and model names were written from documentation without internet access; a real call was never made by the builders (`docs/deployment/assistant.md` section 8 lists the acceptance checks).
- The PHP and Node sanitisers are two implementations of the same rules; both are tested but must be changed together.

## Rollback

Set `assistant.provider` to `none` (or `assistant.enabled` to `false`) and remove `OPENAI_API_KEY` from the environment. No schema or stored data depends on the assistant; removing the code leaves only `assistant.*` audit rows and an unused permission.
