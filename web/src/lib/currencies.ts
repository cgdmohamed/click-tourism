export type CurrencyRow = {
  code: string
  name: string
  symbol: string | null
  exponent: number
  is_active: boolean
}

export type CurrencyDetail = CurrencyRow & { is_base: boolean; in_use: boolean }

/** Uppercase letters only, at most three (the code is typed live in the add form). */
export function normalizeCurrencyCode(value: string): string {
  return value.replace(/[^a-zA-Z]/g, '').toUpperCase().slice(0, 3)
}

export function isValidCurrencyCode(value: string): boolean {
  return /^[A-Z]{3}$/.test(value)
}

/** Currencies that may be offered for NEW documents; inactive ones only stay on old records. */
export function activeCurrencies<T extends { is_active: boolean }>(rows: readonly T[] | undefined): T[] {
  return (rows ?? []).filter((row) => row.is_active)
}

/**
 * The currency a NEW draft should really use: the one in the form while it is still offered,
 * otherwise the installation's base currency, otherwise the first active one. Keeps the value
 * that is shown, the value that is submitted and the values of the line items in step when
 * the default (or a catalogue currency) has been deactivated.
 */
export function resolveCurrency(current: string, codes: readonly string[], base?: string): string {
  if (codes.includes(current)) return current
  if (base && codes.includes(base)) return base
  return codes[0] ?? current
}
