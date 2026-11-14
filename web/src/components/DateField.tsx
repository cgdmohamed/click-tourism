import { useId } from 'react'
import type { InputHTMLAttributes } from 'react'
import { useI18n } from '../i18n'
import { formatDateLong } from '../lib/format'
import { Input } from './ui'

/**
 * Shared date input. Keeps the native `type="date"` (accessible, mobile pickers)
 * and value format (`YYYY-MM-DD`), and shows the chosen date in the app locale
 * underneath so the format is never ambiguous (the native placeholder follows the
 * browser locale, e.g. mm/dd/yyyy inside an Arabic UI).
 */
export function DateField({
  value,
  className,
  ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const { locale } = useI18n()
  const previewId = useId()
  const text = formatDateLong(typeof value === 'string' ? value : undefined, locale)
  return (
    <div className="flex min-w-0 flex-col">
      <Input
        lang={locale}
        {...props}
        type="date"
        dir="ltr"
        value={value}
        className={className}
        aria-describedby={text ? previewId : props['aria-describedby']}
      />
      <span id={previewId} className="text-small mt-1 min-h-4 text-text-subtle" dir="auto" aria-live="polite">
        {text}
      </span>
    </div>
  )
}
