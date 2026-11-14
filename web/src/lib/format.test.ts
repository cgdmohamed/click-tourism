import { describe, expect, it } from 'vitest'
import { exponent, formatDate, formatMoney, formatMoneyParts, isValidAmount, moneyFitStyle, parseAmount, registerCurrencyExponents, toDecimal, toMinor } from './format'

describe('formatMoney / formatMoneyParts', () => {
  const huge = 123456789012345
  it('keeps the existing formatMoney output for a 15-digit minor amount', () => {
    expect(formatMoney(huge, 'EGP', 'en').replace(/\s/g, ' ')).toBe('EGP 1,234,567,890,123.45')
    expect(formatMoney(huge, 'EGP', 'ar').replace(/[‎‏]/g, '').replace(/\s/g, ' ')).toBe('1,234,567,890,123.45 ج.م.')
  })

  it('splits into one run with the symbol isolated, in both locales', () => {
    const en = formatMoneyParts(huge, 'EGP', 'en')
    expect(en.negative).toBe(false)
    expect(en.text).toBe('EGP 1,234,567,890,123.45')
    expect(en.segments.filter((s) => s.kind === 'currency')).toEqual([{ kind: 'currency', value: 'EGP' }])
    const ar = formatMoneyParts(huge, 'EGP', 'ar')
    expect(ar.text).toBe('1,234,567,890,123.45 ج.م.')
    expect(ar.segments[ar.segments.length - 1]).toEqual({ kind: 'currency', value: 'ج.م.' })
  })

  it('puts the minus sign first for negative amounts and strips bidi marks', () => {
    const en = formatMoneyParts(-987654321098765, 'EGP', 'en')
    expect(en.negative).toBe(true)
    expect(en.text).toBe('-EGP 9,876,543,210,987.65')
    const ar = formatMoneyParts(-987654321098765, 'EGP', 'ar')
    expect(ar.negative).toBe(true)
    expect(ar.text).toBe('-9,876,543,210,987.65 ج.م.')
    expect(ar.text).not.toMatch(/[‎‏؜]/)
  })

  it('handles zero, missing values and 0 and 3 decimal currencies', () => {
    expect(formatMoneyParts(0, 'EGP', 'en').text).toBe('EGP 0.00')
    expect(formatMoneyParts(0, 'EGP', 'en').negative).toBe(false)
    expect(formatMoneyParts(null, 'EGP', 'ar').text).toBe('—')
    expect(formatMoneyParts(undefined, 'EGP', 'en').segments).toEqual([{ kind: 'text', value: '—' }])
    expect(formatMoneyParts(123456, 'KWD', 'en').text).toBe('KWD 123.456')
    registerCurrencyExponents([{ code: 'JPY', exponent: 0 }])
    try {
      expect(formatMoneyParts(1234567, 'JPY', 'en').text).toBe('¥1,234,567')
      expect(formatMoneyParts(-5, 'JPY', 'ar').text.startsWith('-5 ')).toBe(true)
      expect(formatMoneyParts(0, 'JPY', 'ar').text.startsWith('0 ')).toBe(true)
    } finally {
      registerCurrencyExponents([])
    }
  })

  it('exposes the character count used to fit the value in its container', () => {
    expect(moneyFitStyle('EGP 1,234,567,890,123.45')['--money-chars']).toBe(24)
    expect(moneyFitStyle('0')['--money-chars']).toBe(6)
  })
})

describe('exponent', () => {
  it('defaults to two decimals and knows three-decimal currencies', () => {
    expect(exponent('EGP')).toBe(2)
    expect(exponent('usd')).toBe(2)
    expect(exponent('KWD')).toBe(3)
    expect(exponent('XXX')).toBe(2)
  })
})

describe('toMinor', () => {
  it('converts decimal strings to integer minor units', () => {
    expect(toMinor('100', 'EGP')).toBe(10000)
    expect(toMinor('100.5', 'EGP')).toBe(10050)
    expect(toMinor('100.005', 'KWD')).toBe(100005)
    expect(toMinor('1,234.56', 'EGP')).toBe(123456)
  })

  it('truncates beyond the currency exponent instead of rounding', () => {
    expect(toMinor('10.999', 'EGP')).toBe(1099)
  })

  it('never silently accepts garbage', () => {
    expect(toMinor('', 'EGP')).toBe(0)
    expect(toMinor('12abc', 'EGP')).toBe(0)
    expect(toMinor('1.2.3', 'EGP')).toBe(0)
  })

  it('keeps the sign for negative amounts', () => {
    expect(toMinor('-12.34', 'EGP')).toBe(-1234)
  })
})

describe('toDecimal', () => {
  it('renders minor units with the currency exponent', () => {
    expect(toDecimal(0, 'EGP')).toBe('0.00')
    expect(toDecimal(1, 'EGP')).toBe('0.01')
    expect(toDecimal(1234, 'EGP')).toBe('12.34')
    expect(toDecimal(100000, 'EGP')).toBe('1000.00')
  })

  it('uses the currency exponent', () => {
    expect(toDecimal(100005, 'KWD')).toBe('100.005')
    expect(toDecimal(-1234, 'EGP')).toBe('-12.34')
    expect(toDecimal(-550, 'EGP')).toBe('-5.50')
  })

  it('round-trips minor units through a decimal string', () => {
    for (const currency of ['EGP', 'KWD']) {
      for (const minor of [0, 1, 99, 1234, 100000, -550]) {
        expect(toMinor(toDecimal(minor, currency), currency)).toBe(minor)
      }
    }
  })
})

describe('amount validation', () => {
  it('accepts grouped digits within the exponent', () => {
    expect(isValidAmount('1,234.56', 'EGP')).toBe(true)
    expect(isValidAmount('1.234', 'KWD')).toBe(true)
    expect(isValidAmount('10', 'EGP')).toBe(true)
  })

  it('rejects signs, letters, bad grouping and too many decimals', () => {
    expect(isValidAmount('', 'EGP')).toBe(false)
    expect(isValidAmount('-10', 'EGP')).toBe(false)
    expect(isValidAmount('10.999', 'EGP')).toBe(false)
    expect(isValidAmount('1,23.00', 'EGP')).toBe(false)
    expect(isValidAmount('1e3', 'EGP')).toBe(false)
  })

  it('parseAmount returns null instead of 0 for invalid input', () => {
    expect(parseAmount('12.34', 'EGP')).toBe(1234)
    expect(parseAmount('nope', 'EGP')).toBeNull()
    expect(parseAmount('', 'EGP')).toBeNull()
  })
})

describe('formatDate', () => {
  it('returns the placeholder for empty values', () => {
    expect(formatDate(null, 'en')).toBe('—')
    expect(formatDate('', 'ar')).toBe('—')
  })

  it('returns the raw value when it is not a date', () => {
    expect(formatDate('not-a-date', 'en')).toBe('not-a-date')
  })
})
