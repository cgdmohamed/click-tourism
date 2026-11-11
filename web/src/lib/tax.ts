/**
 * Mirrors BookingService::taxFor with integer math (rate has up to 4 decimals).
 * Kept in `lib` so the UI preview can be tested against the server rule.
 */
export function taxMinor(grossMinor: number, rate: string, inclusive: boolean): number {
  const match = /^(\d+)(?:\.(\d+))?$/.exec(rate.trim())
  if (!match || grossMinor <= 0) return 0
  const scaled = BigInt(match[1] + (match[2] ?? '').padEnd(4, '0').slice(0, 4))
  if (scaled === 0n) return 0
  const scale = 10000n
  const denominator = (inclusive ? 100n * scale + scaled : 100n * scale) * 2n
  const numerator = BigInt(grossMinor) * scaled * 2n + denominator / 2n
  return Number(numerator / denominator)
}
