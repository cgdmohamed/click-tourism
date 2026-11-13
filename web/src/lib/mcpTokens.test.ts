import { describe, expect, it } from 'vitest'
import { defaultExpiry, expiryChoices, showMcpSection, splitDuration, tokenState, validateTokenName } from './mcpTokens'

describe('mcp token helpers', () => {
  it('offers expiry choices up to the configured maximum', () => {
    expect(expiryChoices(1440)).toEqual([60, 480, 1440])
    expect(expiryChoices(480)).toEqual([60, 480])
    expect(expiryChoices(120)).toEqual([60, 120])
    expect(expiryChoices(30)).toEqual([30])
  })

  it('preselects the installation default but never above the maximum', () => {
    expect(defaultExpiry({ default_minutes: 480, max_minutes: 1440, max_active_per_user: 10 })).toBe(480)
    expect(defaultExpiry({ default_minutes: 480, max_minutes: 60, max_active_per_user: 10 })).toBe(60)
    expect(defaultExpiry({ default_minutes: 90, max_minutes: 1440, max_active_per_user: 10 })).toBe(60)
  })

  it('splits durations for display', () => {
    expect(splitDuration(480)).toEqual({ hours: 8, minutes: 0 })
    expect(splitDuration(90)).toEqual({ hours: 1, minutes: 30 })
  })

  it('classifies tokens by expiry', () => {
    const now = new Date('2026-11-13T10:00:00Z')
    expect(tokenState({ expires_at: '2026-11-13T11:00:00Z' }, now)).toBe('active')
    expect(tokenState({ expires_at: '2026-11-13T09:59:59Z' }, now)).toBe('expired')
    expect(tokenState({ expires_at: null }, now)).toBe('active')
  })

  it('validates the token name like the server (1-80 characters)', () => {
    expect(validateTokenName('')).toBe('required')
    expect(validateTokenName('   ')).toBe('required')
    expect(validateTokenName('Claude Desktop')).toBeNull()
    expect(validateTokenName('x'.repeat(80))).toBeNull()
    expect(validateTokenName('x'.repeat(81))).toBe('tooLong')
  })

  it('shows the section only with the permission and the installation flag', () => {
    expect(showMcpSection(true, true)).toBe(true)
    expect(showMcpSection(true, false)).toBe(false)
    expect(showMcpSection(true, undefined)).toBe(false)
    expect(showMcpSection(false, true)).toBe(false)
  })
})
