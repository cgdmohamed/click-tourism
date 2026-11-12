import type { AuditBranch, AuditEvent, AuditFilters } from './types'

const FILTER_KEYS = ['actor', 'action', 'entity_type', 'entity_id', 'branch', 'from', 'to'] as const

/**
 * Query parameters for the audit endpoints (GAP-CORE-02). Blank filters are
 * omitted so the server applies its branch scope instead of an empty match.
 */
export function auditQuery(filters: AuditFilters): Record<string, string> {
  const query: Record<string, string> = {}

  for (const key of FILTER_KEYS) {
    const value = filters[key].trim()
    if (value !== '') query[key] = value
  }

  return query
}

/** Human-readable entity reference for a trail row. */
export function auditEntityLabel(event: AuditEvent): string {
  if (!event.entity_type) return '—'
  return event.entity_id === null ? event.entity_type : `${event.entity_type} #${event.entity_id}`
}

/** Branch label in the active UI language, falling back to the English name. */
export function auditBranchLabel(branch: AuditBranch | null, locale: 'ar' | 'en'): string {
  if (!branch) return '—'
  return locale === 'ar' ? branch.name_ar || branch.name : branch.name_en || branch.name
}

/** Format a before/after snapshot for the details dialog. */
export function auditSnapshot(value: Record<string, unknown> | null): string {
  if (value === null) return '—'
  return JSON.stringify(value, null, 2)
}
