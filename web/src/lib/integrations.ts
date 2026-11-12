// Optional Meta Lead Ads / WhatsApp integrations (admin settings screen).
// The API never returns secrets: `configured` holds booleans for env-provided values.

export type SecretsConfigured = { app_secret: boolean; verify_token: boolean; access_token: boolean }

export type MetaStatus = {
  enabled: boolean
  page_id: string
  default_branch_id: string
  form_branch_map: string
  graph_version: string
  webhook_url: string
  configured: SecretsConfigured
  webhook_verified_at: string | null
  last_event_at: string | null
  failed_events: number
}

export type WhatsAppStatus = {
  enabled: boolean
  outbound_enabled: boolean
  phone_number_id: string
  business_account_id: string
  graph_version: string
  stop_keywords: string
  webhook_url: string
  configured: SecretsConfigured
  webhook_verified_at: string | null
  last_event_at: string | null
  failed_events: number
  queued_messages: number
  failed_messages: number
}

export type IntegrationStatus = { meta: MetaStatus; whatsapp: WhatsAppStatus }

export type IntegrationDraft = {
  meta_leads_enabled: boolean
  meta_page_id: string
  meta_default_branch_id: string
  meta_form_branch_map: string
  meta_graph_version: string
  whatsapp_enabled: boolean
  whatsapp_outbound_enabled: boolean
  whatsapp_phone_number_id: string
  whatsapp_business_account_id: string
  whatsapp_graph_version: string
  whatsapp_stop_keywords: string
}

export function draftFromStatus(status: IntegrationStatus): IntegrationDraft {
  return {
    meta_leads_enabled: status.meta.enabled,
    meta_page_id: status.meta.page_id,
    meta_default_branch_id: status.meta.default_branch_id,
    meta_form_branch_map: status.meta.form_branch_map,
    meta_graph_version: status.meta.graph_version,
    whatsapp_enabled: status.whatsapp.enabled,
    whatsapp_outbound_enabled: status.whatsapp.outbound_enabled,
    whatsapp_phone_number_id: status.whatsapp.phone_number_id,
    whatsapp_business_account_id: status.whatsapp.business_account_id,
    whatsapp_graph_version: status.whatsapp.graph_version,
    whatsapp_stop_keywords: status.whatsapp.stop_keywords,
  }
}

/** Only the fields that changed, so an untouched form sends nothing. */
export function settingsPatch(draft: IntegrationDraft, current: IntegrationDraft): Partial<IntegrationDraft> {
  const patch: Record<string, string | boolean> = {}
  for (const key of Object.keys(draft) as Array<keyof IntegrationDraft>) {
    if (draft[key] !== current[key]) patch[key] = draft[key]
  }
  return patch as Partial<IntegrationDraft>
}

/** Env secrets still missing for a provider; used to warn before enabling. */
export function missingSecrets(configured: SecretsConfigured, needsAccessToken = true): Array<keyof SecretsConfigured> {
  const missing: Array<keyof SecretsConfigured> = []
  if (!configured.app_secret) missing.push('app_secret')
  if (!configured.verify_token) missing.push('verify_token')
  if (needsAccessToken && !configured.access_token) missing.push('access_token')
  return missing
}

/** Client-side check mirroring the API: JSON object of numeric form id -> branch id, or empty. */
export function formBranchMapError(value: string): string | null {
  const text = value.trim()
  if (text === '') return null
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return 'invalid_json'
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return 'invalid_json'
  for (const [formId, branchId] of Object.entries(parsed as Record<string, unknown>)) {
    if (!/^\d{1,40}$/.test(formId) || typeof branchId !== 'string' || branchId === '') return 'invalid_entry'
  }
  return null
}
