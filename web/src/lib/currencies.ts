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
