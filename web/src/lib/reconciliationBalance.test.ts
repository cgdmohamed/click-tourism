import { describe, expect, it } from 'vitest'
import { balanceSourceLabel, balanceSourceLabels, balancesUnconfirmed } from './reconciliationBalance'

describe('reconciliation balance source', () => {
  it('labels every source in Arabic and English', () => {
    for (const key of ['explicit', 'statement', 'statement_unverified', 'line_balance', 'previous_session']) {
      expect(balanceSourceLabels[key].ar.length).toBeGreaterThan(0)
      expect(balanceSourceLabels[key].en.length).toBeGreaterThan(0)
    }
  })

  it('shows a missing source as unconfirmed', () => {
    expect(balanceSourceLabel(null, 'opening', 'en')).toBe('Unconfirmed')
    expect(balanceSourceLabel(undefined, 'closing', 'ar')).toBe('غير مؤكد')
    expect(balanceSourceLabel({ opening: 'previous_session', closing: 'explicit' }, 'opening', 'en')).toBe('Previous approved reconciliation')
  })

  it('flags legacy and unverified balances', () => {
    expect(balancesUnconfirmed(null)).toBe(true)
    expect(balancesUnconfirmed({ opening: 'statement_unverified', closing: 'explicit' })).toBe(true)
    expect(balancesUnconfirmed({ opening: 'explicit', closing: 'line_balance' })).toBe(false)
  })
})
