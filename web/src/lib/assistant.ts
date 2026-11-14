/**
 * Pure helpers for the in-product assistant panel (ADR-0017). No React, so they run in node tests.
 * The conversation lives in memory only: nothing here touches localStorage, sessionStorage or the server.
 */

export type Citation = {
  tool: string
  entity: string
  id: string | null
  /** Document number / names of a cited row, as the server read them; the screen links them in the answer. */
  labels?: string[]
  route: string
  as_of: string
}

/** An action the assistant prepared; nothing is saved until the user confirms it (ADR-0023). */
export type ActionCard = {
  id: string
  tool: string
  title: string
  status: 'pending' | 'confirmed' | 'rejected' | 'failed' | 'expired'
  rows: Array<{ label: string; value: string }>
  expires_at: string
  result: { entity: string; id: string } | null
  error: { code: string | null; message: string | null } | null
}

export type ChatMessage = {
  /** Client-side key only; never sent to the server. */
  key: number
  role: 'user' | 'assistant'
  content: string
  citations?: Citation[]
  actions?: ActionCard[]
  truncated?: boolean
  /** Tools the assistant used for this answer (shown as "Searched: ..."); not saved or sent. */
  steps?: string[]
  /** Failed assistant turn: shown as an error, never sent back to the model. */
  error?: boolean
}

export type ChatReply = {
  answer: string
  citations: Citation[]
  usage: { prompt: number; completion: number; total: number }
  truncated: boolean
  actions?: ActionCard[]
  /** Set when the server saved the turn (ADR-0022); null when saving is off. */
  conversation_id?: string | null
}

/** Server limits (config/assistant.php); the server validates again. */
export const MAX_MESSAGES = 40
export const MAX_MESSAGE_CHARS = 4000

export function addUserMessage(messages: ChatMessage[], content: string): ChatMessage[] {
  return [...messages, { key: nextKey(messages), role: 'user', content: content.trim() }]
}

export function addAssistantReply(messages: ChatMessage[], reply: ChatReply, steps: string[] = []): ChatMessage[] {
  return [
    ...messages,
    { key: nextKey(messages), role: 'assistant', content: reply.answer, citations: reply.citations, truncated: reply.truncated, steps, actions: reply.actions ?? [] },
  ]
}

/** Messages of a saved conversation (GET /assistant/conversations/{id}) as screen messages. */
export function fromSaved(rows: Array<{ role: 'user' | 'assistant'; content: string; citations?: Citation[]; truncated?: boolean; actions?: ActionCard[] }>): ChatMessage[] {
  return rows.map((row, index) => ({ key: index + 1, role: row.role, content: row.content, citations: row.citations ?? [], truncated: row.truncated ?? false, actions: row.actions ?? [] }))
}

export function addAssistantError(messages: ChatMessage[], text: string): ChatMessage[] {
  return [...messages, { key: nextKey(messages), role: 'assistant', content: text, error: true }]
}

function nextKey(messages: ChatMessage[]): number {
  return messages.reduce((max, message) => Math.max(max, message.key), 0) + 1
}

/** Removes the last user turn and everything after it (stop button / retry). */
export function dropLastTurn(messages: ChatMessage[]): ChatMessage[] {
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    if (messages[index].role === 'user') return messages.slice(0, index)
  }
  return messages
}

/**
 * The payload for POST /assistant/chat: roles and text only (no keys, citations or failed turns),
 * newest MAX_MESSAGES, starting with a user message and ending with one.
 */
export function toApiMessages(messages: ChatMessage[]): Array<{ role: 'user' | 'assistant'; content: string }> {
  const usable = messages
    .filter((message) => !message.error && message.content.trim() !== '')
    .map((message) => ({ role: message.role, content: message.content.slice(0, MAX_MESSAGE_CHARS) }))
  let tail = usable.slice(-MAX_MESSAGES)
  while (tail.length > 0 && tail[0].role !== 'user') tail = tail.slice(1)
  while (tail.length > 0 && tail[tail.length - 1].role !== 'user') tail = tail.slice(0, -1)
  return tail
}

export function canSend(draft: string, busy: boolean): boolean {
  const text = draft.trim()
  return !busy && text.length > 0 && Array.from(text).length <= MAX_MESSAGE_CHARS
}

export type AssistantFlags = { assistant_enabled?: boolean; assistant_provider?: string | null }

/** The header button needs both the permission and the installation flag (the API stays the authority). */
export function showAssistant(canUse: boolean, flags: AssistantFlags | undefined): boolean {
  return canUse && flags?.assistant_enabled === true
}

export type CitationLink = { href: string | null; label: string }

const ENTITY_LABEL: Record<string, { en: string; ar: string }> = {
  customer: { en: 'Customer', ar: 'عميل' },
  supplier: { en: 'Supplier', ar: 'مورد' },
  booking: { en: 'Booking', ar: 'حجز' },
  invoice: { en: 'Invoice', ar: 'فاتورة' },
  payment: { en: 'Payments', ar: 'المدفوعات' },
  expense: { en: 'Expenses', ar: 'المصروفات' },
  lead: { en: 'Lead', ar: 'عميل محتمل' },
  report: { en: 'Report', ar: 'تقرير' },
  search: { en: 'Search', ar: 'بحث' },
}

const PUBLIC_ID = /^[A-Za-z0-9_-]{8,64}$/

/**
 * Internal page for a server-built citation, or null href when the record has no page of its own.
 * Only same-origin paths are ever produced; ids are re-validated and encoded, and the model's text
 * can never influence the target.
 */
export function citationLink(citation: Pick<Citation, 'entity' | 'id'>, locale: 'ar' | 'en'): CitationLink {
  const label = ENTITY_LABEL[citation.entity]?.[locale] ?? citation.entity
  const id = citation.id !== null && PUBLIC_ID.test(citation.id) ? encodeURIComponent(citation.id) : null
  switch (citation.entity) {
    case 'customer':
      return { href: id ? `/customers/${id}` : '/customers', label }
    case 'supplier':
      return { href: id ? `/suppliers/${id}` : '/suppliers', label }
    case 'invoice':
      return { href: id ? `/invoices/${id}` : '/invoices', label }
    case 'lead':
      return { href: id ? `/leads/${id}` : '/leads', label }
    case 'booking':
      return { href: id ? `/bookings?booking_id=${id}` : '/bookings', label }
    case 'payment':
      return { href: '/payments', label }
    case 'expense':
      return { href: '/expenses', label }
    case 'report':
      return { href: '/reports', label }
    default:
      return { href: null, label }
  }
}

/** Short "as of" time for a citation, in the viewer's locale; empty when the timestamp is not valid. */
export function formatAsOf(asOf: string, locale: 'ar' | 'en'): string {
  const date = new Date(asOf)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { hour: '2-digit', minute: '2-digit' }).format(date)
}

/** De-duplicated citations in first-seen order (the server already does this; harmless safety). */
export function uniqueCitations(citations: Citation[]): Citation[] {
  const seen = new Set<string>()
  return citations.filter((citation) => {
    const key = `${citation.tool}|${citation.entity}|${citation.id ?? ''}|${citation.route}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

export const STARTERS: Record<'ar' | 'en', string[]> = {
  en: [
    'Which invoices are still open?',
    'Summarize the latest leads.',
    'Show the receivables aging as of today.',
    'Find a customer by name.',
  ],
  ar: ['ما هي الفواتير المفتوحة حتى الآن؟', 'لخّص آخر العملاء المحتملين.', 'اعرض أعمار الذمم المدينة حتى اليوم.', 'ابحث عن عميل بالاسم.'],
}

export type AssistantErrorKey =
  | 'assistantErrDisabled'
  | 'assistantErrNotConfigured'
  | 'assistantErrNotAccepted'
  | 'assistantErrBudget'
  | 'assistantErrRate'
  | 'assistantErrProvider'
  | 'assistantErrForbidden'
  | null

/** Maps an API error (status + code) to a translated message key; null falls back to the generic text. */
export function assistantErrorKey(status: number | undefined, code: string | undefined): AssistantErrorKey {
  switch (code) {
    case 'assistant_disabled':
      return 'assistantErrDisabled'
    case 'assistant_not_configured':
      return 'assistantErrNotConfigured'
    case 'assistant_not_accepted':
      return 'assistantErrNotAccepted'
    case 'assistant_budget_exceeded':
      return 'assistantErrBudget'
    case 'assistant_rate_limited':
      return 'assistantErrRate'
    case 'assistant_provider_error':
      return 'assistantErrProvider'
    default:
      return status === 403 ? 'assistantErrForbidden' : null
  }
}

const ENTITY_STEP: Record<string, { en: string; ar: string }> = {
  customer: { en: 'customers', ar: 'العملاء' },
  supplier: { en: 'suppliers', ar: 'الموردين' },
  booking: { en: 'bookings', ar: 'الحجوزات' },
  invoice: { en: 'invoices', ar: 'الفواتير' },
  payment: { en: 'payments', ar: 'المدفوعات' },
  expense: { en: 'expenses', ar: 'المصروفات' },
  lead: { en: 'leads', ar: 'العملاء المحتملين' },
  report: { en: 'reports', ar: 'التقارير' },
  search: { en: 'the system', ar: 'النظام' },
}

/** Plural name of what a tool reads, for "Searching invoices…". Unknown entities read as the system. */
export function stepEntity(entity: string, locale: 'ar' | 'en'): string {
  return (ENTITY_STEP[entity] ?? ENTITY_STEP.search)[locale]
}

const POOL: Record<'ar' | 'en', string[]> = {
  en: [
    'Which invoices are still open?',
    'Summarize the latest leads.',
    'Show the receivables aging as of today.',
    'Find a customer by name.',
    'Which supplier invoices are due this month?',
    'What were the most recent payments received?',
    'Which bookings are not fully paid yet?',
    'Show the profit and loss report for this month.',
    'Which customers owe us the most?',
    'List the latest expenses and who approved them.',
    'How many new leads came in this week?',
    'What is the status of my latest booking?',
    'Which invoices are overdue?',
    'Show the trial balance as of today.',
  ],
  ar: [
    'ما هي الفواتير المفتوحة حتى الآن؟',
    'لخّص آخر العملاء المحتملين.',
    'اعرض أعمار الذمم المدينة حتى اليوم.',
    'ابحث عن عميل بالاسم.',
    'ما فواتير الموردين المستحقة هذا الشهر؟',
    'ما آخر المدفوعات التي استلمناها؟',
    'أي الحجوزات لم تُسدَّد بالكامل بعد؟',
    'اعرض تقرير الأرباح والخسائر لهذا الشهر.',
    'من هم العملاء الذين عليهم أكبر مبالغ؟',
    'اعرض آخر المصروفات ومن اعتمدها.',
    'كم عميلاً محتملاً جاء هذا الأسبوع؟',
    'ما حالة آخر حجز؟',
    'ما الفواتير المتأخرة عن موعد استحقاقها؟',
    'اعرض ميزان المراجعة حتى اليوم.',
  ],
}

/** `count` different example questions (Fisher-Yates with the given random source). */
export function pickStarters(locale: 'ar' | 'en', count = 4, random: () => number = Math.random): string[] {
  const pool = [...POOL[locale]]
  for (let index = pool.length - 1; index > 0; index -= 1) {
    const other = Math.floor(random() * (index + 1))
    ;[pool[index], pool[other]] = [pool[other], pool[index]]
  }
  return pool.slice(0, count)
}
