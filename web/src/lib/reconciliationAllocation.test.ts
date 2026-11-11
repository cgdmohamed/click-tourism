import { describe, expect, it } from 'vitest'
import { allocateMatch } from './reconciliationAllocation'

const line = (id: string, remaining_minor: number) => ({ id, remaining_minor })

describe('allocateMatch', () => {
  it('pairs one bank line with several ledger lines', () => {
    expect(allocateMatch([line('b1', 300)], [line('k1', 100), line('k2', 200)])).toEqual([
      { bank_line_id: 'b1', journal_line_id: 'k1', amount_minor: 100 },
      { bank_line_id: 'b1', journal_line_id: 'k2', amount_minor: 200 },
    ])
  })

  it('splits a ledger line across several bank lines', () => {
    expect(allocateMatch([line('b1', 100), line('b2', 50)], [line('k1', 150)])).toEqual([
      { bank_line_id: 'b1', journal_line_id: 'k1', amount_minor: 100 },
      { bank_line_id: 'b2', journal_line_id: 'k1', amount_minor: 50 },
    ])
  })

  it('handles many-to-many selections with different counts without gaps or overlaps', () => {
    const items = allocateMatch([line('b1', 120), line('b2', 80)], [line('k1', 50), line('k2', 100), line('k3', 50)])
    expect(items.reduce((sum, item) => sum + item.amount_minor, 0)).toBe(200)
    expect(items.filter((item) => item.bank_line_id === 'b1').reduce((sum, item) => sum + item.amount_minor, 0)).toBe(120)
    expect(items.filter((item) => item.journal_line_id === 'k2').reduce((sum, item) => sum + item.amount_minor, 0)).toBe(100)
  })

  it('stops when one side is exhausted (tolerance leftovers)', () => {
    expect(allocateMatch([line('b1', 100)], [line('k1', 99)])).toEqual([{ bank_line_id: 'b1', journal_line_id: 'k1', amount_minor: 99 }])
  })
})
