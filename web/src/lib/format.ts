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

export function exponent(currency: string): number {
  return CURRENCY_EXPONENT[currency.toUpperCase()] ?? 2
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
