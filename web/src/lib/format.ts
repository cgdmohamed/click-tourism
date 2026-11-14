export const CURRENCY_EXPONENT: Record<string, number> = {
  EGP: 2,
  SAR: 2,
  USD: 2,
  EUR: 2,
  GBP: 2,
  AED: 2,
  KWD: 3,
  BHD: 3,
  OMR: 3,
  JOD: 3,
}

/** Exponents of currencies created in Settings > Currencies (the built-in table above wins). */
let configuredExponents: Record<string, number> = {}

export function registerCurrencyExponents(rows: ReadonlyArray<{ code: string; exponent: number }>): void {
  configuredExponents = Object.fromEntries(rows.map((row) => [row.code.toUpperCase(), row.exponent]))
}

export function exponent(currency: string): number {
  const code = currency.toUpperCase()
  return CURRENCY_EXPONENT[code] ?? configuredExponents[code] ?? 2
}

export function toMinor(amount: string | number, currency: string): number {
  const raw = typeof amount === 'number' ? amount.toString() : amount
  const cleaned = raw.replace(/,/g, '').trim()
  if (!/^-?\d+(\.\d+)?$/.test(cleaned)) return 0
  const negative = cleaned.startsWith('-')
  const [whole, fraction = ''] = cleaned.replace('-', '').split('.')
  const scale = exponent(currency)
  const padded = (fraction + '0'.repeat(scale)).slice(0, scale)
  const value = Number.parseInt(whole || '0', 10) * 10 ** scale + Number.parseInt(padded || '0', 10)
  return negative ? -value : value
}

export function toDecimal(amountMinor: number, currency: string): string {
  const scale = exponent(currency)
  const negative = amountMinor < 0
  const absolute = Math.abs(amountMinor)
  const whole = Math.floor(absolute / 10 ** scale)
  const fraction = (absolute % 10 ** scale).toString().padStart(scale, '0')
  return `${negative ? '-' : ''}${whole}${scale ? `.${fraction}` : ''}`
}

export function formatMoney(amountMinor: number | undefined | null, currency: string, locale: 'ar' | 'en'): string {
  if (amountMinor === undefined || amountMinor === null) return '—'
  const value = amountMinor / 10 ** exponent(currency)
  try {
    return new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: exponent(currency),
      maximumFractionDigits: exponent(currency),
    }).format(value)
  } catch {
    return `${toDecimal(amountMinor, currency)} ${currency}`
  }
}

/** One run of a formatted amount: the currency symbol is isolated so its own script direction cannot reorder the digits. */
export type MoneySegment = { kind: 'text' | 'currency'; value: string }

export type MoneyParts = {
  /** True when the amount is below zero. */
  negative: boolean
  /** Locale-correct segments in display order (sign first), bidi marks removed. */
  segments: MoneySegment[]
  /** Plain-text value (same characters as `segments`), used for sizing and tooltips. */
  text: string
}

// Invisible direction marks Intl inserts around RTL currency output.
const BIDI_MARKS = /[\u200e\u200f\u061c\u202a-\u202e\u2066-\u2069]/g

/**
 * Same figures as {@link formatMoney} but split so the UI can render one
 * nowrap, left-to-right run with the minus sign first and the symbol isolated.
 */
export function formatMoneyParts(amountMinor: number | undefined | null, currency: string, locale: 'ar' | 'en'): MoneyParts {
  if (amountMinor === undefined || amountMinor === null) return { negative: false, segments: [{ kind: 'text', value: '—' }], text: '—' }
  const negative = amountMinor < 0
  const scale = exponent(currency)
  const segments: MoneySegment[] = []
  try {
    const parts = new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: scale,
      maximumFractionDigits: scale,
    }).formatToParts(amountMinor / 10 ** scale)
    let sign = ''
    for (const part of parts) {
      const value = part.value.replace(BIDI_MARKS, '')
      if (!value) continue
      if (part.type === 'minusSign') sign = '-'
      else if (part.type === 'currency') segments.push({ kind: 'currency', value })
      else {
        const last = segments[segments.length - 1]
        if (last && last.kind === 'text') last.value += value
        else segments.push({ kind: 'text', value })
      }
    }
    if (sign) {
      const first = segments[0]
      if (first && first.kind === 'text') first.value = sign + first.value
      else segments.unshift({ kind: 'text', value: sign })
    }
  } catch {
    segments.length = 0
    segments.push({ kind: 'text', value: `${toDecimal(amountMinor, currency)} ` }, { kind: 'currency', value: currency })
  }
  const text = segments.map((segment) => segment.value).join('').replace(/\s+/g, ' ').trim()
  return { negative, segments, text }
}

/**
 * Inline style that tells the money-fit CSS how many characters the value has,
 * so the font size can step down until the whole value fits its container.
 */
export function moneyFitStyle(text: string): Record<string, string | number> {
  return { '--money-chars': Math.max(text.length, 6) }
}

export function formatDate(value: string | undefined | null, locale: 'ar' | 'en'): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  }).format(date)
}

/** Unambiguous long date (e.g. "14 نوفمبر 2026") for an ISO `YYYY-MM-DD` value; timezone-safe. */
export function formatDateLong(value: string | undefined | null, locale: 'ar' | 'en'): string {
  if (!value) return ''
  const date = new Date(`${value.slice(0, 10)}T00:00:00Z`)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    timeZone: 'UTC',
  }).format(date)
}

export function formatDateTime(value: string | undefined | null, locale: 'ar' | 'en'): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

export function todayISO(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Strict amount check for user-typed money: digits with optional thousands
 * commas (groups of three) and optional decimals up to the currency exponent.
 * No sign, letters, spaces or exponent notation. Empty input is not valid
 * (callers decide whether a blank field is allowed).
 */
export function isValidAmount(value: string, currency: string): boolean {
  const text = value.trim()
  if (!text) return false
  const scale = exponent(currency)
  const decimals = scale === 0 ? '' : `(\\.\\d{1,${scale}})?`
  const pattern = new RegExp(`^(\\d+|\\d{1,3}(,\\d{3})+)${decimals}$`)
  return pattern.test(text)
}

/**
 * Parses a typed amount into integer minor units. Returns null for empty or
 * invalid input — never 0 for garbage, so a typo cannot silently post nothing.
 */
export function parseAmount(value: string, currency: string): number | null {
  if (!isValidAmount(value, currency)) return null
  const minor = toMinor(value, currency)
  return Number.isSafeInteger(minor) ? minor : null
}
