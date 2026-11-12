import { describe, expect, it } from 'vitest'
import { activeCurrencies, isValidCurrencyCode, normalizeCurrencyCode, resolveCurrency } from './currencies'

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

describe('resolveCurrency', () => {
  it('keeps the form currency while it is still offered', () => {
    expect(resolveCurrency('SAR', ['EGP', 'SAR', 'USD'], 'EGP')).toBe('SAR')
  })

  it('falls back to the base currency when the default was deactivated', () => {
    expect(resolveCurrency('EGP', ['SAR', 'USD'], 'USD')).toBe('USD')
  })

  it('falls back to the first active currency when the base is not offered either', () => {
    expect(resolveCurrency('EGP', ['SAR', 'USD'], 'EGP')).toBe('SAR')
    expect(resolveCurrency('EGP', ['SAR', 'USD'])).toBe('SAR')
  })

  it('keeps the current value when nothing is offered', () => {
    expect(resolveCurrency('EGP', [], 'EGP')).toBe('EGP')
  })
})
