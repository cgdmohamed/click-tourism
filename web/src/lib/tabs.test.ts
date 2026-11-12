import { describe, expect, it } from 'vitest'
import { nextTabIndex, resolveTab } from './tabs'

describe('resolveTab', () => {
  const allowed = ['general', 'currencies', 'tax']
  it('returns a known tab and falls back otherwise', () => {
    expect(resolveTab('tax', allowed, 'general')).toBe('tax')
    expect(resolveTab('nope', allowed, 'general')).toBe('general')
    expect(resolveTab(null, allowed, 'general')).toBe('general')
    expect(resolveTab('', allowed, 'general')).toBe('general')
  })
})

describe('nextTabIndex', () => {
  it('moves with the arrow keys and wraps in LTR', () => {
    expect(nextTabIndex('ArrowRight', 0, 3, false)).toBe(1)
    expect(nextTabIndex('ArrowRight', 2, 3, false)).toBe(0)
    expect(nextTabIndex('ArrowLeft', 0, 3, false)).toBe(2)
  })
  it('mirrors the arrows in RTL', () => {
    expect(nextTabIndex('ArrowLeft', 0, 3, true)).toBe(1)
    expect(nextTabIndex('ArrowRight', 0, 3, true)).toBe(2)
  })
  it('supports Home/End and ignores other keys', () => {
    expect(nextTabIndex('Home', 2, 3, false)).toBe(0)
    expect(nextTabIndex('End', 0, 3, true)).toBe(2)
    expect(nextTabIndex('Enter', 0, 3, false)).toBeNull()
    expect(nextTabIndex('ArrowRight', 0, 0, false)).toBeNull()
  })
})
