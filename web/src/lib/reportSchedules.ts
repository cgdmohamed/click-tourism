/** Pure helpers for the scheduled-report form (ADR-0026). */

export const PERIODS = ['previous_month', 'month_to_date', 'previous_week', 'yesterday', 'last_30_days', 'year_to_date'] as const
export type Period = (typeof PERIODS)[number]

export const PERIOD_LABEL: Record<Period, { ar: string; en: string }> = {
  previous_month: { ar: 'الشهر الماضي', en: 'Previous month' },
  month_to_date: { ar: 'من أول الشهر', en: 'Month to date' },
  previous_week: { ar: 'الأسبوع الماضي', en: 'Previous week' },
  yesterday: { ar: 'أمس', en: 'Yesterday' },
  last_30_days: { ar: 'آخر 30 يوماً', en: 'Last 30 days' },
  year_to_date: { ar: 'من أول السنة', en: 'Year to date' },
}

/** Reports that run without extra filters (the others, such as the ledger, need an account or party). */
export const SIMPLE_REPORTS = ['trial-balance', 'income-statement', 'balance-sheet', 'profitability', 'sales', 'commissions', 'tax'] as const

const EMAIL = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/

/** Splits a pasted list on commas, semicolons, spaces and new lines; lower-cases and removes duplicates. */
export function parseRecipients(raw: string): string[] {
  const seen = new Set<string>()
  for (const part of raw.split(/[\s,;]+/)) {
    const value = part.trim().toLowerCase()
    if (value) seen.add(value)
  }
  return [...seen]
}

/** Addresses that are not valid e-mail addresses, so the form can name them. */
export function invalidRecipients(list: string[]): string[] {
  return list.filter((value) => !EMAIL.test(value))
}

/** Day options of a frequency: weekly 1-7, monthly 1-28, none for daily. */
export function dayOptions(frequency: 'daily' | 'weekly' | 'monthly'): number[] {
  if (frequency === 'weekly') return [1, 2, 3, 4, 5, 6, 7]
  if (frequency === 'monthly') return Array.from({ length: 28 }, (_, index) => index + 1)
  return []
}
