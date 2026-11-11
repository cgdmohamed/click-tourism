import { describe, expect, it } from 'vitest'
import { taxMinor } from './tax'

/**
 * `taxMinor` mirrors `BookingService::taxFor`:
 *
 *   raw    = gross * rate / (inclusive ? 100 + rate : 100)
 *   result = floor(raw + 0.5)
 *
 * The cases below are hand-computed checkpoints of that rule, so a rounding
 * change on either side shows up here long before a customer sees it.
 */
describe('taxMinor', () => {
  it('applies an exclusive rate', () => {
    expect(taxMinor(10000, '14.0000', false)).toBe(1400)
    expect(taxMinor(1000, '5.5000', false)).toBe(55)
    expect(taxMinor(10000, '7.2500', false)).toBe(725)
  })

  it('extracts an inclusive rate from the gross amount', () => {
    // 10000 * 14 / 114 = 1228.0701…
    expect(taxMinor(10000, '14.0000', true)).toBe(1228)
    // 10001 * 14 / 114 = 1228.1930…
    expect(taxMinor(10001, '14.0000', true)).toBe(1228)
    // 100005 * 14 / 114 = 12281.3157… (KWD amount, 3 decimals)
    expect(taxMinor(100005, '14.0000', true)).toBe(12281)
  })

  it('accepts rates with fewer than four decimals', () => {
    expect(taxMinor(10000, '14', false)).toBe(1400)
    expect(taxMinor(10000, '7.25', false)).toBe(725)
    expect(taxMinor(10000, ' 14.0 ', false)).toBe(1400)
  })

  it('rounds halves up like the server', () => {
    // 1 * 50 / 100 = 0.5 -> 1
    expect(taxMinor(1, '50', false)).toBe(1)
    // 1 * 14 / 114 = 0.1228 -> 0
    expect(taxMinor(1, '14', true)).toBe(0)
    // 3 * 50 / 100 = 1.5 -> 2
    expect(taxMinor(3, '50', false)).toBe(2)
  })

  it('returns zero for a zero rate, a zero amount or a non-numeric rate', () => {
    expect(taxMinor(10000, '0', false)).toBe(0)
    expect(taxMinor(10000, '0.0000', true)).toBe(0)
    expect(taxMinor(0, '14.0000', false)).toBe(0)
    expect(taxMinor(10000, '', false)).toBe(0)
    expect(taxMinor(10000, 'abc', false)).toBe(0)
    expect(taxMinor(10000, '-14', false)).toBe(0)
  })

  it('does not lose precision on large amounts', () => {
    expect(taxMinor(999999999999, '14.0000', false)).toBe(140000000000)
    expect(taxMinor(999999999999, '14.0000', true)).toBe(122807017544)
  })
})
