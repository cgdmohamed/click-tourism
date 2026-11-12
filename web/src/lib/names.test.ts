import { describe, expect, it } from 'vitest'
import { codeLabel, localizedName } from './names'

describe('localizedName', () => {
  const item = { name_ar: 'مدينون', name_en: 'Receivables' }
  it('uses the active locale only', () => {
    expect(localizedName(item, 'ar')).toBe('مدينون')
    expect(localizedName(item, 'en')).toBe('Receivables')
  })
  it('falls back to the other language when the active one is empty', () => {
    expect(localizedName({ name_ar: '', name_en: 'Receivables' }, 'ar')).toBe('Receivables')
    expect(localizedName({ name_ar: 'مدينون', name_en: '  ' }, 'en')).toBe('مدينون')
    expect(localizedName({ name_ar: null, name_en: 'X' }, 'ar')).toBe('X')
  })
  it('handles missing items', () => {
    expect(localizedName(null, 'ar')).toBe('')
    expect(localizedName(undefined, 'en', '—')).toBe('—')
    expect(localizedName({}, 'en', '—')).toBe('—')
  })
})

describe('codeLabel', () => {
  it('joins code and localized name', () => {
    expect(codeLabel({ code: '1100', name_ar: 'مدينون', name_en: 'Receivables' }, 'en')).toBe('1100 — Receivables')
    expect(codeLabel({ code: '1100', name_ar: '', name_en: '' }, 'en')).toBe('1100')
  })
})
