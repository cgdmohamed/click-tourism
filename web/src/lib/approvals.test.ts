import { describe, expect, it } from 'vitest'
import { recordHref, rowAmount, rowDate, SOURCES, sourceParams, visibleSources } from './approvals'

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
  it('links a record to its own page, and a source without one to its collection', () => {
    const by = (key: string) => SOURCES.find((source) => source.key === key)!
    expect(recordHref(by('expenses'), 'abc')).toBe('/expenses/abc')
    expect(recordHref(by('documents'), 'a b')).toBe('/documents/a%20b')
    expect(recordHref(by('invoices-approved'), 'x1')).toBe('/invoices/x1')
    expect(recordHref(by('commissions'), 'c1')).toBe('/commissions')
  })
  it('lists bookings without an invoice for the ready-to-invoice source and statuses for the others', () => {
    const by = (key: string) => SOURCES.find((source) => source.key === key)!
    expect(sourceParams(by('ready-to-invoice'))).toEqual({ needs_invoice: '1' })
    expect(sourceParams(by('expenses'))).toEqual({ status: 'submitted' })
    expect(visibleSources((p) => p === 'invoices.create').map((source) => source.key)).toEqual(['ready-to-invoice'])
    expect(rowAmount({ id: 'b', total_sell_minor: 900 })).toBe(900)
    expect(rowDate({ id: 'b', start_date: '2026-05-01' })).toBe('2026-05-01')
  })
})
