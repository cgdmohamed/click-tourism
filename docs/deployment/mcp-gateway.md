# MCP gateway - creating a token and connecting a client

Stage 1 of `docs/architecture/mcp-ai-assistant-blueprint.md` (ADR-0016). Optional, off the production compose files, read-only. No AI provider is integrated: you bring your own MCP client.

## 1. Who may use it

Users holding the permission `mcp.use` (default roles: `admin`, `finance_manager`). The token can never exceed the user's own permissions and branches; it additionally only reaches `GET /search`, `GET /bookings` and `GET /invoices`.

## 2. Create a token

The token endpoints need a normal login token (an MCP token cannot create tokens). There is no UI yet, so use the API:

```bash
# 1. log in (SPA token)
SPA=$(curl -s -X POST "$ERP/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"..."}' | jq -r .data.token)

# 2. create an MCP token (default 8 h; max 24 h via expires_in_minutes up to 1440)
curl -s -X POST "$ERP/api/v1/mcp/tokens" -H "Authorization: Bearer $SPA" \
  -H 'Content-Type: application/json' -d '{"name":"Claude Desktop","expires_in_minutes":480}'
# -> data.token is shown ONCE. Store it in your client's secret config, not in git.

# list own tokens (no secrets) / revoke
curl -s "$ERP/api/v1/mcp/tokens" -H "Authorization: Bearer $SPA"
curl -s -X DELETE "$ERP/api/v1/mcp/tokens/<id>" -H "Authorization: Bearer $SPA"
```

Tokens expire without refresh; create a new one each day. A deactivated user's tokens stop working immediately.

Installation knobs (`backend/.env`): `MCP_TOKEN_DEFAULT_MINUTES` (480), `MCP_TOKEN_MAX_MINUTES` (1440, hard cap), `MCP_MAX_TOKENS_PER_USER` (10), `MCP_RATE_LIMIT_PER_MINUTE` (60 per token). Setting `mcp.mask_contacts` (Settings API, group `mcp`, default `true`) controls masking of phone/e-mail.

## 3. Run the gateway

On the machine of the person using the client (needs Node 20+):

```bash
cd mcp-gateway && npm ci
```

## 4. Connect Claude Desktop

Edit `claude_desktop_config.json` (Settings, Developer, Edit config):

```json
{
  "mcpServers": {
    "click-tourism-erp": {
      "command": "node",
      "args": ["/absolute/path/to/mcp-gateway/src/index.js"],
      "env": {
        "API_BASE_URL": "https://erp.example.com",
        "MCP_TOKEN": "<token from step 2>",
        "MCP_BRANCH_ID": "<optional branch public id>"
      }
    }
  }
}
```

Restart the client; the three `erp_*` tools appear. Use a dedicated profile with no other MCP servers (no web, e-mail or messaging tools) in the same session: that combination is the main exfiltration risk (threat T4).

## 5. Audit and revocation

Laravel records `mcp.token_created`, `mcp.token_revoked`, `mcp.tool_called` and `mcp.tool_denied` in `audit_events` (read with `GET /audit-events`, permission `audit.view`). Rows hold tool, route, branch header, status and token id only: no payloads or contact details.

To switch the feature off: stop the gateway, revoke tokens, optionally remove `mcp.use` from the roles.
