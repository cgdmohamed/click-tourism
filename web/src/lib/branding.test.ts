import { describe, expect, it } from 'vitest'
import { brandName, logoFileProblem, logoSrc } from './branding'

describe('logoFileProblem', () => {
  it('accepts PNG/JPEG up to 1 MB only', () => {
    expect(logoFileProblem({ type: 'image/png', size: 1000 })).toBeNull()
    expect(logoFileProblem({ type: 'image/jpeg', size: 1024 * 1024 })).toBeNull()
    expect(logoFileProblem({ type: 'image/svg+xml', size: 100 })).toBe('type')
    expect(logoFileProblem({ type: 'image/gif', size: 100 })).toBe('type')
    expect(logoFileProblem({ type: 'image/png', size: 1024 * 1024 + 1 })).toBe('size')
    expect(logoFileProblem({ type: 'image/png', size: 0 })).toBe('size')
  })
})

describe('brandName', () => {
  const branding = { company_name_ar: 'النيل للسياحة', company_name_en: 'Nile Travel' }
  it('uses the active locale', () => {
    expect(brandName(branding, 'ar', 'x')).toBe('النيل للسياحة')
    expect(brandName(branding, 'en', 'x')).toBe('Nile Travel')
  })
  it('falls back to the other language, then to the built-in name', () => {
    expect(brandName({ company_name_ar: ' ', company_name_en: 'Nile Travel' }, 'ar', 'x')).toBe('Nile Travel')
    expect(brandName({ company_name_ar: '', company_name_en: '' }, 'en', 'Tourism ERP')).toBe('Tourism ERP')
    expect(brandName(undefined, 'en', 'Tourism ERP')).toBe('Tourism ERP')
  })
})

describe('logoSrc', () => {
  it('is null without a logo and versioned with one', () => {
    expect(logoSrc(undefined)).toBeNull()
    expect(logoSrc({ has_logo: false, logo_version: null })).toBeNull()
    expect(logoSrc({ has_logo: true, logo_version: 'abc123' })).toMatch(/\/branding\/logo\?v=abc123$/)
  })
})
