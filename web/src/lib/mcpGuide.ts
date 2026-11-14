/**
 * Pure builders for the Settings > MCP setup guide (ADR-0024). The snippets never contain a real
 * token: the person pastes the one shown when it was created, so a token never sits in this page.
 */

export const TOKEN_PLACEHOLDER = '<YOUR_MCP_TOKEN>'
export const GATEWAY_PLACEHOLDER = 'https://mcp.your-domain.com'

/** Trimmed gateway base URL without a trailing slash or `/mcp`; '' when it is not an http(s) URL. */
export function normalizeGatewayUrl(raw: string): string {
  const value = raw.trim().replace(/\/+$/, '').replace(/\/mcp$/i, '')
  try {
    const url = new URL(value)
    return url.protocol === 'https:' || url.protocol === 'http:' ? `${url.origin}${url.pathname === '/' ? '' : url.pathname}` : ''
  } catch {
    return ''
  }
}

/** A remote MCP server must be reachable over HTTPS (local http is fine for a trial on this machine). */
export function gatewayWarning(url: string): 'none' | 'insecure' {
  if (url === '') return 'none'
  const { protocol, hostname } = new URL(url)
  return protocol === 'http:' && !['localhost', '127.0.0.1', '[::1]'].includes(hostname) ? 'insecure' : 'none'
}

export function endpoint(gatewayUrl: string): string {
  return `${gatewayUrl || GATEWAY_PLACEHOLDER}/mcp`
}

/** Claude Desktop, local process (stdio): the gateway runs on the user's computer. */
export function claudeDesktopConfig(apiUrl: string): string {
  return JSON.stringify(
    {
      mcpServers: {
        'click-tourism-erp': {
          command: 'node',
          args: ['/path/to/click-tourism/mcp-gateway/src/index.js'],
          env: { API_BASE_URL: apiUrl, MCP_TOKEN: TOKEN_PLACEHOLDER },
        },
      },
    },
    null,
    2,
  )
}

/** Claude Code, remote gateway over HTTPS. */
export function claudeCodeCommand(gatewayUrl: string): string {
  return `claude mcp add --transport http click-tourism-erp ${endpoint(gatewayUrl)} \\\n  --header "Authorization: Bearer ${TOKEN_PLACEHOLDER}"`
}

/** OpenAI Responses API with a remote MCP tool. */
export function openAiRequest(gatewayUrl: string, model = 'gpt-4.1'): string {
  return `curl https://api.openai.com/v1/responses \\
  -H "Content-Type: application/json" \\
  -H "Authorization: Bearer $OPENAI_API_KEY" \\
  -d '{
    "model": "${model}",
    "tools": [{
      "type": "mcp",
      "server_label": "click_tourism_erp",
      "server_url": "${endpoint(gatewayUrl)}",
      "authorization": "${TOKEN_PLACEHOLDER}",
      "require_approval": "never"
    }],
    "input": "Which invoices are still open?"
  }'`
}

export function healthCheck(gatewayUrl: string): string {
  return `curl ${gatewayUrl || GATEWAY_PLACEHOLDER}/health`
}

/** A raw JSON-RPC call that lists the tools: proves the gateway, the token and the ERP are connected. */
export function listToolsCheck(gatewayUrl: string): string {
  return `curl ${endpoint(gatewayUrl)} \\
  -H "Authorization: Bearer ${TOKEN_PLACEHOLDER}" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json, text/event-stream" \\
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`
}

/** Docker run for the remote gateway (next to the ERP, never with database credentials). */
export function gatewayDocker(apiUrl: string): string {
  return `docker build -t click-tourism-mcp ./mcp-gateway
docker run -d --name click-tourism-mcp -p 8787:8787 \\
  -e API_BASE_URL=${apiUrl} \\
  click-tourism-mcp src/http.js`
}
