# ADR-0024 - MCP over Streamable HTTP (Claude and OpenAI) and the Settings > MCP setup tab

- **Status:** Accepted (project owner request 2026-11-14). No change to the Laravel API contract: the HTTP gateway calls the same read-only routes with the caller's own MCP token.
- **Date:** 2026-11-14
- **Related:** ADR-0016 (MCP gateway), `docs/deployment/mcp-gateway.md`, `docs/architecture/threat-model.md`.

## Context

The gateway only spoke stdio: a local process started by the MCP client with the token in its environment. That serves Claude Desktop, but OpenAI (Responses API, Agents SDK) and remote Claude clients (Claude Code `--transport http`) connect to a **remote** MCP server over HTTPS. The owner required both Claude and OpenAI support, with the instructions inside the product ("ربط mcp يجب ان يكون مدعوم claude + open ai وفي تبويب داخله التعليمات").

## Decision

1. **`mcp-gateway/src/http.js`**: a second entry point using the SDK's Streamable HTTP transport in **stateless JSON mode**. `POST /mcp` only; `GET /health` answers `{status: ok}` without a token and reveals nothing.
2. **Auth = the user's own MCP token**, sent per request as `Authorization: Bearer`. The gateway holds no token of its own, never stores or logs it, keeps only a hash-keyed cache of per-token breaker state (500 entries, one hour idle), and calls `/api/v1` with that token, so permissions, branch scope, masking, budgets, rate limits and audit are exactly those of the stdio gateway. An optional `X-Branch-Id` header pins a branch like `MCP_BRANCH_ID`.
3. **Same tools, same server**: `src/server.js` is shared by both entries (15 read-only tools, read-only annotations). No write tool exists on either transport.
4. **Browser protection**: a request with an `Origin` header is refused (403) unless it is listed in `MCP_ALLOWED_ORIGINS`; GET and other paths are refused. Request bodies are capped at 1 MB.
5. **TLS** is the deployment's job (Coolify/nginx). The setup tab warns about a non-HTTPS gateway address.
6. **Settings > MCP tab**: switch and status (moved from Integrations), token creation, per-client instructions (Claude Desktop stdio, Claude Code remote, OpenAI Responses API), copyable snippets that use a placeholder instead of a token, a gateway-address field remembered in the browser, a test section (`/health`, `tools/list`) and safety notes.

## Not supported (said in the tab)

- Custom connectors on claude.ai (browser) and the ChatGPT app's custom connectors require OAuth; this gateway authenticates with bearer tokens only. OAuth would be a separate decision.
- UNVERIFIED: a live connection from OpenAI's servers, Claude Code against a deployed gateway and Claude Desktop. The HTTP transport is verified with the official SDK client against a fake ERP API (`mcp-gateway/test/http.test.js`).

## Consequences

- A new public surface exists when an administrator deploys the HTTP gateway: it accepts only requests with a valid MCP token, which the ERP validates on every call; a stolen token is limited by the owner's permissions, read-only scope, expiry and revocation. Rollback: stop the gateway container (or turn `mcp.enabled` off, which makes every token answer `mcp_disabled`).
- The Docker image now has `ENTRYPOINT node` with default `src/index.js`; existing stdio use is unchanged, HTTP mode runs `src/http.js`.

## Approval record

| # | Change | Approver (name) | Role | Date | Where the approval was given |
|---|---|---|---|---|---|
| 1 | Remote HTTP transport for the gateway and the Settings > MCP tab with Claude and OpenAI instructions | Project owner | architect | 2026-11-14 | Owner instruction in the implementation session: "ربط mcp يجب ان يكون مدعوم claude + open ai وفي تبويب داخله التعليمات" |
