import type { Locale } from '../i18n'
import { localizedName } from './names'

export type BeneficiaryType = 'employee' | 'agent' | 'branch'
export type CommissionBasis = 'percent_revenue' | 'percent_margin' | 'fixed'
export type RuleStatus = 'draft' | 'pending_approval' | 'approved' | 'rejected' | 'retired'
export type CommissionStatus = 'accrued' | 'approved' | 'posted' | 'paid' | 'rejected' | 'reversed' | 'cancelled'

export type BeneficiaryRef = { type: BeneficiaryType; id: string | null; name_ar: string | null; name_en: string | null }

export type CommissionRule = {
  id: string
  code: string
  version: number
  name_ar: string
  name_en: string
  status: RuleStatus
  beneficiary_type: BeneficiaryType
  beneficiary: BeneficiaryRef | null
  basis: CommissionBasis
  rate_bp: number | null
  fixed_minor: number | null
  branch_id: string | null
  service_type: string | null
  allow_cross_branch: boolean
  effective_from: string
  effective_to: string | null
  rejection_reason: string | null
}

export type Commission = {
  id: string
  number: string
  booking_id: string | null
  booking_number: string | null
  rule_code: string
  rule_version: number
  beneficiary_type: BeneficiaryType
  beneficiary: BeneficiaryRef | null
  basis: CommissionBasis
  rate_bp: number | null
  basis_amount_minor: number
  calculated_minor: number
  override_minor: number | null
  override_reason: string | null
  amount_minor: number
  paid_minor: number
  outstanding_minor: number
  currency: string
  status: CommissionStatus
  accrued_on: string
  needs_reversal: boolean
}

export type CommissionStatementLine = {
  date: string
  type: 'commission' | 'payment'
  commission: Commission
  amount_minor: number
}

export type CommissionStatement = {
  beneficiary_type: BeneficiaryType
  beneficiary: BeneficiaryRef | null
  from: string
  to: string
  currency: string
  summary: {
    pending_approval_minor: number
    approved_unposted_minor: number
    payable_minor: number
    paid_minor: number
    reversed_minor: number
    expected_minor: number
    adjusted_minor: number
  }
  lines: CommissionStatementLine[]
}

export type BeneficiaryRow = {
  beneficiary_type: BeneficiaryType
  beneficiary: BeneficiaryRef | null
  pending_approval_minor: number
  approved_unposted_minor: number
  payable_minor: number
  paid_minor: number
  expected_minor: number
  adjusted_minor: number
}

/**
 * Percent text ("12.5") to basis points (1250) with string maths only, so no
 * floating point ever touches a rate. Returns null for anything that is not a
 * percentage with at most two decimals between 0.01 and 100.
 */
export function percentToBp(input: string): number | null {
  const cleaned = input.trim().replace(/%$/, '').trim()
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(cleaned)) return null
  const [whole, fraction = ''] = cleaned.split('.')
  const bp = Number.parseInt(whole, 10) * 100 + Number.parseInt((fraction + '00').slice(0, 2), 10)
  return bp >= 1 && bp <= 10000 ? bp : null
}

/** Basis points (1250) to percent text ("12.5"), dropping trailing zeros. */
export function bpToPercent(bp: number | null | undefined): string {
  if (bp === null || bp === undefined) return ''
  const whole = Math.floor(bp / 100)
  const fraction = String(bp % 100).padStart(2, '0').replace(/0+$/, '')
  return fraction ? `${whole}.${fraction}` : String(whole)
}

type Label = { ar: string; en: string }

const ruleStatuses: Record<string, Label> = {
  draft: { ar: 'مسودة', en: 'Draft' },
  pending_approval: { ar: 'بانتظار الاعتماد', en: 'Pending approval' },
  approved: { ar: 'معتمدة', en: 'Approved' },
  rejected: { ar: 'مرفوضة', en: 'Rejected' },
  retired: { ar: 'موقوفة', en: 'Retired' },
}

const commissionStatuses: Record<string, Label> = {
  accrued: { ar: 'مستحقة', en: 'Accrued' },
  approved: { ar: 'معتمدة', en: 'Approved' },
  posted: { ar: 'مرحّلة', en: 'Posted' },
  paid: { ar: 'مدفوعة', en: 'Paid' },
  rejected: { ar: 'مرفوضة', en: 'Rejected' },
  reversed: { ar: 'معكوسة', en: 'Reversed' },
  cancelled: { ar: 'ملغاة', en: 'Cancelled' },
}

const beneficiaries: Record<BeneficiaryType, Label> = {
  employee: { ar: 'موظف', en: 'Employee' },
  agent: { ar: 'وكيل', en: 'Agent' },
  branch: { ar: 'فرع', en: 'Branch' },
}

const bases: Record<CommissionBasis, Label> = {
  percent_revenue: { ar: 'نسبة من الإيراد', en: '% of revenue' },
  percent_margin: { ar: 'نسبة من الهامش', en: '% of margin' },
  fixed: { ar: 'مبلغ ثابت', en: 'Fixed amount' },
}

const pick = (table: Record<string, Label>, value: string, locale: Locale) => table[value]?.[locale] ?? value

export const ruleStatusLabel = (value: string, locale: Locale) => pick(ruleStatuses, value, locale)
export const commissionStatusLabel = (value: string, locale: Locale) => pick(commissionStatuses, value, locale)
export const beneficiaryTypeLabel = (value: string, locale: Locale) => pick(beneficiaries, value, locale)
export const basisLabel = (value: string, locale: Locale) => pick(bases, value, locale)

export function beneficiaryName(ref: BeneficiaryRef | null | undefined, locale: Locale, fallback: string): string {
  if (!ref) return fallback
  return localizedName(ref, locale, fallback)
}
