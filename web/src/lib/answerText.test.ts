import { describe, expect, it } from 'vitest'
import { linkRecords, parseAnswer, parseInline } from './answerText'
import type { Citation } from './assistant'

const cite = (over: Partial<Citation>): Citation => ({ tool: 'erp_invoices_list', entity: 'invoice', id: 'abcdefgh1234', route: '/invoices', as_of: '2026-11-13T10:00:00Z', ...over })

describe('answerText', () => {
  it('turns **bold** into runs and drops stray marks', () => {
    expect(parseInline('a **b** c')).toEqual([
      { kind: 'text', text: 'a ' },
      { kind: 'bold', text: 'b' },
      { kind: 'text', text: ' c' },
    ])
    expect(JSON.stringify(parseInline('x ** y'))).not.toContain('**')
  })

  it('builds numbered lists with nested bullets and keeps numbering', () => {
    const blocks = parseAnswer('1. **المورد**\n- **الرقم**: BIL-1\n- المبلغ\n\n2. **العميل**\n- INV-1')
    expect(blocks).toHaveLength(1)
    const list = blocks[0]
    expect(list.kind).toBe('ol')
    if (list.kind !== 'ol') return
    expect(list.items).toHaveLength(2)
    expect(list.items[0].children).toHaveLength(2)
    expect(list.items[1].children).toHaveLength(1)
  })

  it('renders headings as bold and paragraphs as text', () => {
    expect(parseAnswer('## عنوان\nنص')[0]).toEqual({ kind: 'p', inline: [{ kind: 'bold', text: 'عنوان' }] })
  })

  it('links a record number to its page only through a server citation', () => {
    const runs = linkRecords(parseInline('الرقم: BIL-HQ-2026-000001 ثم INV-9'), [cite({ labels: ['BIL-HQ-2026-000001'] })], 'ar')
    expect(runs.some((run) => run.kind === 'link' && run.href === '/invoices/abcdefgh1234' && run.text === 'BIL-HQ-2026-000001')).toBe(true)
    expect(runs.some((run) => run.kind === 'link' && run.text === 'INV-9')).toBe(false)
  })

  it('never links without a server label or id, and escapes regex characters', () => {
    expect(linkRecords(parseInline('a (b) c'), [cite({ labels: [] })], 'en')).toEqual(parseInline('a (b) c'))
    expect(linkRecords(parseInline('a (b) c'), [cite({ id: null, labels: ['a (b)'] })], 'en')).toEqual(parseInline('a (b) c'))
    const runs = linkRecords(parseInline('see a (b) c'), [cite({ labels: ['a (b)'] })], 'en')
    expect(runs.some((run) => run.kind === 'link')).toBe(true)
  })
})
