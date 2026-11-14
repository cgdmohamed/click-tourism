export type Setting = { group: string; key: string; value: string | null; is_secret: boolean }

export type SettingField = {
  group: string
  key: string
  ar: string
  en: string
  type?: 'text' | 'select' | 'toggle'
  options?: string[]
  /** Human labels for stored option values (the stored value is never changed). */
  optionLabels?: Record<string, { ar: string; en: string }>
}

/** Editable installation settings. The report logo is uploaded through POST /settings/logo, not edited as text. */
export const editable: SettingField[] = [
  { group: 'general', key: 'company_name_ar', ar: 'اسم الشركة (عربي)', en: 'Company name (Arabic)' },
  { group: 'general', key: 'company_name_en', ar: 'اسم الشركة (إنجليزي)', en: 'Company name (English)' },
  { group: 'general', key: 'company_address', ar: 'عنوان الشركة (يظهر على الفاتورة)', en: 'Company address (printed on invoices)' },
  { group: 'general', key: 'company_phone', ar: 'هاتف الشركة', en: 'Company phone' },
  { group: 'general', key: 'company_tax_id', ar: 'الرقم الضريبي للشركة', en: 'Company tax number' },
  { group: 'general', key: 'company_commercial_register', ar: 'رقم السجل التجاري', en: 'Commercial register number' },
  { group: 'general', key: 'locale', ar: 'اللغة الافتراضية', en: 'Default language', type: 'select', options: ['ar', 'en'], optionLabels: { ar: { ar: 'العربية', en: 'العربية' }, en: { ar: 'English', en: 'English' } } },
  { group: 'general', key: 'base_currency', ar: 'العملة الأساسية', en: 'Base currency', type: 'select' },
  { group: 'general', key: 'report_signoff', ar: 'سطور التوقيع في تقارير PDF', en: 'Sign-off lines on PDF reports', type: 'toggle', options: ['true', 'false'] },
  { group: 'accounting', key: 'fiscal_year_start', ar: 'بداية السنة المالية (MM-DD)', en: 'Fiscal year start (MM-DD)' },
  { group: 'accounting', key: 'rounding_policy', ar: 'سياسة التقريب', en: 'Rounding policy', type: 'select', options: ['half_up', 'bankers'], optionLabels: { half_up: { ar: 'تقريب للأعلى عند المنتصف', en: 'Round half up' }, bankers: { ar: 'تقريب مصرفي (للأقرب زوجي)', en: "Banker's rounding (half to even)" } } },
  { group: 'accounting', key: 'credit_limit_policy', ar: 'سياسة حد الائتمان', en: 'Credit limit policy', type: 'select', options: ['off', 'warn', 'block'], optionLabels: { off: { ar: 'إيقاف', en: 'Off' }, warn: { ar: 'تحذير فقط', en: 'Warn only' }, block: { ar: 'منع التجاوز', en: 'Block overrun' } } },
  { group: 'tax', key: 'enabled', ar: 'تفعيل الضريبة', en: 'Enable tax', type: 'toggle', options: ['true', 'false'] },
  { group: 'tax', key: 'default_rate', ar: 'نسبة الضريبة (%)', en: 'Tax rate (%)' },
  { group: 'tax', key: 'inclusive', ar: 'الأسعار شاملة الضريبة', en: 'Prices include tax', type: 'toggle', options: ['true', 'false'] },
  { group: 'crm', key: 'reassign_after_hours', ar: 'إعادة توزيع العميل المحتمل بعد (ساعات)', en: 'Lead reassignment after (hours)' },
  { group: 'crm', key: 'duplicate_window_days', ar: 'نافذة اكتشاف تكرار العميل المحتمل (أيام، 0 لتعطيلها)', en: 'Lead duplicate window (days, 0 disables)' },
]

export const keyOf = (group: string, key: string) => `${group}.${key}`

/** Changed fields only: what the Save button sends. */
export function pendingChanges(
  draft: Record<string, string>,
  values: Record<string, string>,
  fields: SettingField[] = editable,
): Array<{ group: string; key: string; value: string }> {
  return fields
    .map((field) => ({ group: field.group, key: field.key, value: draft[keyOf(field.group, field.key)] ?? '' }))
    .filter((setting) => setting.value !== (values[keyOf(setting.group, setting.key)] ?? ''))
}

/** Settings tabs in display order, with the settings groups each one edits. */
export const SETTINGS_TABS = ['general', 'currencies', 'accounting', 'tax', 'crm', 'branches', 'integrations', 'assistant', 'mcp', 'decisions'] as const
export type SettingsTab = (typeof SETTINGS_TABS)[number]

/** Tabs that edit settings key/values through the shared draft and Save button. */
export const FORM_TABS: readonly SettingsTab[] = ['general', 'accounting', 'tax', 'crm']
