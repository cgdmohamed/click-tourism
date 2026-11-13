/**
 * Pure helpers for the assistant setup screen and the dashboard switch (ADR-0017).
 * No React: covered by node-env tests. The provider key itself is never handled here;
 * the API only reports whether it exists in the server environment.
 */

export type AssistantModel = { id: string; recommended: boolean }

export type AssistantModels = {
  key_configured: boolean
  provider: string
  recommended: string | null
  models: AssistantModel[]
}

/** The assistant settings group as the Settings API returns it (before mapping). */
export type SettingRow = { group: string; key: string; value: string | null }

export type AssistantSettings = {
  /** Stored `enabled` value: 'true' or 'false'. */
  enabled: string
  /** 'none' until a provider is saved. */
  provider: string
  /** Blank means the server default model applies. */
  model: string
  /** '' or an ISO timestamp of the accepted data-processing notice. */
  acceptedAt: string
}

export type AssistantDraft = {
  enabled: boolean
  model: string
  acceptedAt: string
}

export type AssistantSettingKey = 'enabled' | 'provider' | 'model' | 'data_processing_accepted_at'

export type AssistantSettingPatch = { group: 'assistant'; key: AssistantSettingKey; value: string }

export const ASSISTANT_GROUP = 'assistant' as const

/** Maps GET /settings rows to the assistant group, with the server defaults for missing rows. */
export function readAssistantSettings(rows: readonly SettingRow[]): AssistantSettings {
  const value = (key: AssistantSettingKey) => rows.find((row) => row.group === ASSISTANT_GROUP && row.key === key)?.value ?? ''
  return {
    enabled: value('enabled'),
    provider: value('provider') || 'none',
    model: value('model'),
    acceptedAt: value('data_processing_accepted_at'),
  }
}

/** An acceptance value counts only when it carries a timestamp. */
export function isAccepted(value: string | null | undefined): boolean {
  return (value ?? '').trim() !== ''
}

/** The provider's recommended model id, or null when it did not recommend one. */
export function recommendedModel(models: readonly AssistantModel[]): string | null {
  return models.find((model) => model.recommended)?.id ?? null
}

/**
 * The model to show in the picker: the stored one while the provider still offers it,
 * otherwise the recommended model, otherwise the first listed model ('' when none).
 */
export function initialModel(stored: string | null | undefined, models: readonly AssistantModel[]): string {
  const trimmed = (stored ?? '').trim()
  if (trimmed !== '' && models.some((model) => model.id === trimmed)) return trimmed
  return recommendedModel(models) ?? models[0]?.id ?? ''
}

/** Turning the assistant on needs a model, the accepted notice and the provider key. */
export function canEnableAssistant(state: { model: string; accepted: boolean; keyConfigured: boolean }): boolean {
  return state.keyConfigured && state.accepted && state.model.trim() !== ''
}

/** The settings entry for the acceptance checkbox: now when checked, empty when withdrawn. */
export function acceptancePatch(checked: boolean, now: Date = new Date()): AssistantSettingPatch {
  return {
    group: ASSISTANT_GROUP,
    key: 'data_processing_accepted_at',
    value: checked ? now.toISOString() : '',
  }
}

/** The dashboard switch writes only the master switch. */
export function enabledPatch(enabled: boolean): AssistantSettingPatch {
  return { group: ASSISTANT_GROUP, key: 'enabled', value: enabled ? 'true' : 'false' }
}

/**
 * Only the assistant entries that actually changed, plus `provider: openai` whenever a model
 * is (re)saved or the assistant is being switched on with a model in place.
 */
export function assistantChanges(current: AssistantSettings, draft: AssistantDraft): AssistantSettingPatch[] {
  const changes: AssistantSettingPatch[] = []
  const model = draft.model.trim()
  const modelChanged = model !== current.model.trim()

  if (modelChanged) changes.push({ group: ASSISTANT_GROUP, key: 'model', value: model })
  if (model !== '' && (modelChanged || draft.enabled) && current.provider !== 'openai') {
    changes.push({ group: ASSISTANT_GROUP, key: 'provider', value: 'openai' })
  }
  if (draft.acceptedAt !== current.acceptedAt) {
    changes.push({ group: ASSISTANT_GROUP, key: 'data_processing_accepted_at', value: draft.acceptedAt })
  }
  const enabled = draft.enabled ? 'true' : 'false'
  if (enabled !== (current.enabled === 'true' ? 'true' : 'false')) {
    changes.push({ group: ASSISTANT_GROUP, key: 'enabled', value: enabled })
  }
  return changes
}

export type AssistantModelsError = 'not-configured' | 'provider-error' | 'forbidden' | 'unavailable'

/** Maps GET /assistant/models failures to the notice the setup screen shows (404 = not deployed yet). */
export function modelsErrorState(status: number | undefined, code: string | undefined): AssistantModelsError {
  if (code === 'assistant_not_configured') return 'not-configured'
  if (code === 'assistant_provider_error' || status === 502) return 'provider-error'
  if (status === 403) return 'forbidden'
  if (status === 503) return 'not-configured'
  return 'unavailable'
}
