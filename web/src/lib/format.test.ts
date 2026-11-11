import { describe, expect, it } from 'vitest'
import { exponent, formatDate, isValidAmount, parseAmount, toDecimal, toMinor } from './format'

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
