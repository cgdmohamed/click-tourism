import { describe, expect, it } from 'vitest'
import { knownStatuses, statusLabel, statusToneOf } from './labels'

describe('status labels and tones', () => {
  it('maps every known status to a tone and both labels', () => {
    expect(knownStatuses.length).toBeGreaterThan(20)
    for (const status of knownStatuses) {
      expect(['neutral', 'info', 'positive', 'warning', 'negative']).toContain(statusToneOf(status))
      expect(statusLabel(status, 'ar')).not.toBe(status)
      expect(statusLabel(status, 'en')).not.toBe(status)
    }
  })

  it('keeps in-progress and closed visually distinct', () => {
    expect(statusToneOf('in_progress')).not.toBe(statusToneOf('closed'))
    expect(statusToneOf('draft')).toBe('neutral')
    expect(statusToneOf('cancelled')).toBe('negative')
  })

  it('labels a submitted invoice as awaiting approval', () => {
    expect(statusLabel('submitted', 'ar')).toBe('بانتظار الاعتماد')
    expect(statusLabel('submitted', 'en')).toBe('Awaiting approval')
  })
})
