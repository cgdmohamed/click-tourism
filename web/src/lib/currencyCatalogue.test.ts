import { describe, expect, it } from 'vitest'
import {
  availableCatalogue,
  catalogueFormValues,
  CURRENCY_CATALOGUE,
  findCatalogueCurrency,
  OTHER_CURRENCY,
} from './currencyCatalogue'
import { isValidCurrencyCode } from './currencies'

// Mirror of Money::EXPONENTS in backend/app/Support/Money.php (the backend ignores the stored value for these).
const BUILTIN_EXPONENTS: Record<string, number> = {
  EGP: 2, SAR: 2, USD: 2, EUR: 2, GBP: 2, AED: 2,
  KWD: 3, BHD: 3, OMR: 3, JOD: 3,
}

const ARAB_REGION = [
  'EGP', 'SAR', 'AED', 'KWD', 'BHD', 'OMR', 'QAR', 'JOD', 'LBP', 'IQD', 'SYP',
  'YER', 'LYD', 'TND', 'DZD', 'MAD', 'SDG', 'MRU', 'SOS', 'DJF', 'KMF',
]

describe('currency catalogue', () => {
  it('has about forty valid, unique ISO codes with names and symbols', () => {
    const codes = CURRENCY_CATALOGUE.map((entry) => entry.code)
    expect(codes.length).toBeGreaterThanOrEqual(35)
    expect(new Set(codes).size).toBe(codes.length)
    for (const entry of CURRENCY_CATALOGUE) {
      expect(isValidCurrencyCode(entry.code)).toBe(true)
      expect(entry.name.length).toBeGreaterThan(1)
      expect(entry.name_ar.length).toBeGreaterThan(1)
      expect(entry.symbol.length).toBeGreaterThan(0)
      expect([0, 1, 2, 3]).toContain(entry.exponent)
    }
  })

  it('includes every Arab-region currency', () => {
    const codes = CURRENCY_CATALOGUE.map((entry) => entry.code)
    for (const code of ARAB_REGION) expect(codes).toContain(code)
  })

  it('uses the built-in exponent for codes the backend fixes', () => {
    for (const [code, exponent] of Object.entries(BUILTIN_EXPONENTS)) {
      expect(findCatalogueCurrency(code)?.exponent, code).toBe(exponent)
    }
  })

  it('prefills code, name, symbol and decimals from an entry', () => {
    const kwd = findCatalogueCurrency('kwd')
    expect(kwd).toBeDefined()
    expect(catalogueFormValues(kwd!)).toEqual({ code: 'KWD', name: 'Kuwaiti Dinar', symbol: 'د.ك', exponent: '3' })
  })

  it('hides currencies that already exist', () => {
    const rest = availableCatalogue(['egp', 'USD'])
    expect(rest.some((entry) => entry.code === 'EGP' || entry.code === 'USD')).toBe(false)
    expect(rest.length).toBe(CURRENCY_CATALOGUE.length - 2)
  })

  it('keeps a distinct manual-entry option value', () => {
    expect(CURRENCY_CATALOGUE.some((entry) => entry.code === OTHER_CURRENCY.toUpperCase())).toBe(false)
  })
})
