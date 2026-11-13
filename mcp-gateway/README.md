# Click Tourism ERP - MCP gateway (stages 1-2)

A small, read-only [Model Context Protocol](https://modelcontextprotocol.io) server. It lets an MCP client (for example Claude Desktop) ask questions about the ERP using the signed-in user's own token. Design: `docs/architecture/mcp-ai-assistant-blueprint.md`, decision: ADR-0016.

- Stateless sidecar: **no database, no filesystem access, no write tools, no AI provider keys**.
- Exactly 15 read-only tools (schemas in `src/schemas.js`): `erp_search`, `erp_customers_list`, `erp_customer_get`, `erp_suppliers_list`, `erp_bookings_list`, `erp_booking_get`, `erp_invoices_list`, `erp_invoice_get`, `erp_payments_list`, `erp_expenses_list`, `erp_leads_list`, `erp_party_statement`, `erp_aging`, `erp_trial_balance`, `erp_income_statement`. There is no write tool.
- Calls only those `GET` routes with `Authorization: Bearer <MCP_TOKEN>`. Laravel decides permissions and branch access; the token itself can do nothing else (`403 mcp_scope_denied`).
- Checks a pinned or requested branch against `GET /api/v1/mcp/context` (fails closed), reports `mcp_disabled` and `budget_exceeded`, and pauses after repeated upstream failures (circuit breaker).
- Every result is wrapped in an `<erp_data>` envelope. Record text (names, notes, memos) is treated as untrusted: control characters stripped, 500-character cap, `<`/`>` escaped, tagged `<untrusted_data field="...">`. Phone and e-mail values are masked.
- Transport: stdio. (Streamable HTTP is not implemented.)

## Configuration (environment only)

| Variable | Required | Meaning |
|---|---|---|
| `API_BASE_URL` | yes | ERP origin, e.g. `https://erp.example.com` (`/api/v1` is appended if missing) |
| `MCP_TOKEN` | yes | MCP token created in the ERP (never commit it) |
| `MCP_BRANCH_ID` | no | Pin a branch public id; sent as `X-Branch-Id`. Tool arguments cannot override it |
| `MCP_MASK_CONTACTS` | no | `true` (default). The ERP setting `mcp.mask_contacts` is authoritative; this is a second layer |
| `MCP_BREAKER_THRESHOLD` | no | consecutive upstream failures (network, 5xx, 429) before calls pause; default 5 |
| `MCP_BREAKER_COOLDOWN_SECONDS` | no | pause length; default 60 |

## Run

```bash
cd mcp-gateway
npm ci
API_BASE_URL=https://erp.example.com MCP_TOKEN=... node src/index.js
```

Docker (optional, nothing in the repo's compose files starts it):

```bash
docker build -t click-tourism-mcp mcp-gateway
docker run -i --rm -e API_BASE_URL -e MCP_TOKEN click-tourism-mcp
```

Claude Desktop (`claude_desktop_config.json`): see `docs/deployment/mcp-gateway.md`.

## Tests

```bash
npm test   # node:test: unit tests plus a stdio protocol test with the official SDK client (local fake API only)
```

## Logs

Only `tool=<name> ok=<bool>` lines go to stderr. Tokens, arguments and results are never logged.
