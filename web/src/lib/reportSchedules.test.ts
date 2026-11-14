import { describe, expect, it } from 'vitest'
import { dayOptions, invalidRecipients, parseRecipients } from './reportSchedules'

describe('reportSchedules', () => {
  it('splits, lower-cases and de-duplicates recipients', () => {
    expect(parseRecipients('Boss@Example.com, cfo@example.com;\nboss@example.com  ')).toEqual(['boss@example.com', 'cfo@example.com'])
    expect(parseRecipients('')).toEqual([])
  })

  it('names the addresses that are not valid', () => {
    expect(invalidRecipients(['a@b.co', 'nope', 'x@y'])).toEqual(['nope', 'x@y'])
  })

  it('offers the days of each frequency', () => {
    expect(dayOptions('daily')).toEqual([])
    expect(dayOptions('weekly')).toHaveLength(7)
    expect(dayOptions('monthly')).toHaveLength(28)
  })
})
