import { describe, expect, it } from 'vitest'
import { knownPermissionCodes, knownRoleNames, permissionGroupLabel, permissionLabel, roleLabel } from './permissionLabels'

describe('permission and role labels', () => {
  it('translates system roles and keeps a custom role name', () => {
    expect(roleLabel('finance_manager', 'ar')).toBe('مدير مالي')
    expect(roleLabel('finance_manager', 'en')).toBe('Finance manager')
    expect(roleLabel('night_auditor', 'ar')).toBe('night_auditor')
  })

  it('translates permissions and groups, falling back to the code', () => {
    expect(permissionLabel('bookings.create', 'ar')).toBe('إنشاء حجز')
    expect(permissionGroupLabel('bookings', 'ar')).toBe('الحجوزات')
    expect(permissionLabel('future.thing', 'ar')).toBe('future.thing')
    expect(permissionGroupLabel('future', 'en')).toBe('future')
  })

  it('has an Arabic and an English name for every known code, and a group for each prefix', () => {
    for (const code of knownPermissionCodes) {
      expect(permissionLabel(code, 'ar')).not.toBe(code)
      expect(permissionLabel(code, 'en')).not.toBe(code)
      const prefix = code.split('.')[0]
      expect(permissionGroupLabel(prefix, 'ar')).not.toBe(prefix)
    }
    expect(knownRoleNames).toHaveLength(9)
  })
})
