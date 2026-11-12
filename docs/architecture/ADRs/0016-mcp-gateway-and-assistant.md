# ADR-0016 — MCP gateway and assistant (stage 1)

- **Status:** Accepted for stage 1 (project owner decision, 2026-11-12). The owner granted the architect approval that `mcp-ai-assistant-blueprint.md` section 12(1) required for the contract additions below, for this change only. Stages 2-4 are not approved.
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
7. **Deferred from the blueprint:** the `mcp.enabled` feature flag, the gateway's second branch filter via `/auth/me`, `X-Mcp-Conversation`, per-token daily row budget and circuit breaker, streamable HTTP transport.

## Consequences

- One additive permission, one additive config file, one additive setting default; no migration, no schema change.
- An MCP token can never do more than the three read-only GETs, even if the gateway is bypassed; authorization (permission + branch) stays in Laravel.
- Masking is best-effort on known keys: free text typed into notes may still contain contact details (the gateway also masks e-mail/phone patterns inside free-text fields).
- Any token with `mcp:read` and without `*` is treated as MCP; future limited-ability tokens must be classified explicitly.

## Rollback

Additive and reversible without data loss: stop the gateway process/container; revoke tokens (`DELETE /mcp/tokens/{id}` or `php artisan tinker` delete of `personal_access_tokens` named `mcp:%`); optionally remove `mcp.use` from roles (`php artisan roles:sync` after removing it from the seeder). Removing the code leaves no schema dependency; audit rows `mcp.*` simply stay as history.
