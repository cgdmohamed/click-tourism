import type { Locale } from '../i18n'

type Label = { ar: string; en: string }

/**
 * Localised labels for party enums that the API returns as stable codes.
 * Keeping them in one place stops the same code from being translated
 * differently on the list, profile and invoice screens.
 */

export const supplierCategories: Array<Label & { value: string }> = [
  { value: 'airline', ar: 'شركة طيران', en: 'Airline' },
  { value: 'hotel_eg', ar: 'فندق داخل مصر', en: 'Hotel (Egypt)' },
  { value: 'hotel_sa', ar: 'فندق داخل السعودية', en: 'Hotel (Saudi)' },
  { value: 'transport', ar: 'شركة نقل', en: 'Transport' },
  { value: 'visa', ar: 'شركة تأشيرات', en: 'Visa' },
  { value: 'agent', ar: 'وكيل', en: 'Agent' },
  { value: 'other', ar: 'أخرى', en: 'Other' },
]

const employeeCategories: Record<string, Label> = {
  staff: { ar: 'موظف', en: 'Staff' },
  executive: { ar: 'إداري', en: 'Executive' },
  driver: { ar: 'سائق', en: 'Driver' },
  other: { ar: 'أخرى', en: 'Other' },
}

const supplierCategoryAliases: Record<string, Label> = {
  // Legacy/seed rows use the generic `hotel` code.
  hotel: { ar: 'فندق', en: 'Hotel' },
}

export function supplierCategoryLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  const found = supplierCategories.find((category) => category.value === value)
  if (found) return found[locale]
  return supplierCategoryAliases[value]?.[locale] ?? value
}

export function employeeCategoryLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  return employeeCategories[value]?.[locale] ?? value
}

export function customerTypeLabel(value: string | null | undefined, locale: Locale): string {
  if (value === 'company') return locale === 'ar' ? 'شركة' : 'Company'
  if (value === 'individual') return locale === 'ar' ? 'فرد' : 'Individual'
  return value ?? '—'
}

const statuses: Record<string, Label> = {
  draft: { ar: 'مسودة', en: 'Draft' },
  quoted: { ar: 'عرض سعر', en: 'Quoted' },
  confirmed: { ar: 'مؤكد', en: 'Confirmed' },
  in_progress: { ar: 'قيد التنفيذ', en: 'In progress' },
  completed: { ar: 'مكتمل', en: 'Completed' },
  closed: { ar: 'مغلق', en: 'Closed' },
  cancelled: { ar: 'ملغي', en: 'Cancelled' },
  no_show: { ar: 'لم يحضر', en: 'No show' },
  submitted: { ar: 'مُقدم', en: 'Submitted' },
  approved: { ar: 'معتمد', en: 'Approved' },
  issued: { ar: 'صادر', en: 'Issued' },
  voided: { ar: 'ملغي', en: 'Voided' },
  open: { ar: 'مفتوح', en: 'Open' },
  partially_paid: { ar: 'مدفوع جزئياً', en: 'Partially paid' },
  paid: { ar: 'مدفوع', en: 'Paid' },
  posted: { ar: 'مُرحّل', en: 'Posted' },
  requested: { ar: 'مطلوب', en: 'Requested' },
  disbursed: { ar: 'مصروف', en: 'Disbursed' },
  settled: { ar: 'مُسدد', en: 'Settled' },
  cleared: { ar: 'مُسوّى', en: 'Cleared' },
  returned: { ar: 'مُعاد', en: 'Returned' },
}

export function statusLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  return statuses[value]?.[locale] ?? value
}

const paymentMethods: Record<string, Label> = {
  cash: { ar: 'نقداً', en: 'Cash' },
  bank_transfer: { ar: 'تحويل بنكي', en: 'Bank transfer' },
  card: { ar: 'بطاقة', en: 'Card' },
  wallet: { ar: 'محفظة', en: 'Wallet' },
}

export function paymentMethodLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  return paymentMethods[value]?.[locale] ?? value
}

const employeeTransactionTypes: Record<string, Label> = {
  advance_request: { ar: 'طلب سلفة', en: 'Advance request' },
  advance_disbursed: { ar: 'صرف سلفة', en: 'Advance disbursed' },
  advance_settled: { ar: 'تسوية سلفة', en: 'Advance settled' },
  custody_issued: { ar: 'صرف عهدة', en: 'Custody issued' },
  custody_cleared: { ar: 'تسوية عهدة', en: 'Custody cleared' },
  custody_returned: { ar: 'إرجاع عهدة', en: 'Custody returned' },
  commission_paid: { ar: 'عمولة مدفوعة', en: 'Commission paid' },
  adjustment: { ar: 'تسوية يدوية', en: 'Adjustment' },
}

export function employeeTransactionLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  return employeeTransactionTypes[value]?.[locale] ?? value
}

const statementSourceTypes: Record<string, Label> = {
  invoice: { ar: 'فاتورة', en: 'Invoice' },
  payment: { ar: 'سند قبض', en: 'Receipt' },
  refund: { ar: 'مرتجع', en: 'Refund' },
  supplier_payment: { ar: 'مدفوع مورد', en: 'Supplier payment' },
  expense: { ar: 'مصروف', en: 'Expense' },
  accounting_document: { ar: 'سند محاسبي', en: 'Voucher' },
  accounting_schedule: { ar: 'قيد مجدول', en: 'Schedule' },
  booking: { ar: 'حجز', en: 'Booking' },
  transfer: { ar: 'تحويل', en: 'Transfer' },
  fixed_asset: { ar: 'أصل ثابت', en: 'Fixed asset' },
  fixed_asset_depreciation_run: { ar: 'إهلاك', en: 'Depreciation' },
  manual: { ar: 'قيد يدوي', en: 'Manual entry' },
}

export function statementSourceLabel(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'
  return statementSourceTypes[value]?.[locale] ?? value.replace(/_/g, ' ')
}

