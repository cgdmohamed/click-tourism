/**
 * Safe display of the assistant's answer (ADR-0017). The model writes light markdown (bold, lists);
 * this turns it into plain data the component renders as React text nodes: no HTML is ever produced
 * or injected, and a link target comes only from a server-built citation, never from the model's text.
 */
import { citationLink } from './assistant'
import type { Citation } from './assistant'

export type Inline = { kind: 'text' | 'bold'; text: string } | { kind: 'link'; text: string; href: string }

export type ListItem = { inline: Inline[]; children: Inline[][] }

export type Block = { kind: 'p'; inline: Inline[] } | { kind: 'ul' | 'ol'; start: number; items: ListItem[] }

const BULLET = /^\s*[-*•]\s+(.*)$/
const ORDERED = /^\s*(\d{1,3})[.)]\s+(.*)$/
const HEADING = /^\s*#{1,6}\s+(.*)$/

/** `**bold**` becomes a bold run; stray emphasis marks are dropped so no asterisks show on screen. */
export function parseInline(text: string): Inline[] {
  const out: Inline[] = []
  const pattern = /\*\*(.+?)\*\*/g
  let last = 0
  let match: RegExpExecArray | null
  while ((match = pattern.exec(text)) !== null) {
    if (match.index > last) out.push({ kind: 'text', text: text.slice(last, match.index) })
    out.push({ kind: 'bold', text: match[1] })
    last = match.index + match[0].length
  }
  if (last < text.length) out.push({ kind: 'text', text: text.slice(last) })
  return out.map((run) => ({ ...run, text: run.text.replace(/\*\*/g, '') })).filter((run) => run.text !== '')
}

export function parseAnswer(text: string): Block[] {
  const blocks: Block[] = []
  let paragraph: string[] = []

  const flush = () => {
    if (paragraph.length > 0) blocks.push({ kind: 'p', inline: parseInline(paragraph.join('\n')) })
    paragraph = []
  }

  for (const line of text.replace(/\r\n?/g, '\n').split('\n')) {
    if (line.trim() === '') {
      flush()
      continue
    }
    const heading = HEADING.exec(line)
    if (heading) {
      flush()
      blocks.push({ kind: 'p', inline: [{ kind: 'bold', text: heading[1].replace(/\*\*/g, '') }] })
      continue
    }
    const ordered = ORDERED.exec(line)
    const bullet = ordered ? null : BULLET.exec(line)
    if (!ordered && !bullet) {
      paragraph.push(line)
      continue
    }
    flush()
    const last = blocks[blocks.length - 1]
    if (ordered) {
      const item: ListItem = { inline: parseInline(ordered[2]), children: [] }
      if (last && last.kind === 'ol') last.items.push(item)
      else blocks.push({ kind: 'ol', start: Number(ordered[1]), items: [item] })
    } else if (bullet) {
      const inline = parseInline(bullet[1])
      if (last && last.kind === 'ol' && last.items.length > 0) last.items[last.items.length - 1].children.push(inline)
      else if (last && last.kind === 'ul') last.items.push({ inline, children: [] })
      else blocks.push({ kind: 'ul', start: 1, items: [{ inline, children: [] }] })
    }
  }
  flush()
  return blocks
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Turns each occurrence of a cited record's own number or name (taken from the server's data, not
 * the model) into a link to that record's page. Citations without a page are left as text.
 */
export function linkRecords(inline: Inline[], citations: Citation[], locale: 'ar' | 'en'): Inline[] {
  // A label is linked only when every citation that carries it points to the same page: two records
  // sharing a name (a customer and a supplier) would otherwise both link to whichever came first.
  const found = new Map<string, string | null>()
  for (const citation of citations) {
    if (citation.id === null) continue
    const href = citationLink(citation, locale).href
    if (!href) continue
    for (const raw of citation.labels ?? []) {
      const label = raw.trim()
      if (label.length < 4) continue
      found.set(label, found.has(label) && found.get(label) !== href ? null : href)
    }
  }
  const targets = new Map<string, string>()
  found.forEach((href, label) => {
    if (href !== null) targets.set(label, href)
  })
  if (targets.size === 0) return inline

  const pattern = new RegExp(`(${[...targets.keys()].sort((a, b) => b.length - a.length).map(escapeRegExp).join('|')})`, 'g')
  return inline.flatMap((run): Inline[] => {
    if (run.kind === 'link') return [run]
    return run.text
      .split(pattern)
      .filter((part) => part !== '')
      .map((part): Inline => {
        const href = targets.get(part)
        return href ? { kind: 'link', text: part, href } : { ...run, text: part }
      })
  })
}
