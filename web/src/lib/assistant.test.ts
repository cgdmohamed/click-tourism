import { describe, expect, it } from 'vitest'
import {
  MAX_MESSAGES,
  addAssistantError,
  addAssistantReply,
  addUserMessage,
  assistantErrorKey,
  canSend,
  citationLink,
  dropLastTurn,
  formatAsOf,
  showAssistant,
  toApiMessages,
  uniqueCitations,
} from './assistant'
import type { ChatMessage, Citation } from './assistant'

const reply = { answer: 'Two invoices.', citations: [], usage: { prompt: 1, completion: 1, total: 2 }, truncated: false }

describe('message state', () => {
  it('appends user and assistant turns with increasing keys and trims the user text', () => {
    let messages: ChatMessage[] = []
    messages = addUserMessage(messages, '  hello  ')
    messages = addAssistantReply(messages, reply)
    messages = addUserMessage(messages, 'again')
    expect(messages.map((m) => [m.key, m.role, m.content])).toEqual([
      [1, 'user', 'hello'],
      [2, 'assistant', 'Two invoices.'],
      [3, 'user', 'again'],
    ])
  })

  it('never mutates the previous state', () => {
    const before: ChatMessage[] = []
    const after = addUserMessage(before, 'x')
    expect(before).toHaveLength(0)
    expect(after).toHaveLength(1)
  })

  it('dropLastTurn removes the last user message and what follows', () => {
    let messages = addUserMessage([], 'a')
    messages = addAssistantReply(messages, reply)
    messages = addUserMessage(messages, 'b')
    messages = addAssistantError(messages, 'failed')
    expect(dropLastTurn(messages).map((m) => m.content)).toEqual(['a', 'Two invoices.'])
    expect(dropLastTurn([])).toEqual([])
  })
})

describe('toApiMessages', () => {
  it('sends roles and text only, skipping failed turns', () => {
    let messages = addUserMessage([], 'first')
    messages = addAssistantReply(messages, { ...reply, citations: [{ tool: 't', entity: 'invoice', id: null, route: '/invoices', as_of: '2026-01-01T00:00:00Z' }] })
    messages = addUserMessage(messages, 'second')
    messages = addAssistantError(messages, 'boom')
    messages = addUserMessage(messages, 'third')
    const api = toApiMessages(messages)
    expect(api).toEqual([
      { role: 'user', content: 'first' },
      { role: 'assistant', content: 'Two invoices.' },
      { role: 'user', content: 'second' },
      { role: 'user', content: 'third' },
    ])
    expect(Object.keys(api[0])).toEqual(['role', 'content'])
  })

  it('keeps at most the newest MAX_MESSAGES, starting and ending with a user message', () => {
    let messages: ChatMessage[] = []
    for (let i = 0; i < 30; i += 1) {
      messages = addUserMessage(messages, `q${i}`)
      messages = addAssistantReply(messages, { ...reply, answer: `a${i}` })
    }
    messages = addUserMessage(messages, 'last')
    const api = toApiMessages(messages)
    expect(api.length).toBeLessThanOrEqual(MAX_MESSAGES)
    expect(api[0].role).toBe('user')
    expect(api[api.length - 1]).toEqual({ role: 'user', content: 'last' })
  })

  it('returns nothing when there is no user message', () => {
    expect(toApiMessages(addAssistantError([], 'x'))).toEqual([])
  })
})

describe('canSend', () => {
  it('needs text, no request in flight and a length within the limit', () => {
    expect(canSend('hi', false)).toBe(true)
    expect(canSend('   ', false)).toBe(false)
    expect(canSend('hi', true)).toBe(false)
    expect(canSend('x'.repeat(4001), false)).toBe(false)
  })
})

describe('showAssistant', () => {
  it('needs the permission and the installation flag', () => {
    expect(showAssistant(true, { assistant_enabled: true })).toBe(true)
    expect(showAssistant(false, { assistant_enabled: true })).toBe(false)
    expect(showAssistant(true, { assistant_enabled: false })).toBe(false)
    expect(showAssistant(true, undefined)).toBe(false)
  })
})

describe('citationLink', () => {
  const id = '01HXAAAAAAAAAAAAAAAAAAAAAA'

  it('builds internal record pages from entity and id', () => {
    expect(citationLink({ entity: 'invoice', id }, 'en')).toEqual({ href: `/invoices/${id}`, label: 'Invoice' })
    expect(citationLink({ entity: 'customer', id }, 'ar').href).toBe(`/customers/${id}`)
    expect(citationLink({ entity: 'supplier', id }, 'en').href).toBe(`/suppliers/${id}`)
    expect(citationLink({ entity: 'lead', id }, 'en').href).toBe(`/leads/${id}`)
    expect(citationLink({ entity: 'booking', id }, 'en').href).toBe(`/bookings?booking_id=${id}`)
  })

  it('falls back to list pages for list calls and records without a page', () => {
    expect(citationLink({ entity: 'invoice', id: null }, 'en').href).toBe('/invoices')
    expect(citationLink({ entity: 'payment', id }, 'en').href).toBe('/payments')
    expect(citationLink({ entity: 'expense', id: null }, 'en').href).toBe('/expenses')
    expect(citationLink({ entity: 'report', id: null }, 'ar')).toEqual({ href: '/reports', label: 'تقرير' })
    expect(citationLink({ entity: 'search', id: null }, 'en').href).toBeNull()
    expect(citationLink({ entity: 'unknown', id: null }, 'en')).toEqual({ href: null, label: 'unknown' })
  })

  it('never produces an external or traversal target, whatever the id says', () => {
    for (const bad of ['https://evil.example/x', '../../etc/passwd', '//evil.example', 'a b c d e f g h', 'javascript:alert(1)']) {
      const { href } = citationLink({ entity: 'invoice', id: bad }, 'en')
      expect(href).toBe('/invoices')
    }
    for (const entity of ['customer', 'supplier', 'invoice', 'lead', 'booking', 'payment', 'expense', 'report']) {
      const href = citationLink({ entity, id }, 'en').href
      expect(href).toMatch(/^\/[a-z]/)
      expect(href).not.toContain('//')
    }
  })
})

describe('misc', () => {
  it('formats the as-of time and tolerates garbage', () => {
    expect(formatAsOf('2026-11-13T14:30:00Z', 'en')).toMatch(/\d{2}:\d{2}/)
    expect(formatAsOf('nope', 'en')).toBe('')
  })

  it('de-duplicates citations', () => {
    const c: Citation = { tool: 'a', entity: 'invoice', id: null, route: '/invoices', as_of: 'x' }
    expect(uniqueCitations([c, { ...c }, { ...c, id: 'abcdefgh' }])).toHaveLength(2)
  })

  it('maps API error codes to message keys', () => {
    expect(assistantErrorKey(403, 'assistant_disabled')).toBe('assistantErrDisabled')
    expect(assistantErrorKey(503, 'assistant_not_configured')).toBe('assistantErrNotConfigured')
    expect(assistantErrorKey(429, 'assistant_budget_exceeded')).toBe('assistantErrBudget')
    expect(assistantErrorKey(429, 'assistant_rate_limited')).toBe('assistantErrRate')
    expect(assistantErrorKey(502, 'assistant_provider_error')).toBe('assistantErrProvider')
    expect(assistantErrorKey(403, 'forbidden')).toBe('assistantErrForbidden')
    expect(assistantErrorKey(500, undefined)).toBeNull()
  })
})
