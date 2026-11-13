# MCP gateway - enabling it, creating a token and connecting a client

Stages 1 and 2 of `docs/architecture/mcp-ai-assistant-blueprint.md` (ADR-0016). Optional, off the production compose files, read-only. No AI provider is integrated: you bring your own MCP client. Write tools do not exist (stage 4 is BLOCKED).

## 1. Switch it on (per installation)

MCP access is **off by default**. While it is off, no token can be created and every request made with an MCP token is refused with `403 mcp_disabled` (audited as `mcp.tool_denied`, reason `disabled`); the gateway reports `mcp_disabled` to the client.

An administrator turns it on in Settings (group `mcp`, key `enabled` = `true`), or through the API:

```bash
curl -s -X PUT "$ERP/api/v1/settings" -H "Authorization: Bearer $SPA" -H 'Content-Type: application/json' \
  -d '{"settings":[{"group":"mcp","key":"enabled","value":"true"}]}'
```

`GET /api/v1/feature-flags` exposes the state as `mcp_enabled`. Rollback is the same call with `false` (or stop the gateway and revoke tokens).

| Setting (`mcp` group) | Default | Meaning |
|---|---|---|
| `enabled` | `false` | master switch (`true` / `false`) |
| `mask_contacts` | `true` | mask phone, e-mail and tax id in every MCP response (`true` / `false`) |
| `daily_row_budget` | not stored by default | rows one token may read per day; whole number 100 to 9999999. When no value is stored the env value `MCP_DAILY_ROW_BUDGET` (default 5000) applies; a stored value overrides it |

## 2. Who may use it

Users holding the permission `mcp.use` (default roles: `admin`, `finance_manager`). A token can never exceed its owner's permissions and branches: each tool maps to one existing read-only `GET` route and Laravel applies that route's own permission (for example `invoices.view` for the invoice tools, `reports.view` for the four report tools). The full tool to permission table is in `docs/product/permission-matrix.md`. `mcp.admin` (default: `admin`) lists and revokes every user's MCP tokens. On an existing installation run `php artisan roles:sync` after upgrading so the new permission reaches the `admin` role.

Upgrading from stage 1: the new setting `mcp.enabled` defaults to `false`, so stage-1 tokens stop working until an administrator switches it on (section 1).

## 3. Create a token

In the web app: **Profile, "MCP access tokens"** (visible only with `mcp.use` and the flag on). Enter a name, choose the lifetime (up to the installation maximum), create. The token is shown once with a copy button; revoke it from the same list. Administrators with `mcp.admin` get an "All tokens" toggle to see and revoke anyone's tokens.

Or with the API (a normal login token is required; an MCP token cannot create tokens):

```bash
SPA=$(curl -s -X POST "$ERP/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"..."}' | jq -r .data.token)

curl -s -X POST "$ERP/api/v1/mcp/tokens" -H "Authorization: Bearer $SPA" \
  -H 'Content-Type: application/json' -d '{"name":"Claude Desktop","expires_in_minutes":480}'
# -> data.token is shown ONCE. Store it in your client's secret config, not in git.

curl -s "$ERP/api/v1/mcp/tokens" -H "Authorization: Bearer $SPA"             # own tokens (+ meta with the limits)
curl -s "$ERP/api/v1/mcp/tokens?all=1" -H "Authorization: Bearer $SPA"       # every user's tokens (mcp.admin)
curl -s -X DELETE "$ERP/api/v1/mcp/tokens/<id>" -H "Authorization: Bearer $SPA"   # revoke (any token with mcp.admin)
```

Tokens expire without refresh; create a new one when it expires. A deactivated user's tokens stop working immediately.

### Connection status (Settings screen)

`GET /api/v1/mcp/status` (permission `settings.view`) is the read-only summary the Settings, MCP screen shows. It counts every user's MCP tokens, so it is not a per-user view; it never returns a token value, its hash or its abilities.

```bash
curl -s "$ERP/api/v1/mcp/status" -H "Authorization: Bearer $SPA"
```

```json
{
  "data": {
    "enabled": true,
    "tokens": { "active": 3, "expired": 1, "total": 4 },
    "last_connection_at": "2026-11-13T09:12:44+00:00",
    "recent": [
      {
        "id": 12,
        "name": "Claude Desktop",
        "state": "active",
        "created_at": "2026-11-12T08:00:00+00:00",
        "last_used_at": "2026-11-13T09:12:44+00:00",
        "expires_at": "2026-11-13T18:00:00+00:00",
        "user": { "id": "usr_...", "name": "Sara Ahmed", "email": "sara@example.com" }
      }
    ]
  }
}
```

- `enabled` is the setting `mcp.enabled` (same value as the feature flag `mcp_enabled`).
- `tokens.active` counts tokens that are not expired; `expired` is `total - active`. A token is expired when `expires_at` is in the past (a token with no expiry never expires); revoked tokens are deleted and not counted.
- **"Recent connections" means token use, not a session:** `last_used_at` is updated by the ERP whenever a request arrives with that token. `last_connection_at` is the newest such timestamp across all MCP tokens, or `null` if no token has ever been used. A gateway that is running but idle does not appear.
- `recent` holds at most 10 tokens, never-used ones last, then newest `last_used_at` first (ties broken by newest token). It carries the owner (`id` is the public user id, plus name and e-mail), `state` (`active` / `expired`) and the timestamps — no secret material.
- This is a read-only endpoint. To cut access off, revoke the token (section 3) or switch `mcp.enabled` off (section 1); the next status call reports the new counts.

## 4. Installation knobs (`backend/.env`)

| Variable | Default | Meaning |
|---|---|---|
| `MCP_TOKEN_DEFAULT_MINUTES` | 480 | lifetime when none is requested |
| `MCP_TOKEN_MAX_MINUTES` | 1440 | hard cap on a lifetime (24 h) |
| `MCP_MAX_TOKENS_PER_USER` | 10 | active MCP tokens per user |
| `MCP_RATE_LIMIT_PER_MINUTE` | 60 | requests per minute per token |
| `MCP_REPORT_RATE_LIMIT_PER_MINUTE` | 6 | the four report tools, per token |
| `MCP_DAILY_ROW_BUDGET` | 5000 | daily row budget per token while the setting `mcp.daily_row_budget` has no stored value (a stored setting wins) |
| `MCP_BULK_PAGES` / `MCP_BULK_PAGE_MIN_ROWS` / `MCP_BULK_WINDOW_SECONDS` | 10 / 20 / 300 | bulk-read flag: this many pages of at least that many rows inside the window |

### Limits and what a client sees

| Situation | Response to the client | Audit |
|---|---|---|
| Per-token rate limit hit | `429 rate_limited` (gateway: `rate_limited`, `retry_after`) | `mcp.tool_called` with status 429 |
| Report tool limit hit | `429 rate_limited` | `mcp.tool_denied`, reason `report_rate_limited` |
| Daily row budget used up (rows counted from the response `data`; the check is made before the call, so one call may overshoot by up to one page) | `429 mcp_budget_exceeded` (gateway: `budget_exceeded`, `retry_after` until midnight) | `mcp.tool_denied`, reason `budget_exceeded` |
| Many large pages in a short window | calls continue until the budget blocks them | one `mcp.bulk_read` event per window (tool, pages, rows today) |
| Feature flag off | `403 mcp_disabled` | `mcp.tool_denied`, reason `disabled` |
| Route outside the allowlist, any non-GET, employee statements | `403 mcp_scope_denied` | `mcp.tool_denied`, reason `scope` |
| Permission or branch missing | `403` (gateway: `forbidden`) | `mcp.tool_called`, outcome `denied` |

Every authenticated MCP request writes exactly one `mcp.tool_called` or `mcp.tool_denied` row (actor, tool, route, status, rows, token id, request id; never payloads or contact details). Requests with a missing, expired or revoked token (401) have no user to attribute and write no row. Read the rows with `GET /audit-events` (permission `audit.view`).

## 5. Run the gateway

On the machine of the person using the client (Node 20+):

```bash
cd mcp-gateway && npm ci
```

Gateway environment: `API_BASE_URL` and `MCP_TOKEN` (required), `MCP_BRANCH_ID` (optional, pins a branch), `MCP_MASK_CONTACTS` (second masking layer), `MCP_BREAKER_THRESHOLD` (5) and `MCP_BREAKER_COOLDOWN_SECONDS` (60).

Safety behaviours of the gateway itself:

- **Branch filter.** On the first call and every 5 minutes it asks `GET /api/v1/mcp/context` for the branches of the token's user. A pinned `MCP_BRANCH_ID` (or a `branch_id` argument) outside that list is refused with `branch_not_allowed` before any data call; if the list cannot be fetched the call fails closed.
- **Circuit breaker.** After 5 consecutive upstream failures (network error, 5xx, or `429`), calls pause for 60 seconds and return `circuit_open` with `retry_after`; the first call after the cooldown is a probe. These paused calls never reach the ERP, so they have no audit row.
- Output handling: every record string is control-char stripped, truncated to 500 characters, markup-escaped and, for third-party text, tagged `<untrusted_data>`; results are capped at 64 KB.

## 6. Connect a client

The gateway is a stdio MCP server. Any client that can launch a local command works; give it the command and the environment below. Use the **absolute** path to `src/index.js` and put the token only in the client's own (private) config file.

### Claude Desktop

Settings, Developer, Edit config, then add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "click-tourism-erp": {
      "command": "node",
      "args": ["/absolute/path/to/mcp-gateway/src/index.js"],
      "env": {
        "API_BASE_URL": "https://erp.example.com",
        "MCP_TOKEN": "<token from step 3>",
        "MCP_BRANCH_ID": "<optional branch public id>"
      }
    }
  }
}
```

Fully restart Claude Desktop; the fifteen `erp_*` tools appear under the connector. If `node` is not on the desktop app's PATH, put the absolute path to the `node` binary in `command`.

### Claude Code

```bash
claude mcp add click-tourism-erp --env API_BASE_URL=https://erp.example.com --env MCP_TOKEN=<token> -- node /absolute/path/to/mcp-gateway/src/index.js
```

### Other clients (Cursor, VS Code, Windsurf, MCP Inspector, ...)

Same three pieces in each client's own syntax: command `node`, argument `/absolute/path/to/mcp-gateway/src/index.js`, environment `API_BASE_URL` / `MCP_TOKEN`. For example in a `.vscode/mcp.json` or Cursor `mcp.json` the entry is `{"servers": {"click-tourism-erp": {"command": "node", "args": ["..."], "env": {...}}}}` (VS Code) or the same `mcpServers` object as above (Cursor). With Docker instead of Node: `"command": "docker", "args": ["run", "-i", "--rm", "-e", "API_BASE_URL", "-e", "MCP_TOKEN", "click-tourism-mcp"]` and the variables in `env`.

Use a dedicated profile with no other MCP servers (no web, e-mail or messaging tools) in the same session: that combination is the main exfiltration risk (threat T4).

### What has and has not been verified

- **Verified in CI (`npm test`, `test/mcp-protocol.test.js`):** the gateway started as a child process over stdio and driven by the official `@modelcontextprotocol/sdk` `Client`: `initialize`, `tools/list` (15 read-only tools, no write tool) and `tools/call` for every tool against a local fake HTTP API, plus invalid arguments, a foreign pinned branch, `mcp_disabled` and a revoked token.
- **UNVERIFIED:** a real Claude Desktop (or other desktop client) connection to a running ERP. It needs the desktop application and an installation, neither available to the automated checks. The JSON above follows the documented `mcpServers` format of the client; confirm it on first use and report differences.

## 7. Tools (all read-only, one GET route each)

| Tool | Route | Needs |
|---|---|---|
| `erp_search` | `GET /search` | group filtered by the matching `*.view` |
| `erp_customers_list`, `erp_customer_get` | `GET /customers`, `/customers/{id}` | `customers.view` |
| `erp_suppliers_list` | `GET /suppliers` | `suppliers.view` |
| `erp_bookings_list`, `erp_booking_get` | `GET /bookings`, `/bookings/{id}` | `bookings.view` |
| `erp_invoices_list`, `erp_invoice_get` | `GET /invoices`, `/invoices/{id}` | `invoices.view` |
| `erp_payments_list` | `GET /payments` | `payments.view` |
| `erp_expenses_list` | `GET /expenses` | `expenses.view` |
| `erp_leads_list` | `GET /leads` | `leads.view` |
| `erp_party_statement` (customer or supplier only), `erp_aging`, `erp_trial_balance`, `erp_income_statement` | `GET /reports/...` | `reports.view` |

Never reachable with an MCP token: exports, settings, users, roles, audit data, imports, journal entries, general ledger, employees, bank statements, token endpoints, and every non-GET route.

## 8. Audit and revocation

Rows: `mcp.token_created`, `mcp.token_revoked` (an admin revocation records `owner_id` and `revoked_by_admin`), `mcp.tool_called`, `mcp.tool_denied`, `mcp.bulk_read`.

To switch the feature off: set `mcp.enabled` to `false` (all MCP tokens stop working at once), stop the gateway, revoke tokens, optionally remove `mcp.use` / `mcp.admin` from the roles.
