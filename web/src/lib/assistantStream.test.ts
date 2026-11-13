import { describe, expect, it } from 'vitest'
import { parseNdjson } from './assistantStream'
import { fromSaved, pickStarters, stepEntity } from './assistant'

describe('assistant stream', () => {
  it('splits complete lines and keeps the unfinished rest', () => {
    const first = parseNdjson('{"type":"thinking"}\n{"type":"tool","tool":"erp_invoices_list","entity":"invoice"}\n{"type":"ans')
    expect(first.events.map((event) => event.type)).toEqual(['thinking', 'tool'])
    expect(first.rest).toBe('{"type":"ans')
    const second = parseNdjson(`${first.rest}wer","data":{"answer":"x","citations":[],"usage":{"prompt":1,"completion":1,"total":2},"truncated":false}}\n`)
    expect(second.events).toHaveLength(1)
    expect(second.events[0].type).toBe('answer')
  })

  it('skips damaged or untyped lines without throwing', () => {
    const parsed = parseNdjson('not json\n{"nope":1}\n\n{"type":"thinking"}\n')
    expect(parsed.events).toEqual([{ type: 'thinking' }])
  })
})

describe('assistant chat helpers', () => {
  it('picks distinct example questions and varies with the random source', () => {
    const a = pickStarters('ar', 4, () => 0)
    const b = pickStarters('ar', 4, () => 0.99)
    expect(new Set(a).size).toBe(4)
    expect(a).not.toEqual(b)
    expect(pickStarters('en', 4)).toHaveLength(4)
  })

  it('names what a step reads and falls back to the system', () => {
    expect(stepEntity('invoice', 'ar')).toBe('الفواتير')
    expect(stepEntity('invoice', 'en')).toBe('invoices')
    expect(stepEntity('record', 'en')).toBe('the system')
  })

  it('maps saved rows to screen messages with unique keys', () => {
    const rows = fromSaved([
      { role: 'user', content: 'q' },
      { role: 'assistant', content: 'a', citations: [], truncated: true },
    ])
    expect(rows.map((row) => row.key)).toEqual([1, 2])
    expect(rows[1].truncated).toBe(true)
  })
})
