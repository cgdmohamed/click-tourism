export type BalanceSource = {
  opening: 'explicit' | 'statement' | 'statement_unverified' | 'line_balance' | 'previous_session'
  closing: 'explicit' | 'statement' | 'statement_unverified' | 'line_balance'
} | null

export const balanceSourceLabels: Record<string, { ar: string; en: string }> = {
  explicit: { ar: 'أُدخل يدويًا', en: 'Entered' },
  statement: { ar: 'من كشف البنك', en: 'From the statement' },
  statement_unverified: { ar: 'كشف قديم غير مؤكد', en: 'Legacy statement (unverified)' },
  line_balance: { ar: 'من عمود الرصيد', en: 'From the running balance' },
  previous_session: { ar: 'من ختام المطابقة المعتمدة السابقة', en: 'Previous approved reconciliation' },
}

/** Label of one side of a balance source; a missing source is shown as unconfirmed. */
export function balanceSourceLabel(source: BalanceSource | undefined, side: 'opening' | 'closing', locale: string): string {
  const key = source?.[side]
  const label = key ? balanceSourceLabels[key] : undefined
  if (!label) return locale === 'ar' ? 'غير مؤكد' : 'Unconfirmed'
  return locale === 'ar' ? label.ar : label.en
}

/** True when the balances were never confirmed (legacy rows, or an unverified legacy statement). */
export function balancesUnconfirmed(source: BalanceSource | undefined): boolean {
  return !source || source.opening === 'statement_unverified' || source.closing === 'statement_unverified'
}
