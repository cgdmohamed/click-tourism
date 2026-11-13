/**
 * Pure helpers for the "MCP access tokens" section of the Profile page (ADR-0016).
 * No React, so they are covered by node-env tests.
 */

export type McpToken = {
  id: number
  name: string
  abilities: string[]
  created_at: string | null
  last_used_at: string | null
  expires_at: string | null
  /** Only present in the administrator's "all tokens" listing. */
  user?: { id: string; name: string; email: string } | null
}

export type McpTokenLimits = {
  default_minutes: number
  max_minutes: number
  max_active_per_user: number
}

/** Used until the first listing reports the installation's limits (the server still validates). */
export const FALLBACK_LIMITS: McpTokenLimits = { default_minutes: 480, max_minutes: 1440, max_active_per_user: 10 }

const CHOICES = [60, 480, 1440]

/** Expiry choices up to the configured maximum, ascending; the maximum itself is offered when it is not a preset. */
export function expiryChoices(maxMinutes: number): number[] {
  const max = Math.max(5, Math.floor(maxMinutes))
  const list = CHOICES.filter((minutes) => minutes <= max)
  if (!list.includes(max)) list.push(max)
  return list.sort((a, b) => a - b)
}

/** The preselected choice: the installation default when offered, otherwise the largest choice not above it. */
export function defaultExpiry(limits: McpTokenLimits): number {
  const choices = expiryChoices(limits.max_minutes)
  const wanted = Math.min(limits.default_minutes, limits.max_minutes)
  return [...choices].reverse().find((minutes) => minutes <= wanted) ?? choices[0]
}

/** Splits minutes into whole hours and minutes for display ("8 h", "45 min"). */
export function splitDuration(minutes: number): { hours: number; minutes: number } {
  return { hours: Math.floor(minutes / 60), minutes: minutes % 60 }
}

export type McpTokenState = 'active' | 'expired'

export function tokenState(token: Pick<McpToken, 'expires_at'>, now: Date = new Date()): McpTokenState {
  if (!token.expires_at) return 'active'
  const expires = new Date(token.expires_at)
  return !Number.isNaN(expires.getTime()) && expires.getTime() <= now.getTime() ? 'expired' : 'active'
}

/** Trims the name and checks it against the server rule (1-80 characters). Returns an error code or null. */
export function validateTokenName(name: string): 'required' | 'tooLong' | null {
  const trimmed = name.trim()
  if (trimmed.length === 0) return 'required'
  return Array.from(trimmed).length > 80 ? 'tooLong' : null
}

/** Whether the section is shown: the permission and the installation flag are both required. */
export function showMcpSection(canUse: boolean, flagEnabled: boolean | undefined): boolean {
  return canUse && flagEnabled === true
}
