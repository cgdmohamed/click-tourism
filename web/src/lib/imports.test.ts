import { describe, expect, it } from 'vitest'
import {
  canConfirmImport,
  formatBytes,
  importFileErrorText,
  importPollInterval,
  importRowErrorText,
  importStatusLabel,
  importStatusTone,
  isImportActive,
  isImportFinal,
} from './imports'

describe('import status helpers', () => {
  it('tells active from final states', () => {
    expect(isImportActive('queued')).toBe(true)
    expect(isImportActive('running')).toBe(true)
    expect(isImportActive('previewed')).toBe(false)
    expect(isImportFinal('completed')).toBe(true)
    expect(isImportFinal('partial')).toBe(true)
    expect(isImportFinal('failed')).toBe(true)
    expect(isImportFinal('previewed')).toBe(false)
  })

  it('polls only while the worker may still be running', () => {
    expect(importPollInterval('queued')).toBe(2000)
    expect(importPollInterval('running')).toBe(2000)
    expect(importPollInterval('completed')).toBe(false)
    expect(importPollInterval(undefined)).toBe(false)
  })

  it('maps statuses to tones and bilingual labels', () => {
    expect(importStatusTone('completed')).toBe('positive')
    expect(importStatusTone('partial')).toBe('warning')
    expect(importStatusTone('failed')).toBe('negative')
    expect(importStatusLabel('partial', 'en')).toBe('Partially imported')
    expect(importStatusLabel('partial', 'ar')).toBe('مكتمل جزئياً')
  })
})

describe('canConfirmImport', () => {
  const base = { status: 'previewed' as const, created_count: 2, updated_count: 0, error_count: 0, on_error: 'skip' as const }

  it('allows a preview that would write rows', () => {
    expect(canConfirmImport(base)).toBe(true)
    expect(canConfirmImport({ ...base, error_count: 3 })).toBe(true)
  })

  it('blocks nothing-to-do, abort-with-errors and non-preview jobs', () => {
    expect(canConfirmImport({ ...base, created_count: 0 })).toBe(false)
    expect(canConfirmImport({ ...base, error_count: 1, on_error: 'abort' })).toBe(false)
    expect(canConfirmImport({ ...base, status: 'completed' })).toBe(false)
  })
})

describe('error text', () => {
  const error = { row: 3, field: 'email', code: 'invalid_email', message: 'Email is not a valid email address.', params: {} }

  it('localises known codes in Arabic and keeps the server text in English', () => {
    expect(importRowErrorText(error, 'en')).toBe(error.message)
    expect(importRowErrorText(error, 'ar')).toBe('بريد إلكتروني غير صالح')
    expect(importRowErrorText({ ...error, code: 'too_many_decimals', params: { max: 2 } }, 'ar')).toContain('2')
  })

  it('falls back to the server text for unknown codes', () => {
    expect(importRowErrorText({ ...error, code: 'something_new', message: 'Hello' }, 'ar')).toBe('Hello')
    expect(importFileErrorText('invalid_encoding', 'x', 'ar')).toContain('UTF-8')
    expect(importFileErrorText('nope', 'fallback', 'ar')).toBe('fallback')
    expect(importFileErrorText('invalid_encoding', 'server text', 'en')).toBe('server text')
  })
})

describe('formatBytes', () => {
  it('scales units', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB')
  })
})
