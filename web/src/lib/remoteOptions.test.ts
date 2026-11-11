import { describe, expect, it } from 'vitest'
import { hasMoreResults, mergeOptions, pickerParams, rememberOption } from './remoteOptions'

describe('pickerParams', () => {
  it('omits blank search and trims the term', () => {
    expect(pickerParams({ open: 1 }, '   ')).toEqual({ open: 1, per_page: 100 })
    expect(pickerParams(undefined, '  INV-7 ')).toEqual({ per_page: 100, search: 'INV-7' })
  })

  it('lets the caller choose the page size', () => {
    expect(pickerParams({}, '', 20)).toEqual({ per_page: 20 })
  })
})

describe('hasMoreResults', () => {
  it('uses page counts when present', () => {
    expect(hasMoreResults({ current_page: 1, last_page: 3 }, 100)).toBe(true)
    expect(hasMoreResults({ current_page: 1, last_page: 1 }, 40)).toBe(false)
  })

  it('falls back to total, and to false without meta', () => {
    expect(hasMoreResults({ total: 250 }, 100)).toBe(true)
    expect(hasMoreResults({ total: 100 }, 100)).toBe(false)
    expect(hasMoreResults(undefined, 100)).toBe(false)
  })
})

describe('mergeOptions / rememberOption', () => {
  const a = { value: 'a', label: 'A' }
  const b = { value: 'b', label: 'B' }

  it('keeps a remembered selection that is outside the current result', () => {
    expect(mergeOptions([a], [b])).toEqual([a, b])
  })

  it('does not duplicate a selection that is in the result', () => {
    expect(mergeOptions([a, b], [b])).toEqual([a, b])
  })

  it('remembers an option only once', () => {
    const once = rememberOption([], a)
    expect(rememberOption(once, a)).toBe(once)
    expect(rememberOption(once, b)).toEqual([a, b])
  })
})
