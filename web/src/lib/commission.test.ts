import { describe, expect, it } from 'vitest'
import { basisLabel, beneficiaryName, bpToPercent, commissionStatusLabel, percentToBp } from './commission'

describe('commission rate helpers', () => {
  it('converts percentages to basis points without floating point', () => {
    expect(percentToBp('12.5')).toBe(1250)
    expect(percentToBp('12.50%')).toBe(1250)
    expect(percentToBp('0.01')).toBe(1)
    expect(percentToBp('100')).toBe(10000)
    expect(percentToBp('0.29')).toBe(29) // 0.29 * 100 is 28.999... in floating point
    expect(percentToBp('1.1')).toBe(110)
  })

  it('rejects out of range or malformed percentages', () => {
    for (const bad of ['', '0', '0.00', '100.01', '101', '-1', '1.234', 'abc', '1,5']) {
      expect(percentToBp(bad)).toBeNull()
    }
  })

  it('round-trips basis points', () => {
    for (const bp of [1, 10, 250, 1250, 9999, 10000]) {
      expect(percentToBp(bpToPercent(bp))).toBe(bp)
    }
    expect(bpToPercent(1250)).toBe('12.5')
    expect(bpToPercent(500)).toBe('5')
    expect(bpToPercent(null)).toBe('')
  })

  it('labels in both locales and falls back to the raw value', () => {
    expect(commissionStatusLabel('accrued', 'ar')).toBe('مستحقة')
    expect(commissionStatusLabel('accrued', 'en')).toBe('Accrued')
    expect(basisLabel('fixed', 'en')).toBe('Fixed amount')
    expect(commissionStatusLabel('mystery', 'en')).toBe('mystery')
  })

  it('names beneficiaries by locale', () => {
    const ref = { type: 'employee' as const, id: '1', name_ar: 'أحمد', name_en: 'Ahmed' }
    expect(beneficiaryName(ref, 'ar', '-')).toBe('أحمد')
    expect(beneficiaryName(ref, 'en', '-')).toBe('Ahmed')
    expect(beneficiaryName(null, 'en', 'Booking owner')).toBe('Booking owner')
  })
})
