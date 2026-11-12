# Click Tourism ERP - MCP gateway (stage 1)

A small, read-only [Model Context Protocol](https://modelcontextprotocol.io) server. It lets an MCP client (for example Claude Desktop) ask questions about the ERP using the signed-in user's own token. Design: `docs/architecture/mcp-ai-assistant-blueprint.md`, decision: ADR-0016.

- Stateless sidecar: **no database, no filesystem access, no write tools, no AI provider keys**.
- Exactly three tools: `erp_search`, `erp_bookings_list`, `erp_invoices_list` (schemas in `src/schemas.js`).
- Calls only `GET /api/v1/search|bookings|invoices` with `Authorization: Bearer <MCP_TOKEN>`. Laravel decides permissions and branch access; the token itself can do nothing else (`403 mcp_scope_denied`).
- Every result is wrapped in an `<erp_data>` envelope. Record text (names, notes, memos) is treated as untrusted: control characters stripped, 500-character cap, `<`/`>` escaped, tagged `<untrusted_data field="...">`. Phone and e-mail values are masked.
- Transport: stdio. (Streamable HTTP is not implemented.)

## Configuration (environment only)

| Variable | Required | Meaning |
|---|---|---|
| `API_BASE_URL` | yes | ERP origin, e.g. `https://erp.example.com` (`/api/v1` is appended if missing) |
| `MCP_TOKEN` | yes | MCP token created in the ERP (never commit it) |
| `MCP_BRANCH_ID` | no | Pin a branch public id; sent as `X-Branch-Id`. Tool arguments cannot override it |
| `MCP_MASK_CONTACTS` | no | `true` (default). The ERP setting `mcp.mask_contacts` is authoritative; this is a second layer |

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
npm test   # node:test, no network
```

## Logs

Only `tool=<name> ok=<bool>` lines go to stderr. Tokens, arguments and results are never logged.
