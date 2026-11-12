import type { ImportJob, ImportJobStatus, ImportRowError } from './types'

type Locale = 'ar' | 'en'

/** Statuses after which the job will not change any more. */
export const FINAL_IMPORT_STATUSES: ImportJobStatus[] = ['completed', 'partial', 'failed']

export function isImportActive(status: ImportJobStatus): boolean {
  return status === 'queued' || status === 'running'
}

export function isImportFinal(status: ImportJobStatus): boolean {
  return FINAL_IMPORT_STATUSES.includes(status)
}

/** A previewed job can be confirmed when at least one row would be written. */
export function canConfirmImport(job: Pick<ImportJob, 'status' | 'created_count' | 'updated_count' | 'error_count' | 'on_error'>): boolean {
  if (job.status !== 'previewed') return false
  if (job.on_error === 'abort' && job.error_count > 0) return false
  return job.created_count + job.updated_count > 0
}

export type ImportTone = 'neutral' | 'positive' | 'warning' | 'negative' | 'brand'

export function importStatusTone(status: ImportJobStatus): ImportTone {
  switch (status) {
    case 'completed':
      return 'positive'
    case 'partial':
    case 'previewed':
      return 'warning'
    case 'failed':
      return 'negative'
    default:
      return 'brand'
  }
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Poll every 2s while the worker may still be running the job. */
export function importPollInterval(status: ImportJobStatus | undefined): number | false {
  return status && isImportActive(status) ? 2000 : false
}

const statusText: Record<Locale, Record<ImportJobStatus, string>> = {
  en: { previewed: 'Awaiting confirmation', queued: 'Queued', running: 'Running', completed: 'Completed', partial: 'Partially imported', failed: 'Failed' },
  ar: { previewed: 'بانتظار التأكيد', queued: 'في الطابور', running: 'قيد التنفيذ', completed: 'مكتمل', partial: 'مكتمل جزئياً', failed: 'فشل' },
}

export function importStatusLabel(status: ImportJobStatus, locale: Locale): string {
  return statusText[locale][status] ?? status
}

const actionText: Record<Locale, Record<string, string>> = {
  en: { create: 'Create', update: 'Update', skip: 'Skip', error: 'Error' },
  ar: { create: 'إنشاء', update: 'تحديث', skip: 'تخطي', error: 'خطأ' },
}

export function importActionLabel(action: string, locale: Locale): string {
  return actionText[locale][action] ?? action
}

const arErrors: Record<string, (p: Record<string, unknown>) => string> = {
  required: () => 'حقل مطلوب',
  too_long: (p) => `النص أطول من ${p.max} حرفاً`,
  invalid_email: () => 'بريد إلكتروني غير صالح',
  invalid_phone: () => 'رقم هاتف غير صالح',
  invalid_choice: (p) => `القيمة يجب أن تكون إحدى: ${p.allowed}`,
  invalid_boolean: () => 'يجب أن تكون القيمة نعم أو لا',
  invalid_currency: () => 'رمز عملة غير معروف',
  invalid_amount: () => 'مبلغ غير صالح',
  too_many_decimals: (p) => `عدد المنازل العشرية أكبر من ${p.max}`,
  invalid_integer: () => 'يجب أن يكون رقماً صحيحاً',
  too_small: (p) => `القيمة أقل من ${p.min}`,
  invalid_decimal: () => 'يجب أن يكون رقماً موجباً',
  invalid_date: () => 'تاريخ غير صالح (استخدم YYYY-MM-DD)',
  invalid_characters: () => 'يحتوي على رموز تحكم غير مسموحة',
  duplicate_in_file: (p) => `مكرر داخل الملف (أول ظهور في الصف ${p.first})`,
  duplicate_exists: () => 'يوجد سجل مطابق بالفعل',
  other_branch: () => 'يوجد سجل بنفس المفتاح في فرع آخر',
  formula_detected: () => 'الخلية تبدأ برمز صيغة (= + - @) وقد تنفذ كصيغة في الجداول',
  apply_failed: () => 'تعذر حفظ الصف',
  save_failed: () => 'تعذر حفظ السجل بسبب قيد في قاعدة البيانات',
  debit_or_credit: () => 'أدخل مديناً أو دائناً فقط وبقيمة أكبر من صفر',
  too_few_lines: () => 'الدفعة تحتاج سطرين على الأقل',
  invalid_type: () => 'نوع حساب غير صالح',
  duplicate_code: () => 'الكود مكرر داخل الملف',
  parent_not_found: () => 'الحساب الأب غير موجود',
  circular_parent: () => 'مرجع دائري في الحساب الأب',
  self_parent: () => 'الحساب لا يمكن أن يكون أباً لنفسه',
  invalid_classification: () => 'التصنيف لا يناسب نوع الحساب',
  account_has_history: () => 'لا يمكن تغيير حساب عليه حركات',
}

/** Localised text for one row error; falls back to the server's English message. */
export function importRowErrorText(error: ImportRowError, locale: Locale): string {
  if (locale === 'ar') {
    const build = arErrors[error.code]
    if (build) return build(error.params ?? {})
  }
  return error.message
}

const arFileErrors: Record<string, string> = {
  invalid_encoding: 'الملف ليس بترميز UTF-8. احفظه بصيغة "CSV UTF-8" أو ارفع ملف xlsx.',
  file_too_large: 'حجم الملف أكبر من الحد المسموح.',
  too_many_rows: 'عدد الصفوف أكبر من الحد المسموح.',
  too_many_columns: 'عدد الأعمدة أكبر من الحد المسموح.',
  cell_too_long: 'إحدى الخلايا أطول من الحد المسموح.',
  missing_columns: 'أعمدة مطلوبة غير موجودة في الملف.',
  duplicate_column: 'عمود مكرر في صف العناوين.',
  empty_file: 'الملف فارغ.',
  no_data_rows: 'الملف يحتوي العناوين فقط بدون بيانات.',
  unsupported_file_type: 'يُقبل فقط ملفات csv و xlsx.',
  unreadable_file: 'تعذرت قراءة الملف. أعد حفظه بصيغة xlsx أو csv.',
  unsupported_template_version: 'إصدار القالب غير مدعوم. نزّل القالب الحالي.',
  invalid_state: 'لا يمكن تنفيذ هذا الإجراء في الحالة الحالية.',
  forbidden: 'ليست لديك صلاحية لهذا الاستيراد.',
}

/** Localised message for a file-level API error code (falls back to the server text). */
export function importFileErrorText(code: string | undefined, fallback: string, locale: Locale): string {
  if (locale === 'ar' && code && arFileErrors[code]) return arFileErrors[code]
  return fallback
}
