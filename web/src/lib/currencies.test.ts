import { describe, expect, it } from 'vitest'
import { activeCurrencies, isValidCurrencyCode, normalizeCurrencyCode } from './currencies'

describe('currency helpers', () => {
  it('normalizes typed codes to up to three uppercase letters', () => {
    expect(normalizeCurrencyCode('us1d9')).toBe('USD')
    expect(normalizeCurrencyCode('abcdef')).toBe('ABC')
    expect(normalizeCurrencyCode('')).toBe('')
  })
  it('validates ISO-like codes', () => {
    expect(isValidCurrencyCode('USD')).toBe(true)
    expect(isValidCurrencyCode('usd')).toBe(false)
    expect(isValidCurrencyCode('US')).toBe(false)
  })
  it('keeps only active currencies for new documents', () => {
    expect(activeCurrencies([{ is_active: true, c: 1 }, { is_active: false, c: 2 }])).toEqual([{ is_active: true, c: 1 }])
    expect(activeCurrencies(undefined)).toEqual([])
  })
})
