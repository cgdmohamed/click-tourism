import { describe, expect, it } from 'vitest'
import { editable, FORM_TABS, pendingChanges, SETTINGS_TABS } from './fields'

describe('settings fields', () => {
  it('never edits the logo path as text', () => {
    expect(editable.some((field) => field.key === 'company_logo')).toBe(false)
  })

  it('reports only changed fields, including company names', () => {
    const values = { 'general.company_name_en': 'Tourism ERP', 'general.company_name_ar': 'نظام' }
    const draft = { ...values, 'general.company_name_en': 'Nile Travel' }
    const changes = pendingChanges(draft, values)
    expect(changes.filter((c) => c.group === 'general' && c.key === 'company_name_en')).toEqual([
      { group: 'general', key: 'company_name_en', value: 'Nile Travel' },
    ])
    expect(changes.find((c) => c.key === 'company_name_ar')).toBeUndefined()
  })

  it('has every form tab in the tab list', () => {
    for (const tab of FORM_TABS) expect(SETTINGS_TABS).toContain(tab)
  })
})
