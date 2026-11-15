import { describe, expect, it } from 'vitest'
import { MAX_FILES, fileKind, fileProblem } from './assistantFiles'

const f = (name: string, type: string, size = 1000) => ({ name, type, size })

describe('fileKind', () => {
  it('accepts JPG and PNG images, Word and Excel', () => {
    expect(fileKind(f('a.png', 'image/png'))).toBe('image')
    expect(fileKind(f('a.jpg', 'image/jpeg'))).toBe('image')
    expect(fileKind(f('a.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))).toBe('word')
    expect(fileKind(f('a.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))).toBe('excel')
  })

  it('falls back to the extension when the browser gives no type', () => {
    expect(fileKind(f('Scan.JPEG', ''))).toBe('image')
    expect(fileKind(f('report.docx', 'application/octet-stream'))).toBe('word')
    expect(fileKind(f('prices.xlsx', ''))).toBe('excel')
  })

  it('refuses everything else', () => {
    for (const [name, type] of [
      ['a.pdf', 'application/pdf'],
      ['a.webp', 'image/webp'],
      ['a.gif', 'image/gif'],
      ['a.doc', 'application/msword'],
      ['a.xls', 'application/vnd.ms-excel'],
      ['a.txt', 'text/plain'],
      ['a.zip', 'application/zip'],
      ['a.exe', 'application/x-msdownload'],
      ['trick.png.exe', ''],
    ])
      expect(fileKind(f(name, type)), name).toBeNull()
  })
})

describe('fileProblem', () => {
  it('names the first problem', () => {
    expect(fileProblem(f('a.pdf', 'application/pdf'), 0)).toBe('type')
    expect(fileProblem(f('a.png', 'image/png', 0), 0)).toBe('size')
    expect(fileProblem(f('a.png', 'image/png', 11 * 1024 * 1024), 0)).toBe('size')
    expect(fileProblem(f('a.png', 'image/png'), MAX_FILES)).toBe('count')
    expect(fileProblem(f('a.png', 'image/png'), MAX_FILES - 1)).toBeNull()
  })
})
