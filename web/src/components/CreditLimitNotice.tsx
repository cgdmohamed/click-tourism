import { useI18n } from '../i18n'
import { formatMoney } from '../lib/format'
import { Field, InlineNotice, Input } from './ui'

/** Additive `warnings[]` entry returned by booking transition / invoice issue. */
export type CreditWarning = {
  code: string
  overridden: boolean
  currency: string
  credit_limit_minor: number
  current_exposure_minor: number
  added_minor: number
  projected_exposure_minor: number
}

export const isCreditLimitError = (message: string) => message.startsWith('Credit limit exceeded')

/**
 * Shows credit-limit warnings from a successful action, and, when the action
 * was refused by the `block` policy, an override-reason field for users who
 * hold `bookings.override_credit_limit`.
 */
export default function CreditLimitNotice({
  warnings,
  blockedMessage,
  canOverride,
  overrideReason,
  onOverrideReasonChange,
}: {
  warnings: CreditWarning[]
  blockedMessage?: string
  canOverride: boolean
  overrideReason: string
  onOverrideReasonChange: (value: string) => void
}) {
  const { locale } = useI18n()
  const ar = locale === 'ar'
  const blocked = blockedMessage ? isCreditLimitError(blockedMessage) : false
  if (warnings.length === 0 && !(blocked && canOverride)) return null

  return (
    <div className="flex flex-col gap-3">
      {warnings.map((warning, index) => (
        <InlineNotice key={index} tone="warning">
          {ar
            ? `تجاوز العميل حد الائتمان${warning.overridden ? ' (تم التجاوز بإذن)' : ''}: الحد ${formatMoney(warning.credit_limit_minor, warning.currency, locale)}، التعرض بعد العملية ${formatMoney(warning.projected_exposure_minor, warning.currency, locale)}.`
            : `Customer credit limit exceeded${warning.overridden ? ' (overridden)' : ''}: limit ${formatMoney(warning.credit_limit_minor, warning.currency, locale)}, exposure after this ${formatMoney(warning.projected_exposure_minor, warning.currency, locale)}.`}
        </InlineNotice>
      ))}
      {blocked && canOverride ? (
        <Field
          label={ar ? 'سبب تجاوز حد الائتمان' : 'Credit limit override reason'}
          hint={ar ? 'أدخل السبب ثم أعد المحاولة؛ يُسجَّل التجاوز في سجل التدقيق.' : 'Enter a reason, then retry; the override is audited.'}
        >
          <Input value={overrideReason} maxLength={500} onChange={(event) => onOverrideReasonChange(event.target.value)} />
        </Field>
      ) : null}
    </div>
  )
}
