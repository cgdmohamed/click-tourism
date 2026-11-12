import { describe, expect, it } from 'vitest'
import { draftFromStatus, formBranchMapError, missingSecrets, settingsPatch } from './integrations'
import type { IntegrationStatus } from './integrations'

const configured = { app_secret: false, verify_token: false, access_token: false }
const status: IntegrationStatus = {
  meta: {
    enabled: false,
    page_id: '',
    default_branch_id: '',
    form_branch_map: '',
    graph_version: 'v21.0',
    webhook_url: 'https://erp.example.com/api/v1/webhooks/meta',
    configured,
    webhook_verified_at: null,
    last_event_at: null,
    failed_events: 0,
  },
  whatsapp: {
    enabled: false,
    outbound_enabled: false,
    phone_number_id: '',
    business_account_id: '',
    graph_version: 'v21.0',
    stop_keywords: 'stop',
    webhook_url: 'https://erp.example.com/api/v1/webhooks/whatsapp',
    configured,
    webhook_verified_at: null,
    last_event_at: null,
    failed_events: 0,
    queued_messages: 0,
    failed_messages: 0,
  },
}

describe('settingsPatch', () => {
  it('sends nothing for an untouched form', () => {
    const draft = draftFromStatus(status)
    expect(settingsPatch(draft, draftFromStatus(status))).toEqual({})
  })

  it('sends only the changed non-secret fields', () => {
    const current = draftFromStatus(status)
    const patch = settingsPatch({ ...current, meta_leads_enabled: true, meta_page_id: '123' }, current)
    expect(patch).toEqual({ meta_leads_enabled: true, meta_page_id: '123' })
  })

  it('never contains secret keys', () => {
    const keys = Object.keys(draftFromStatus(status))
    for (const forbidden of ['app_secret', 'access_token', 'verify_token']) {
      expect(keys.some((key) => key.includes(forbidden))).toBe(false)
    }
  })
})

describe('missingSecrets', () => {
  it('lists every missing env secret', () => {
    expect(missingSecrets(configured)).toEqual(['app_secret', 'verify_token', 'access_token'])
  })

  it('can skip the access token', () => {
    expect(missingSecrets({ ...configured, app_secret: true }, false)).toEqual(['verify_token'])
  })

  it('is empty when everything is configured', () => {
    expect(missingSecrets({ app_secret: true, verify_token: true, access_token: true })).toEqual([])
  })
})

describe('formBranchMapError', () => {
  it('accepts empty and valid maps', () => {
    expect(formBranchMapError('')).toBeNull()
    expect(formBranchMapError('{"9001":"01ABC"}')).toBeNull()
  })

  it('rejects malformed JSON, arrays and bad entries', () => {
    expect(formBranchMapError('nope')).toBe('invalid_json')
    expect(formBranchMapError('[]')).toBe('invalid_json')
    expect(formBranchMapError('{"abc":"01ABC"}')).toBe('invalid_entry')
    expect(formBranchMapError('{"9001":5}')).toBe('invalid_entry')
  })
})
