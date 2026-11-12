import { describe, expect, it } from 'vitest'
import { filenameFromDisposition } from './download'

describe('filenameFromDisposition', () => {
  it('reads a quoted filename', () => {
    expect(filenameFromDisposition('attachment; filename="invoice-INV-1.pdf"', 'fallback.pdf'))
      .toBe('invoice-INV-1.pdf')
  })

  it('reads an unquoted filename and ignores trailing headers', () => {
    expect(filenameFromDisposition('attachment; filename=trial-balance.csv; size=10', 'fallback.csv'))
      .toBe('trial-balance.csv')
  })

  it('prefers the RFC 5987 encoded filename and decodes it', () => {
    expect(filenameFromDisposition("attachment; filename=\"report.pdf\"; filename*=UTF-8''%D8%AA%D9%82%D8%B1%D9%8A%D8%B1.pdf", 'fallback.pdf'))
      .toBe('تقرير.pdf')
  })

  it('falls back when the header is missing, empty or malformed', () => {
    expect(filenameFromDisposition(undefined, 'fallback.pdf')).toBe('fallback.pdf')
    expect(filenameFromDisposition('', 'fallback.pdf')).toBe('fallback.pdf')
    expect(filenameFromDisposition('attachment', 'fallback.pdf')).toBe('fallback.pdf')
    expect(filenameFromDisposition("attachment; filename*=UTF-8''%E0%A4%A", 'fallback.pdf')).toBe('%E0%A4%A')
  })
})
