import { describe, expect, it } from 'vitest'
import { auditBranchLabel, auditEntityLabel, auditQuery, auditSnapshot } from './audit'
import type { AuditEvent, AuditFilters } from './types'

const blank: AuditFilters = {
  actor: '',
  action: '',
  entity_type: '',
  entity_id: '',
  branch: '',
  from: '',
  to: '',
}

const event: AuditEvent = {
  id: '1',
  action: 'lead.created',
  actor: null,
  entity_type: 'Lead',
  entity_id: 12,
  branch: null,
  before: null,
  after: { name: 'Test' },
  ip: null,
  request_id: null,
  created_at: '2026-01-10T09:00:00+00:00',
}

describe('auditQuery', () => {
  it('omits blank filters and trims the rest', () => {
    expect(auditQuery({ ...blank, actor: ' Nour ', action: 'lead', entity_id: '12' })).toEqual({
      actor: 'Nour',
      action: 'lead',
      entity_id: '12',
    })
  })

  it('returns an empty object when nothing is filtered', () => {
    expect(auditQuery(blank)).toEqual({})
  })
})

describe('audit labels', () => {
  it('renders the entity type with its id', () => {
    expect(auditEntityLabel(event)).toBe('Lead #12')
    expect(auditEntityLabel({ ...event, entity_type: null })).toBe('—')
    expect(auditEntityLabel({ ...event, entity_id: null })).toBe('Lead')
  })

  it('picks the branch name in the active language', () => {
    const branch = { id: 'b1', name: 'HQ', name_ar: 'الرئيسي', name_en: 'Head Office' }
    expect(auditBranchLabel(branch, 'ar')).toBe('الرئيسي')
    expect(auditBranchLabel(branch, 'en')).toBe('Head Office')
    expect(auditBranchLabel(null, 'en')).toBe('—')
  })
})

describe('auditSnapshot', () => {
  it('pretty-prints snapshots and shows a dash for none', () => {
    expect(auditSnapshot({ name: 'Test' })).toContain('"name": "Test"')
    expect(auditSnapshot(null)).toBe('—')
  })
})
