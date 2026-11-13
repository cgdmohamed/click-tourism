/**
 * Pure helpers for the "MCP status" card in Settings > Integrations (ADR-0016/0017).
 * No React, so they are covered by node-env tests. The API stays the authority.
 */

export type McpTokenTotals = { active: number; expired: number; total: number }

export type McpRecentConnection = {
  id: number
  name: string
  state: 'active' | 'expired'
  created_at: string | null
  last_used_at: string | null
  expires_at: string | null
  user: { id: string; name: string; email: string } | null
}

export type McpStatus = {
  enabled: boolean
  tokens: McpTokenTotals
  last_connection_at: string | null
  recent: McpRecentConnection[]
}

/** The card shows the last ten connections at most. */
export const RECENT_LIMIT = 10

export type McpConnectionState = 'off' | 'idle' | 'active'

/**
 * 'off' when the installation switch is off; 'active' when it is on and usable
 * (an active token or a recorded connection); 'idle' when it is on but nothing
 * has connected yet.
 */
export function connectionState(status: Pick<McpStatus, 'enabled' | 'tokens' | 'last_connection_at'>): McpConnectionState {
  if (!status.enabled) return 'off'
  if (status.last_connection_at || safeCount(status.tokens?.active) > 0) return 'active'
  return 'idle'
}

function safeCount(value: number | undefined | null): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return 0
  return Math.floor(value)
}

/** Non-negative integer totals; the total is never below active + expired. */
export function tokenSummary(tokens: Partial<McpTokenTotals> | null | undefined): McpTokenTotals {
  const active = safeCount(tokens?.active)
  const expired = safeCount(tokens?.expired)
  const total = safeCount(tokens?.total)
  return { active, expired, total: Math.max(total, active + expired) }
}

/** The recent rows the card may render, capped at RECENT_LIMIT; never null. */
export function recentOrEmpty(status: Pick<McpStatus, 'recent'> | null | undefined): McpRecentConnection[] {
  return (status?.recent ?? []).slice(0, RECENT_LIMIT)
}

/** 403 is a permission problem; anything else (including 404 before deployment) is unavailable. */
export function mcpStatusError(status: number | undefined): 'forbidden' | 'unavailable' {
  return status === 403 ? 'forbidden' : 'unavailable'
}
