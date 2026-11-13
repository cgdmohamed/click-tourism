import { describe, expect, it } from 'vitest'
import { connectionState, mcpStatusError, recentOrEmpty, tokenSummary } from './mcpStatus'
import type { McpRecentConnection, McpStatus } from './mcpStatus'

const base: Pick<McpStatus, 'enabled' | 'tokens' | 'last_connection_at'> = {
  enabled: true,
  tokens: { active: 0, expired: 0, total: 0 },
  last_connection_at: null,
}

const row = (id: number): McpRecentConnection => ({
  id,
  name: `token-${id}`,
  state: 'active',
  created_at: null,
  last_used_at: null,
  expires_at: null,
  user: null,
})

describe('mcp status helpers', () => {
  it('classifies the connection state', () => {
    expect(connectionState({ ...base, enabled: false })).toBe('off')
    expect(connectionState(base)).toBe('idle')
    expect(connectionState({ ...base, tokens: { active: 2, expired: 0, total: 2 } })).toBe('active')
    expect(connectionState({ ...base, last_connection_at: '2026-11-13T10:00:00Z' })).toBe('active')
    expect(connectionState({ ...base, enabled: false, last_connection_at: '2026-11-13T10:00:00Z' })).toBe('off')
  })

  it('normalizes token totals', () => {
    expect(tokenSummary({ active: 2, expired: 1, total: 3 })).toEqual({ active: 2, expired: 1, total: 3 })
    expect(tokenSummary({ active: 2 })).toEqual({ active: 2, expired: 0, total: 2 })
    expect(tokenSummary(null)).toEqual({ active: 0, expired: 0, total: 0 })
    // A total below the parts is raised; invalid numbers are dropped.
    expect(tokenSummary({ active: 2, expired: 1, total: 0 })).toEqual({ active: 2, expired: 1, total: 3 })
    expect(tokenSummary({ active: -1, expired: Number.NaN, total: 2 })).toEqual({ active: 0, expired: 0, total: 2 })
  })

  it('caps the recent rows and never returns null', () => {
    const rows = Array.from({ length: 14 }, (_, index) => row(index + 1))
    expect(recentOrEmpty({ recent: rows })).toHaveLength(10)
    expect(recentOrEmpty({ recent: rows })[9].id).toBe(10)
    expect(recentOrEmpty({ recent: [] })).toEqual([])
    expect(recentOrEmpty(null)).toEqual([])
  })

  it('separates a permission failure from an unavailable endpoint', () => {
    expect(mcpStatusError(403)).toBe('forbidden')
    expect(mcpStatusError(404)).toBe('unavailable')
    expect(mcpStatusError(500)).toBe('unavailable')
    expect(mcpStatusError(undefined)).toBe('unavailable')
  })
})
