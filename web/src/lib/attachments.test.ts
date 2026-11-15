import { describe, expect, it } from 'vitest'
import { canExtract, categoriesFor, categoryLabel, formatSize, initialValues, invoicePrefill, toConfirmPayload } from './attachments'
import type { Extraction } from './attachments'

const extraction: Extraction = {
  id: 'x', attachment_id: 'a', status: 'succeeded', category: 'passport', error_code: null, confirmed: null,
  proposed: { fields: { full_name: { value: 'A B', flag: null }, passport_number: { value: null, flag: 'missing' } } },
}

describe('attachments helpers', () => {
  it('knows which categories can be read', () => {
    expect(canExtract('passport')).toBe(true)
    expect(canExtract('bank_transfer')).toBe(true)
    expect(canExtract('other')).toBe(false)
  })
  it('labels categories in both languages and falls back to the key', () => {
    expect(categoryLabel('passport', 'en')).toBe('Passport')
    expect(categoryLabel('passport', 'ar')).toBe('جواز سفر')
    expect(categoryLabel('unknown', 'en')).toBe('unknown')
  })
  it('formats sizes', () => {
    expect(formatSize(900)).toBe('900 B')
    expect(formatSize(2048)).toBe('2 KB')
    expect(formatSize(3 * 1024 * 1024)).toBe('3.0 MB')
  })
  it('starts the review from the proposal and leaves missing values empty', () => {
    const values = initialValues(extraction)
    expect(values.full_name).toBe('A B')
    expect(values.passport_number).toBe('')
    expect(Object.keys(values)).toHaveLength(5)
  })
  it('sends empty values as null and trims the rest', () => {
    expect(toConfirmPayload({ a: ' x ', b: '  ' })).toEqual({ a: 'x', b: null })
  })
  it('pre-fills an invoice from confirmed values without inventing any', () => {
    expect(invoicePrefill({ supplier_name: 'Nile', invoice_number: 'I-1', invoice_date: '2026-11-13', subtotal: null, total: '100.00' })).toEqual({
      issue_date: '2026-11-13', due_date: undefined, currency: undefined, memo: 'Nile · I-1', description: 'I-1', amount: '100.00',
    })
  })
})

describe('categoriesFor', () => {
  it('offers an invoice only the types that make sense for it', () => {
    const values = categoriesFor('invoice').map((entry) => entry.value)
    expect(values).toContain('supplier_invoice')
    expect(values).toContain('bank_transfer')
    expect(values).not.toContain('passport')
    expect(values).not.toContain('visa')
  })

  it('offers a customer the identity documents and a payment only receipts', () => {
    expect(categoriesFor('customer').map((entry) => entry.value)).toContain('passport')
    expect(categoriesFor('payment').map((entry) => entry.value)).toEqual(['payment_receipt', 'bank_transfer', 'other'])
  })

  it('keeps the category a file already has, even when it no longer fits', () => {
    expect(categoriesFor('invoice', 'passport').map((entry) => entry.value)).toContain('passport')
  })
})
