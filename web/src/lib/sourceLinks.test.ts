import { describe, expect, it } from 'vitest'
import { sourceDocumentLink } from './sourceLinks'

describe('sourceDocumentLink', () => {
  it('opens the invoice and booking record when the public id is known', () => {
    expect(sourceDocumentLink({ source_type: 'invoice', source_public_id: 'inv1' })?.href).toBe('/invoices/inv1')
    expect(sourceDocumentLink({ source_type: 'booking', source_public_id: 'bk1' })?.href).toBe('/bookings?booking_id=bk1')
  })

  it('falls back to a filtered list for payments and to the module list for expenses', () => {
    expect(sourceDocumentLink({ source_type: 'payment', source_reference: 'PAY 1' })?.href).toBe('/payments?search=PAY%201')
    expect(sourceDocumentLink({ source_type: 'expense' })).toEqual({ href: '/expenses', permission: 'expenses.view' })
  })

  it('returns null for manual or unknown sources', () => {
    expect(sourceDocumentLink({ source_type: null })).toBeNull()
    expect(sourceDocumentLink({ source_type: 'manual' })).toBeNull()
    expect(sourceDocumentLink({ source_type: 'invoice' })).toBeNull()
  })
})
