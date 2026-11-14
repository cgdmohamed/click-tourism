import { describe, expect, it } from 'vitest'
import { rowAmount, rowDate, visibleSources } from './approvals'

describe('approvals', () => {
  it('shows only the sources the user may approve', () => {
    const keys = visibleSources((p) => p === 'expenses.approve' || p === 'invoices.approve').map((s) => s.key)
    expect(keys).toEqual(['expenses', 'invoices-submitted', 'invoices-approved'])
    expect(visibleSources(() => false)).toEqual([])
  })
  it('reads amount and date from whichever field the source uses', () => {
    expect(rowAmount({ id: '1', total_minor: 500 })).toBe(500)
    expect(rowAmount({ id: '1', amount_minor: 70 })).toBe(70)
    expect(rowDate({ id: '1', expense_date: '2026-01-02' })).toBe('2026-01-02')
  })
})
