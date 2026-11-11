import { describe, expect, it } from 'vitest'
import { resolveBaseCurrency, resolveTaxEnabled } from './useSetting'

describe('resolveTaxEnabled', () => {
  it('trusts the feature flag over the legacy setting', () => {
    expect(resolveTaxEnabled({ tax_enabled: false }, { value: 'true', loaded: true })).toEqual({ enabled: false, loaded: true })
    expect(resolveTaxEnabled({ tax_enabled: true }, { value: 'false', loaded: true })).toEqual({ enabled: true, loaded: true })
  })

  it('falls back to the legacy setting when the flags are unavailable', () => {
    expect(resolveTaxEnabled(undefined, { value: 'false', loaded: true })).toEqual({ enabled: false, loaded: true })
    expect(resolveTaxEnabled(undefined, { value: 'true', loaded: true })).toEqual({ enabled: true, loaded: true })
  })

  it('is optimistic while nothing is known', () => {
    expect(resolveTaxEnabled(undefined, { value: 'true', loaded: false })).toEqual({ enabled: true, loaded: false })
  })
})

describe('resolveBaseCurrency', () => {
  it('prefers the flag, then the legacy value', () => {
    expect(resolveBaseCurrency({ base_currency: 'SAR' }, 'EGP')).toBe('SAR')
    expect(resolveBaseCurrency(undefined, 'EGP')).toBe('EGP')
  })
})
