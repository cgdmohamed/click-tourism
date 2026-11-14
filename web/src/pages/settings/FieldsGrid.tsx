import { useI18n } from '../../i18n'
import { Field, Input, Select } from '../../components/ui'
import { keyOf, type SettingField } from './fields'

/** Renders a group of editable settings (text, select, toggle) bound to the page-level draft. */
export function FieldsGrid({
  fields,
  draft,
  values,
  onChange,
  readOnly,
  selectOptions,
}: {
  fields: SettingField[]
  draft: Record<string, string>
  values: Record<string, string>
  onChange: (id: string, value: string) => void
  readOnly: boolean
  /** Options for selects whose list comes from data (e.g. general.base_currency). */
  selectOptions?: Record<string, string[]>
}) {
  const { locale } = useI18n()
  const ar = locale === 'ar'

  return (
    <div className="grid gap-4 px-5 py-5 sm:grid-cols-2">
      {fields.map((field) => {
        const id = keyOf(field.group, field.key)
        const label = ar ? field.ar : field.en

        if (field.type === 'toggle') {
          const checked = (draft[id] ?? 'false') === 'true'
          return (
            <Field key={id} label={label}>
              <label className="flex items-center gap-2 py-2 text-sm">
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={readOnly}
                  onChange={(event) => onChange(id, event.target.checked ? 'true' : 'false')}
                />
                {checked ? (ar ? 'مفعّل' : 'Enabled') : ar ? 'معطّل' : 'Disabled'}
              </label>
            </Field>
          )
        }

        if (field.type === 'select') {
          const locked = id === 'general.base_currency' && values['general.base_currency_locked'] === 'true'
          const options = selectOptions?.[id] ?? field.options ?? []
          return (
            <Field
              key={id}
              label={label}
              hint={
                locked
                  ? ar
                    ? 'مقفلة: لا يمكن تغيير العملة الأساسية بعد ترحيل أول قيد يومية.'
                    : 'Locked: the base currency cannot be changed after the first journal entry is posted.'
                  : undefined
              }
            >
              <Select value={draft[id] ?? ''} disabled={readOnly || locked} onChange={(event) => onChange(id, event.target.value)}>
                <option value="">—</option>
                {options.map((option) => (
                  <option key={option} value={option}>
                    {field.optionLabels?.[option]?.[ar ? 'ar' : 'en'] ?? option}
                  </option>
                ))}
              </Select>
            </Field>
          )
        }

        return (
          <Field key={id} label={label}>
            <Input
              value={draft[id] ?? ''}
              disabled={readOnly}
              onChange={(event) => onChange(id, event.target.value)}
              dir={field.key === 'company_name_ar' ? 'rtl' : 'ltr'}
              maxLength={field.key.startsWith('company_name') ? 120 : undefined}
            />
          </Field>
        )
      })}
    </div>
  )
}
