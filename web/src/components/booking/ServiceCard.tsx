import { useI18n } from '../../i18n'
import { Button, Field, Input, Select, cx } from '../ui'
import { Icon } from '../icons'
import PartyPicker from '../PartyPicker'
import { Amount } from './Amount'
import { SERVICE_TYPES, type DraftItem, type ItemAmounts } from './types'
import type { ItemErrors } from './validation'

export function serviceLabel(value: string, ar: boolean): string {
  const labels: Record<string, [string, string]> = {
    flight: ['طيران', 'Flight'],
    hotel: ['فندق', 'Hotel'],
    transport: ['نقل', 'Transport'],
    visa: ['تأشيرة', 'Visa'],
    program: ['برنامج سياحي', 'Program'],
    other: ['أخرى', 'Other'],
  }
  const found = labels[value]
  return found ? found[ar ? 0 : 1] : value
}

/**
 * One service of the booking. Stable layout at every width: identity row (type, supplier,
 * description), then the money row (quantity, unit cost, unit price) and the computed result.
 * Desktop and tablet use one 12-column grid; the phone stacks the same fields in the same order.
 */
export function ServiceCard({
  item,
  index,
  amounts,
  errors,
  showErrors,
  taxEnabled,
  canRemove,
  onChange,
  onDuplicate,
  onRemove,
}: {
  item: DraftItem
  index: number
  amounts: ItemAmounts
  errors: ItemErrors
  showErrors: boolean
  taxEnabled: boolean
  canRemove: boolean
  onChange: (patch: Partial<DraftItem>) => void
  onDuplicate: () => void
  onRemove: () => void
}) {
  const { t, locale } = useI18n()
  const ar = locale === 'ar'
  const err = (key: keyof ItemErrors) => (showErrors ? errors[key] : undefined)
  const margin = amounts.sell - amounts.cost
  const negative = margin < 0
  const id = (name: string) => `bf-item-${item.uid}-${name}`
  const title = `${ar ? 'الخدمة' : 'Service'} ${index + 1} · ${serviceLabel(item.service_type, ar)}`

  return (
    <li
      aria-label={title}
      className={cx('min-w-0 rounded-12 border bg-surface-default p-4', negative ? 'border-warning-border' : 'border-border-default')}
    >
      <div className="mb-3 flex items-center justify-between gap-2">
        <h4 className="text-medium min-w-0 truncate font-semibold">{title}</h4>
        <div className="flex shrink-0 items-center gap-1">
          <Button variant="ghost" icon="copy" onClick={onDuplicate} title={ar ? 'نسخ هذه الخدمة' : 'Duplicate this service'}>
            {ar ? 'نسخ' : 'Duplicate'}
          </Button>
          <Button variant="ghost" icon="trash" disabled={!canRemove} onClick={onRemove} aria-label={`${t('removeLine')} ${index + 1}`} title={t('removeLine')}>
            <span className="max-sm:sr-only">{ar ? 'حذف' : 'Remove'}</span>
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-12">
        <div className="col-span-2 min-w-0 sm:col-span-3">
          <Field label={t('service')}>
            <Select id={id('type')} value={item.service_type} onChange={(event) => onChange({ service_type: event.target.value })}>
              {SERVICE_TYPES.map((service) => (
                <option key={service} value={service}>{serviceLabel(service, ar)}</option>
              ))}
            </Select>
          </Field>
        </div>
        <div className="col-span-2 min-w-0 sm:col-span-4">
          <Field label={t('supplier')}>
            <PartyPicker kind="supplier" value={item.supplier_id} onChange={(supplier_id) => onChange({ supplier_id })} placeholder={t('supplier')} clearLabel={t('none')} />
          </Field>
        </div>
        <div className="col-span-2 min-w-0 sm:col-span-5">
          <Field label={t('description')} required error={err('description')}>
            <Input id={id('description')} value={item.description} maxLength={255} aria-invalid={Boolean(err('description'))} onChange={(event) => onChange({ description: event.target.value })} />
          </Field>
        </div>

        <div className="col-span-1 min-w-0 sm:col-span-2">
          <Field label={t('quantity')} required error={err('quantity')}>
            <Input id={id('quantity')} value={item.quantity} aria-invalid={Boolean(err('quantity'))} onChange={(event) => onChange({ quantity: event.target.value })} inputMode="numeric" dir="ltr" />
          </Field>
        </div>
        <div className="col-span-1 min-w-0 sm:col-span-3">
          <Field label={`${t('unitCost')} (${item.cost_currency})`} required error={err('unit_cost')}>
            <Input id={id('unit-cost')} value={item.unit_cost} aria-invalid={Boolean(err('unit_cost'))} onChange={(event) => onChange({ unit_cost: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
        </div>
        <div className="col-span-2 min-w-0 sm:col-span-3">
          <Field label={`${t('unitPrice')} (${item.sell_currency})`} required error={err('unit_sell')}>
            <Input id={id('unit-sell')} value={item.unit_sell} aria-invalid={Boolean(err('unit_sell'))} onChange={(event) => onChange({ unit_sell: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
        </div>
        <dl className="col-span-2 flex min-w-0 flex-col justify-end gap-1 rounded-12 bg-surface-subtle px-3 py-2 sm:col-span-4">
          <div className="flex min-w-0 items-baseline justify-between gap-3">
            <dt className="text-small shrink-0 text-text-subtle">{t('total')}</dt>
            <dd className="min-w-0 flex-1"><Amount minor={amounts.sell} currency={item.sell_currency} align="end" /></dd>
          </div>
          <div className="flex min-w-0 items-baseline justify-between gap-3">
            <dt className="text-small shrink-0 text-text-subtle">{t('margin')}</dt>
            <dd className={cx('min-w-0 flex-1', negative && 'text-warning-text')}><Amount minor={margin} currency={item.sell_currency} align="end" /></dd>
          </div>
          {taxEnabled ? (
            <div className="flex min-w-0 items-baseline justify-between gap-3">
              <dt className="text-small shrink-0 text-text-subtle">{t('tax')}</dt>
              <dd className="min-w-0 flex-1"><Amount minor={amounts.tax} currency={item.sell_currency} align="end" /></dd>
            </div>
          ) : null}
        </dl>
      </div>

      {negative ? (
        <p className="text-small mt-3 flex items-start gap-2 rounded-12 border border-warning-border bg-warning-background px-3 py-2 text-warning-text">
          <Icon name="alertTriangle" className="mt-0.5 h-4 w-4" />
          <span>{ar ? 'هامش سالب في هذه الخدمة: سعر البيع أقل من التكلفة (تنبيه فقط، يمكن الحفظ).' : 'Negative margin on this service: the price is below the cost (warning only, you can still save).'}</span>
        </p>
      ) : null}
      {!taxEnabled ? <p className="text-small mt-2 text-text-subtle">{ar ? 'الضريبة معطلة في الإعدادات.' : 'Tax is disabled in settings.'}</p> : null}
    </li>
  )
}
