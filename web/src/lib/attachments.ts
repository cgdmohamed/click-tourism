/** Document attachments (ADR-0021): categories, extractable fields and small helpers. */

export type Locale = 'ar' | 'en'
export type AttachableType = 'customer' | 'supplier' | 'booking' | 'invoice' | 'payment' | 'expense'

export type AttachmentRow = {
  id: string
  attachable_type: AttachableType
  category: string
  sensitive: boolean
  name: string
  mime: string
  size_bytes: number
  notes: string | null
  uploaded_by: string | null
  created_at: string | null
  duplicate?: boolean
}

export type ExtractionField = { value: string | null; flag: 'missing' | 'unclear' | null }
export type Extraction = {
  id: string
  attachment_id: string
  status: 'succeeded' | 'failed' | 'confirmed' | 'rejected'
  category: string
  proposed: { fields: Record<string, ExtractionField> } | null
  confirmed: Record<string, string | null> | null
  error_code: string | null
  reused?: boolean
}

export const CATEGORIES: { value: string; ar: string; en: string }[] = [
  { value: 'passport', ar: 'جواز سفر', en: 'Passport' },
  { value: 'visa', ar: 'تأشيرة', en: 'Visa' },
  { value: 'booking_confirmation', ar: 'تأكيد حجز', en: 'Booking confirmation' },
  { value: 'supplier_invoice', ar: 'فاتورة مورد', en: 'Supplier invoice' },
  { value: 'payment_receipt', ar: 'إيصال دفع', en: 'Payment receipt' },
  { value: 'bank_transfer', ar: 'إشعار تحويل بنكي', en: 'Bank-transfer confirmation' },
  { value: 'travel_document', ar: 'مستند سفر', en: 'Travel document' },
  { value: 'contract', ar: 'عقد', en: 'Contract' },
  { value: 'other', ar: 'أخرى', en: 'Other' },
]

/** Fields the server may propose for each category (mirrors ExtractionSchema). */
export const FIELDS: Record<string, { key: string; ar: string; en: string }[]> = {
  supplier_invoice: [
    { key: 'supplier_name', ar: 'اسم المورد', en: 'Supplier' },
    { key: 'invoice_number', ar: 'رقم الفاتورة', en: 'Invoice number' },
    { key: 'invoice_date', ar: 'تاريخ الفاتورة (YYYY-MM-DD)', en: 'Invoice date (YYYY-MM-DD)' },
    { key: 'due_date', ar: 'تاريخ الاستحقاق (YYYY-MM-DD)', en: 'Due date (YYYY-MM-DD)' },
    { key: 'currency', ar: 'العملة', en: 'Currency' },
    { key: 'subtotal', ar: 'الإجمالي قبل الضريبة', en: 'Subtotal' },
    { key: 'tax', ar: 'الضريبة', en: 'Tax' },
    { key: 'total', ar: 'الإجمالي', en: 'Total' },
    { key: 'booking_reference', ar: 'مرجع الحجز', en: 'Booking reference' },
    { key: 'item_description', ar: 'وصف البند', en: 'Item description' },
  ],
  payment_receipt: [
    { key: 'payer_name', ar: 'الدافع', en: 'Payer' },
    { key: 'payee_name', ar: 'المستفيد', en: 'Payee' },
    { key: 'amount', ar: 'المبلغ', en: 'Amount' },
    { key: 'currency', ar: 'العملة', en: 'Currency' },
    { key: 'payment_date', ar: 'تاريخ الدفع (YYYY-MM-DD)', en: 'Payment date (YYYY-MM-DD)' },
    { key: 'reference', ar: 'المرجع', en: 'Reference' },
    { key: 'bank_name', ar: 'البنك', en: 'Bank' },
  ],
  passport: [
    { key: 'full_name', ar: 'الاسم الكامل', en: 'Full name' },
    { key: 'passport_number', ar: 'رقم الجواز', en: 'Passport number' },
    { key: 'nationality', ar: 'الجنسية', en: 'Nationality' },
    { key: 'date_of_birth', ar: 'تاريخ الميلاد (YYYY-MM-DD)', en: 'Date of birth (YYYY-MM-DD)' },
    { key: 'expiry_date', ar: 'تاريخ الانتهاء (YYYY-MM-DD)', en: 'Expiry date (YYYY-MM-DD)' },
  ],
  visa: [
    { key: 'full_name', ar: 'الاسم الكامل', en: 'Full name' },
    { key: 'document_number', ar: 'رقم التأشيرة', en: 'Visa number' },
    { key: 'valid_from', ar: 'صالحة من (YYYY-MM-DD)', en: 'Valid from (YYYY-MM-DD)' },
    { key: 'valid_until', ar: 'صالحة حتى (YYYY-MM-DD)', en: 'Valid until (YYYY-MM-DD)' },
    { key: 'destination', ar: 'الوجهة', en: 'Destination' },
  ],
  travel_document: [
    { key: 'traveler_name', ar: 'اسم المسافر', en: 'Traveler' },
    { key: 'booking_reference', ar: 'مرجع الحجز', en: 'Booking reference' },
    { key: 'departure_date', ar: 'تاريخ المغادرة (YYYY-MM-DD)', en: 'Departure (YYYY-MM-DD)' },
    { key: 'return_date', ar: 'تاريخ العودة (YYYY-MM-DD)', en: 'Return (YYYY-MM-DD)' },
  ],
  booking_confirmation: [
    { key: 'traveler_name', ar: 'اسم المسافر', en: 'Traveler' },
    { key: 'booking_reference', ar: 'مرجع الحجز', en: 'Booking reference' },
    { key: 'supplier_name', ar: 'المورد', en: 'Supplier' },
    { key: 'departure_date', ar: 'تاريخ المغادرة (YYYY-MM-DD)', en: 'Departure (YYYY-MM-DD)' },
    { key: 'return_date', ar: 'تاريخ العودة (YYYY-MM-DD)', en: 'Return (YYYY-MM-DD)' },
    { key: 'total', ar: 'الإجمالي', en: 'Total' },
    { key: 'currency', ar: 'العملة', en: 'Currency' },
  ],
  contract: [
    { key: 'counterparty', ar: 'الطرف الآخر', en: 'Counterparty' },
    { key: 'contract_date', ar: 'تاريخ العقد (YYYY-MM-DD)', en: 'Contract date (YYYY-MM-DD)' },
    { key: 'end_date', ar: 'تاريخ الانتهاء (YYYY-MM-DD)', en: 'End date (YYYY-MM-DD)' },
    { key: 'total_amount', ar: 'قيمة العقد', en: 'Contract value' },
    { key: 'currency', ar: 'العملة', en: 'Currency' },
  ],
}
FIELDS.bank_transfer = FIELDS.payment_receipt

export function categoryLabel(value: string, locale: Locale): string {
  const row = CATEGORIES.find((entry) => entry.value === value)
  return row ? row[locale] : value
}

export function canExtract(category: string): boolean {
  return category in FIELDS
}

export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Values the reviewer starts from: what the server proposed, flagged fields included. */
export function initialValues(extraction: Extraction): Record<string, string> {
  const values: Record<string, string> = {}
  for (const field of FIELDS[extraction.category] ?? []) {
    values[field.key] = extraction.proposed?.fields?.[field.key]?.value ?? ''
  }
  return values
}

/** The reviewed values as the API expects them: empty text becomes null. */
export function toConfirmPayload(values: Record<string, string>): Record<string, string | null> {
  const out: Record<string, string | null> = {}
  for (const [key, value] of Object.entries(values)) out[key] = value.trim() === '' ? null : value.trim()
  return out
}

/** Router state that opens the invoice form pre-filled from a confirmed supplier-invoice extraction. */
export type InvoicePrefill = {
  issue_date?: string
  due_date?: string
  currency?: string
  memo?: string
  description?: string
  amount?: string
}

export function invoicePrefill(values: Record<string, string | null>): InvoicePrefill {
  const memo = [values.supplier_name, values.invoice_number].filter(Boolean).join(' · ')
  return {
    issue_date: values.invoice_date ?? undefined,
    due_date: values.due_date ?? undefined,
    currency: values.currency ?? undefined,
    memo: memo || undefined,
    description: values.item_description ?? values.invoice_number ?? undefined,
    amount: values.subtotal ?? values.total ?? undefined,
  }
}
