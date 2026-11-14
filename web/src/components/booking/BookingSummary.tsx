import type { ReactNode } from 'react'
import { useI18n } from '../../i18n'
import { Badge, cx } from '../ui'
import { Icon } from '../icons'
import { Amount } from './Amount'
import { focusField, type MissingEntry } from './validation'

/**
 * Always-visible footer of the booking form: totals in the booking currency, the negative-margin
 * warning, what is still missing to save (each entry jumps to its field) and the form actions.
 */
export function BookingSummary({
  currency,
  sell,
  tax,
  margin,
  taxEnabled,
  missing,
  error,
  actions,
}: {
  currency: string
  sell: number
  tax: number
  margin: number
  taxEnabled: boolean
  missing: MissingEntry[]
  error: string
  actions: ReactNode
}) {
  const { t, locale } = useI18n()
  const ar = locale === 'ar'
  const negative = margin < 0
  const cells: Array<{ label: string; minor: number; warn?: boolean }> = [
    { label: t('total'), minor: sell },
    ...(taxEnabled ? [{ label: t('tax'), minor: tax }] : []),
    { label: t('margin'), minor: margin, warn: negative },
  ]

  return (
    <div role="region" aria-label={ar ? 'ملخص الحجز' : 'Booking summary'} className="flex flex-col gap-3">
      <div className="flex min-w-0 items-start gap-4">
        <dl className={cx('flex min-w-0 flex-1 flex-col gap-1 sm:grid sm:gap-x-4', taxEnabled ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
          {cells.map((cell) => (
            <div key={cell.label} className="flex min-w-0 items-baseline justify-between gap-3 sm:block">
              <dt className="text-small shrink-0 text-text-subtle">{cell.label}</dt>
              <dd className={cx('min-w-0 max-sm:flex-1', cell.warn && 'text-warning-text')}>
                <Amount minor={cell.minor} currency={currency} size="lg" align="start" className="max-sm:ltr:!text-right max-sm:rtl:!text-left" />
              </dd>
            </div>
          ))}
        </dl>
        <div className="shrink-0 text-end">
          <p className="text-small text-text-subtle">{t('currency')}</p>
          <Badge tone="neutral">{currency}</Badge>
        </div>
      </div>

      {negative ? (
        <p role="alert" className="text-small flex items-start gap-2 text-warning-text">
          <Icon name="alertTriangle" className="mt-0.5 h-4 w-4" />
          <span>{ar ? 'تنبيه: الهامش سالب (سعر البيع أقل من التكلفة). يمكن الحفظ.' : 'Warning: negative margin (selling below cost). You can still save.'}</span>
        </p>
      ) : null}

      {error ? (
        <p role="alert" className="text-medium rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-danger-text">{error}</p>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div className="text-small min-w-0 flex-1 basis-60">
          {missing.length > 0 ? (
            <p className="flex flex-wrap items-center gap-x-1 gap-y-1 text-text-subtle" aria-live="polite">
              <span className="font-semibold text-text-default">{ar ? 'لإتمام الحفظ:' : 'Still needed to save:'}</span>
              {missing.slice(0, 4).map((entry, index) => (
                <span key={entry.key}>
                  <button type="button" className="text-brand-1-accent underline underline-offset-2" onClick={() => focusField(entry.target)}>{entry.label}</button>
                  {index < Math.min(missing.length, 4) - 1 ? (ar ? '،' : ',') : ''}
                </span>
              ))}
              {missing.length > 4 ? <span>{ar ? `و${missing.length - 4} أخرى` : `and ${missing.length - 4} more`}</span> : null}
            </p>
          ) : (
            <p className="flex items-center gap-1 text-success-text" aria-live="polite">
              <Icon name="checkCircle" className="h-4 w-4" />
              {ar ? 'كل البيانات المطلوبة مكتملة.' : 'All required details are complete.'}
            </p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2">{actions}</div>
      </div>
    </div>
  )
}
