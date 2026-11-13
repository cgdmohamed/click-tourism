import { describe, expect, it } from 'vitest'
import {
  acceptancePatch,
  assistantChanges,
  canEnableAssistant,
  enabledPatch,
  initialModel,
  isAccepted,
  modelsErrorState,
  readAssistantSettings,
  recommendedModel,
} from './assistantSetup'
import type { AssistantModel, AssistantSettings, SettingRow } from './assistantSetup'

const models: AssistantModel[] = [
  { id: 'gpt-4o-mini', recommended: false },
  { id: 'gpt-4.1-mini', recommended: true },
]

const stored: AssistantSettings = { enabled: 'false', provider: 'none', model: '', acceptedAt: '' }

describe('assistant setup helpers', () => {
  it('reads the assistant settings group with server defaults', () => {
    const rows: SettingRow[] = [
      { group: 'assistant', key: 'enabled', value: 'true' },
      { group: 'assistant', key: 'provider', value: 'openai' },
      { group: 'assistant', key: 'model', value: 'gpt-4.1-mini' },
      { group: 'assistant', key: 'data_processing_accepted_at', value: '2026-11-13T10:00:00Z' },
      { group: 'tax', key: 'enabled', value: 'false' },
    ]
    expect(readAssistantSettings(rows)).toEqual({
      enabled: 'true',
      provider: 'openai',
      model: 'gpt-4.1-mini',
      acceptedAt: '2026-11-13T10:00:00Z',
    })
    expect(readAssistantSettings([])).toEqual({ enabled: '', provider: 'none', model: '', acceptedAt: '' })
  })

  it('treats only a timestamp as acceptance', () => {
    expect(isAccepted('2026-11-13T10:00:00Z')).toBe(true)
    expect(isAccepted('')).toBe(false)
    expect(isAccepted('   ')).toBe(false)
    expect(isAccepted(null)).toBe(false)
  })

  it('finds the recommended model', () => {
    expect(recommendedModel(models)).toBe('gpt-4.1-mini')
    expect(recommendedModel([{ id: 'a', recommended: false }])).toBeNull()
    expect(recommendedModel([])).toBeNull()
  })

  it('preselects the stored model, else the recommended one', () => {
    expect(initialModel('gpt-4o-mini', models)).toBe('gpt-4o-mini')
    expect(initialModel('', models)).toBe('gpt-4.1-mini')
    expect(initialModel(null, models)).toBe('gpt-4.1-mini')
    // The stored model is no longer offered: fall back to the recommendation.
    expect(initialModel('gpt-3.5-turbo', models)).toBe('gpt-4.1-mini')
    expect(initialModel('', [{ id: 'a', recommended: false }])).toBe('a')
    expect(initialModel('', [])).toBe('')
  })

  it('enables only with a model, acceptance and the provider key', () => {
    const base = { model: 'gpt-4.1-mini', accepted: true, keyConfigured: true }
    expect(canEnableAssistant(base)).toBe(true)
    expect(canEnableAssistant({ ...base, model: '' })).toBe(false)
    expect(canEnableAssistant({ ...base, model: '   ' })).toBe(false)
    expect(canEnableAssistant({ ...base, accepted: false })).toBe(false)
    expect(canEnableAssistant({ ...base, keyConfigured: false })).toBe(false)
  })

  it('patches acceptance and the master switch', () => {
    const now = new Date('2026-11-13T10:00:00.000Z')
    expect(acceptancePatch(true, now)).toEqual({
      group: 'assistant',
      key: 'data_processing_accepted_at',
      value: '2026-11-13T10:00:00.000Z',
    })
    expect(acceptancePatch(false, now).value).toBe('')
    expect(enabledPatch(true)).toEqual({ group: 'assistant', key: 'enabled', value: 'true' })
    expect(enabledPatch(false).value).toBe('false')
  })

  it('sends only changed assistant entries and the provider with a saved model', () => {
    expect(assistantChanges(stored, { enabled: false, model: '', acceptedAt: '' })).toEqual([])

    expect(assistantChanges(stored, { enabled: false, model: 'gpt-4.1-mini', acceptedAt: '' })).toEqual([
      { group: 'assistant', key: 'model', value: 'gpt-4.1-mini' },
      { group: 'assistant', key: 'provider', value: 'openai' },
    ])

    const accepted = '2026-11-13T10:00:00.000Z'
    expect(assistantChanges(stored, { enabled: false, model: '', acceptedAt: accepted })).toEqual([
      { group: 'assistant', key: 'data_processing_accepted_at', value: accepted },
    ])

    expect(assistantChanges(stored, { enabled: true, model: 'gpt-4.1-mini', acceptedAt: accepted })).toEqual([
      { group: 'assistant', key: 'model', value: 'gpt-4.1-mini' },
      { group: 'assistant', key: 'provider', value: 'openai' },
      { group: 'assistant', key: 'data_processing_accepted_at', value: accepted },
      { group: 'assistant', key: 'enabled', value: 'true' },
    ])

    // Unchanged model but the assistant is switched on: the provider is still filled in.
    const configured: AssistantSettings = { enabled: 'false', provider: 'none', model: 'gpt-4.1-mini', acceptedAt: accepted }
    expect(assistantChanges(configured, { enabled: true, model: 'gpt-4.1-mini', acceptedAt: accepted })).toEqual([
      { group: 'assistant', key: 'provider', value: 'openai' },
      { group: 'assistant', key: 'enabled', value: 'true' },
    ])

    // Already on OpenAI: no redundant provider entry.
    const openai: AssistantSettings = { enabled: 'true', provider: 'openai', model: 'gpt-4.1-mini', acceptedAt: accepted }
    expect(assistantChanges(openai, { enabled: false, model: 'gpt-4.1-mini', acceptedAt: accepted })).toEqual([
      { group: 'assistant', key: 'enabled', value: 'false' },
    ])
  })

  it('maps model-list failures to a notice', () => {
    expect(modelsErrorState(503, 'assistant_not_configured')).toBe('not-configured')
    expect(modelsErrorState(503, undefined)).toBe('not-configured')
    expect(modelsErrorState(502, 'assistant_provider_error')).toBe('provider-error')
    expect(modelsErrorState(502, undefined)).toBe('provider-error')
    expect(modelsErrorState(403, undefined)).toBe('forbidden')
    expect(modelsErrorState(404, undefined)).toBe('unavailable')
    expect(modelsErrorState(undefined, undefined)).toBe('unavailable')
  })
})
