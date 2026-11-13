# ADR-0016 — MCP gateway and assistant (stage 1)

- **Status:** Accepted for stages 1 and 2 (project owner decisions, 2026-11-12 and 2026-11-13; see the amendment at the end). The owner granted the architect approval that `mcp-ai-assistant-blueprint.md` section 12(1) required for the contract additions, for these changes only. Stage 3 (assistant UI) is decided separately in ADR-0017 (OpenAI behind a provider abstraction); stage 4 (write/draft tools) is BLOCKED and not approved.
- **Date:** 2026-11-12
- **Related:** ADR-0005 (branch scoping), ADR-0006 (auth), ADR-0008 (API conventions), `mcp-ai-assistant-blueprint.md`, `threat-model.md` section 6.

## Context

Customers want to ask questions about their ERP data from an AI client (for example Claude Desktop). The REST API already enforces permission, branch scope and audit per request, so the safest shape is a gateway that only calls that API with the end user's own token and has no database or filesystem access.

## Decision

1. **No AI provider is integrated.** The gateway is a plain MCP server that any MCP client can use. No provider keys exist anywhere; the in-product assistant stays out of scope.
2. **No assistant transcripts are stored.** Only the server-side audit rows below are written.
3. **Contact details are masked by default.** Setting `mcp.mask_contacts` (default `true`) makes Laravel mask `phone`/`email`/`mobile`/`whatsapp` values (and the contact text in search `subtitle`) in every response served to an MCP token. The gateway masks again as defense in depth (`MCP_MASK_CONTACTS`, default true). SPA tokens are never masked.
4. **Write tools stay blocked.** They are not implemented, not listed, and the middleware rejects every non-GET request.
5. **Contract additions (additive only):**
   - permission `mcp.use` (admin and finance_manager by default; documented in `docs/product/permission-matrix.md`);
   - Sanctum token ability `mcp:read`; MCP tokens are named `mcp:<name>` and carry only that ability;
   - `POST /mcp/tokens` (needs `mcp.use`; plaintext token returned once; name required; default 8 h, configurable up to 24 h via `MCP_TOKEN_DEFAULT_MINUTES`/`MCP_TOKEN_MAX_MINUTES`; at most 10 active MCP tokens per user), `GET /mcp/tokens` (own, no secrets), `DELETE /mcp/tokens/{id}` (revoke own). List and revoke need no permission so a user who lost `mcp.use` can still revoke. Admin revoke-any is deferred;
   - middleware `mcp.readonly`: a token with `mcp:read` and without `*` may only `GET` `/search`, `/bookings` and `/invoices`; everything else (writes, exports, settings, users, roles, audit, imports, token endpoints, other reads) is `403 mcp_scope_denied`. SPA login tokens carry `*` and are unaffected. The middleware runs before route-model binding so denied calls never answer 404;
   - named rate limiter `mcp-read` (default 60 requests/minute per MCP token, `MCP_RATE_LIMIT_PER_MINUTE`); other tokens are not limited by it;
   - audit events written by Laravel: `mcp.token_created`, `mcp.token_revoked`, `mcp.tool_called` (tool, route, branch header, status, token id) and `mcp.tool_denied` (method, route, token id). No payloads, no query strings, no PII.
6. **Gateway shape:** a standalone Node ESM package in `mcp-gateway/` using `@modelcontextprotocol/sdk` 1.32.1 over stdio, configured only by environment (`API_BASE_URL`, `MCP_TOKEN`, optional `MCP_BRANCH_ID`). Exactly three tools (`erp_search`, `erp_bookings_list`, `erp_invoices_list`) with the blueprint's JSON schemas, validated with Ajv before any request. Results are wrapped in a delimited data envelope; record text is control-char stripped, truncated to 500 characters, markup-neutralised and tagged `<untrusted_data>`. It is not added to any production compose file.
7. **Deferred from the blueprint (stage 1):** the `mcp.enabled` feature flag, the gateway's second branch filter via `/auth/me`, admin revoke-any, `X-Mcp-Conversation`, per-token daily row budget and circuit breaker, streamable HTTP transport. **Done in the 2026-11-13 amendment:** all of these except `X-Mcp-Conversation` and the streamable HTTP transport (still deferred).

## Consequences

- One additive permission, one additive config file, one additive setting default; no migration, no schema change.
- An MCP token can never do more than the three read-only GETs, even if the gateway is bypassed; authorization (permission + branch) stays in Laravel.
- Masking is best-effort on known keys: free text typed into notes may still contain contact details (the gateway also masks e-mail/phone patterns inside free-text fields).
- Any token with `mcp:read` and without `*` is treated as MCP; future limited-ability tokens must be classified explicitly.

## Rollback

Additive and reversible without data loss: stop the gateway process/container; revoke tokens (`DELETE /mcp/tokens/{id}` or `php artisan tinker` delete of `personal_access_tokens` named `mcp:%`); optionally remove `mcp.use` from roles (`php artisan roles:sync` after removing it from the seeder). Removing the code leaves no schema dependency; audit rows `mcp.*` simply stay as history.

## Amendment 2026-11-13: stage 2 (read expansion) and the stage-1 leftovers

Approved by the project owner (work order "finish all MCP work except write tools"). Additive only; no migration; write tools stay BLOCKED and are neither implemented nor listed.

1. **Feature flag.** Setting `mcp.enabled` (default `false`, validated `true`/`false` in `SettingController`, exposed as `mcp_enabled` in `GET /feature-flags`). While off: `POST /mcp/tokens` and every request made with an MCP token answer `403 mcp_disabled` (audited `mcp.tool_denied`, reason `disabled`); listing and revoking tokens still work. The gateway reports `mcp_disabled`.
2. **Second branch filter.** New helper `GET /mcp/context` (allowed for MCP tokens, not an MCP tool, not counted in the row budget) returns the public ids of the branches the token's user may use (the `BranchScope` rule). The gateway fetches it on the first call and every 5 minutes; a pinned `MCP_BRANCH_ID` or a `branch_id` argument outside the list is refused (`branch_not_allowed`) and an unavailable list fails closed. `/auth/me` itself stays denied to MCP tokens because it returns the user's e-mail, roles and full permission list.
3. **Admin revoke-any.** New permission `mcp.admin` (admin role only, documented in `permission-matrix.md`): `GET /mcp/tokens?all=1` lists every user's MCP tokens (with owner name and e-mail) and `DELETE /mcp/tokens/{id}` revokes any of them; the audit row `mcp.token_revoked` records `owner_id` and `revoked_by_admin`. Without the permission `all=1` is `403` and another user's token is `404`. `GET /mcp/tokens` also returns a `meta` block (`default_minutes`, `max_minutes`, `max_active_per_user`) so clients can offer valid expiry choices.
4. **Masking.** `mcp.mask_contacts` is validated `true`/`false` and changeable through `PUT /settings` (tested). `tax_id` is now masked as well (server and gateway).
5. **Stage 2 tools.** Tools 2-4, 6, 8-11 and 12-15 of the blueprint catalogue (15 in total), each mapped to one existing `GET` route and that route's existing permission, with the literal allowlist `McpReadOnly::ALLOWED` (no wildcards): `customers`, `customers/{customer}`, `suppliers`, `bookings/{booking}`, `invoices/{invoice}`, `payments`, `expenses`, `leads`, `reports/party-statement`, `reports/aging`, `reports/trial-balance`, `reports/income-statement`. Exports, settings, users, roles, audit, imports, journal entries, general ledger, employees and bank statements stay denied. Only `party_type` `customer` or `supplier` is accepted for party statements, read from the same combined input (query string and JSON body) that the controller validates, and a read-only tool request that carries body parameters is refused outright; this is enforced by the allowlist middleware as well as the gateway schema. The reports use the `mcp:read` ability (no separate `mcp:read:reports`): Laravel's `reports.view` already gates them.
6. **Limits** (blueprint section 8, values in `docs/deployment/mcp-gateway.md`): report limiter 6/min per token; daily row budget per token (setting `mcp.daily_row_budget`, default 5,000; config fallback `MCP_DAILY_ROW_BUDGET`; `429 mcp_budget_exceeded` before the call once used up); bulk-read flag `mcp.bulk_read` (10 pages of at least 20 rows in 300 s, once per window); gateway circuit breaker (5 consecutive network/5xx/429 failures pause calls for 60 s, `circuit_open`). Counters live in the application cache.
7. **Audit.** Every authenticated MCP request writes exactly one `mcp.tool_called` (with status, outcome, rows) or `mcp.tool_denied` (with reason) row; 401s have no user and write none; breaker pauses never leave the gateway.
8. **Verification.** `McpToolMatrixTest` (403/200 per tool from one table, route permissions, `permission-matrix.md` rows, nine roles), extended `McpTokenTest`, gateway unit tests and a stdio protocol test with the official SDK client against a fake API. A connection from a real desktop client (Claude Desktop) was not run: UNVERIFIED.

Rollback: set `mcp.enabled` to `false` (instant kill switch), stop the gateway, revoke tokens. Removing the code leaves no schema dependency: the new rows are settings, a permission and audit events.
