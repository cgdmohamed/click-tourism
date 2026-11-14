import { describe, expect, it } from 'vitest'
import {
  TOKEN_PLACEHOLDER,
  claudeCodeCommand,
  claudeDesktopConfig,
  endpoint,
  gatewayWarning,
  listToolsCheck,
  normalizeGatewayUrl,
  openAiRequest,
} from './mcpGuide'

describe('mcpGuide', () => {
  it('normalizes a gateway address and refuses non-http values', () => {
    expect(normalizeGatewayUrl(' https://mcp.example.com/ ')).toBe('https://mcp.example.com')
    expect(normalizeGatewayUrl('https://mcp.example.com/mcp')).toBe('https://mcp.example.com')
    expect(normalizeGatewayUrl('javascript:alert(1)')).toBe('')
    expect(normalizeGatewayUrl('not a url')).toBe('')
    expect(normalizeGatewayUrl('')).toBe('')
  })

  it('warns about plain http except on this machine', () => {
    expect(gatewayWarning('http://mcp.example.com')).toBe('insecure')
    expect(gatewayWarning('http://localhost:8787')).toBe('none')
    expect(gatewayWarning('https://mcp.example.com')).toBe('none')
    expect(gatewayWarning('')).toBe('none')
  })

  it('builds snippets that use the gateway address and never a real token', () => {
    const url = 'https://mcp.example.com'
    expect(endpoint(url)).toBe('https://mcp.example.com/mcp')
    for (const snippet of [claudeCodeCommand(url), openAiRequest(url), listToolsCheck(url), claudeDesktopConfig('https://erp.example.com')]) {
      expect(snippet).toContain(TOKEN_PLACEHOLDER)
    }
    expect(openAiRequest(url)).toContain('"server_url": "https://mcp.example.com/mcp"')
    expect(JSON.parse(claudeDesktopConfig('https://erp.example.com')).mcpServers['click-tourism-erp'].env.API_BASE_URL).toBe('https://erp.example.com')
  })

  it('shows a placeholder address until one is typed', () => {
    expect(endpoint('')).toBe('https://mcp.your-domain.com/mcp')
  })
})
