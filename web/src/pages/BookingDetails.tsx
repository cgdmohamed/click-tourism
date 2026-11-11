import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { exponent, formatDate, formatDateTime, formatMoney, toMinor } from '../lib/format'
import { statusLabel } from '../lib/labels'
import type { Booking, BookingItem } from '../lib/types'
import CreditLimitNotice, { type CreditWarning } from '../components/CreditLimitNotice'
import {
  Badge,
  Button,
  Field,
  InlineNotice,
  Input,
  Loading,
  Modal,
  Table,
  Td,
  Textarea,
  Th,
  ErrorState,
  statusTone,
} from '../components/ui'

const CANCELLABLE = ['draft', 'quoted', 'confirmed', 'in_progress', 'completed']

function serviceLabel(value: string, locale: 'ar' | 'en') {
  const labels: Record<string, [string, string]> = {
    flight: ['طيران', 'Flight'],
    hotel: ['فندق', 'Hotel'],
    transport: ['نقل', 'Transport'],
    visa: ['تأشيرة', 'Visa'],
    program: ['برنامج سياحي', 'Program'],
    other: ['أخرى', 'Other'],
  }
  const found = labels[value]
  return found ? found[locale === 'ar' ? 0 : 1] : value
}

const transitionLabels: Record<string, [string, string]> = {
  quoted: ['عرض سعر', 'Mark as quoted'],
  confirmed: ['تأكيد', 'Confirm'],
  in_progress: ['بدء التنفيذ', 'Start'],
  completed: ['إكمال', 'Complete'],
  closed: ['إغلاق', 'Close'],
  no_show: ['لم يحضر', 'Mark no-show'],
}

type Dialog = 'none' | 'cancel' | 'margin'

/** Parses a non-negative decimal amount in the booking currency; returns null when invalid. */
function parseFee(value: string, currency: string): number | null {
  const cleaned = value.replace(/,/g, '').trim()
  if (cleaned === '') return 0
  const pattern = new RegExp(`^\\d+(\\.\\d{1,${exponent(currency)}})?$`)
  if (!pattern.test(cleaned)) return null
  return toMinor(cleaned, currency)
}

export default function BookingDetails({ bookingId, onClose }: { bookingId: string | null; onClose: () => void }) {
  const { locale } = useI18n()
  const { can, user } = useAuth()
  const queryClient = useQueryClient()
  const [dialog, setDialog] = useState<Dialog>('none')
  const [error, setError] = useState('')
  const [reason, setReason] = useState('')
  const [fee, setFee] = useState('')
  const [dialogError, setDialogError] = useState('')
  const [overrideReason, setOverrideReason] = useState('')
  const [creditWarnings, setCreditWarnings] = useState<CreditWarning[]>([])
  const ar = locale === 'ar'

  const query = useQuery({
    queryKey: ['booking', bookingId],
    enabled: Boolean(bookingId),
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking }>(`/bookings/${bookingId}`)
      return data.data
    },
  })
  const booking = query.data

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['bookings'] })
    await queryClient.invalidateQueries({ queryKey: ['booking', bookingId] })
  }

  const closeDialog = () => {
    setDialog('none')
    setReason('')
    setFee('')
    setDialogError('')
  }

  const close = () => {
    closeDialog()
    setError('')
    setCreditWarnings([])
    setOverrideReason('')
    onClose()
  }

  const transition = useMutation({
    mutationFn: async (status: string) => {
      const override = overrideReason.trim()
      const { data } = await api.post<{ data: { warnings?: CreditWarning[] } }>(`/bookings/${bookingId}/transition`, {
        status,
        ...(override && can('bookings.override_credit_limit') ? { override_reason: override } : {}),
      })
      return data.data.warnings ?? []
    },
    onSuccess: async (warnings) => {
      setError('')
      setOverrideReason('')
      setCreditWarnings(warnings)
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const cancel = useMutation({
    mutationFn: async () => {
      if (!booking) return
      if (reason.trim() === '') throw new Error(ar ? 'سبب الإلغاء مطلوب.' : 'A cancellation reason is required.')
      const feeMinor = parseFee(fee, booking.currency)
      if (feeMinor === null) throw new Error(ar ? 'رسوم الإلغاء يجب أن تكون مبلغاً صحيحاً غير سالب.' : 'The cancellation fee must be a valid non-negative amount.')
      await api.post(`/bookings/${booking.id}/cancel`, { reason: reason.trim(), cancellation_fee_minor: feeMinor })
    },
    onSuccess: async () => {
      closeDialog()
      setError('')
      await refresh()
    },
    onError: (cause) => setDialogError(errorMessage(cause)),
  })

  const requestMargin = useMutation({
    mutationFn: async () => {
      if (reason.trim() === '') throw new Error(ar ? 'سبب طلب الموافقة مطلوب.' : 'A reason for the approval request is required.')
      await api.post(`/bookings/${bookingId}/margin-approval`, { reason: reason.trim() })
    },
    onSuccess: async () => {
      closeDialog()
      setError('')
      await refresh()
    },
    onError: (cause) => setDialogError(errorMessage(cause)),
  })

  const approveMargin = useMutation({
    mutationFn: async () => {
      await api.post(`/bookings/${bookingId}/margin-approval/approve`)
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const money = (amount: number | undefined) => formatMoney(amount ?? 0, booking?.currency ?? 'EGP', locale)
  const busy = transition.isPending || approveMargin.isPending

  const negative = booking ? booking.margin_minor < 0 : false
  const approval = booking?.margin_approval_status ?? null
  const canRequest =
    booking && negative && can('bookings.transition') && ['draft', 'quoted'].includes(booking.status) && approval !== 'pending' && approval !== 'approved'
  const canApprove =
    booking && negative && can('bookings.approve_margin') && approval === 'pending' && booking.margin_requested_by !== user?.id
  const transitions = (booking?.allowed_transitions ?? []).filter((status) => status !== 'cancelled')
  const canCancel = booking ? can('bookings.cancel') && CANCELLABLE.includes(booking.status) : false

  const supplierName = (item: BookingItem) => (ar ? item.supplier_name_ar : item.supplier_name_en) || item.supplier_name_ar || item.supplier_name_en || '—'
  const netSell = booking ? booking.total_sell_minor - (booking.tax_minor ?? 0) : 0

  return (
    <>
      <Modal
        open={Boolean(bookingId) && dialog === 'none'}
        title={booking ? `${ar ? 'الحجز' : 'Booking'} ${booking.number}` : ar ? 'تفاصيل الحجز' : 'Booking details'}
        onClose={close}
        footer={
          booking ? (
            <>
              {canCancel ? (
                <Button variant="danger" className="me-auto" onClick={() => setDialog('cancel')}>
                  {ar ? 'إلغاء الحجز' : 'Cancel booking'}
                </Button>
              ) : null}
              {can('bookings.transition')
                ? transitions.map((status) => {
                    const label = transitionLabels[status]
                    return (
                      <Button
                        key={status}
                        variant={status === 'confirmed' ? 'primary' : 'secondary'}
                        disabled={busy}
                        onClick={() => transition.mutate(status)}
                      >
                        {label ? label[ar ? 0 : 1] : statusLabel(status, locale)}
                      </Button>
                    )
                  })
                : null}
              <Button variant="secondary" onClick={close}>
                {ar ? 'إغلاق' : 'Close'}
              </Button>
            </>
          ) : undefined
        }
      >
        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {booking ? (
          <div className="flex flex-col gap-5">
            {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
            <CreditLimitNotice
              warnings={creditWarnings}
              blockedMessage={error}
              canOverride={can('bookings.override_credit_limit')}
              overrideReason={overrideReason}
              onOverrideReasonChange={setOverrideReason}
            />

            <dl className="grid gap-x-4 gap-y-3 sm:grid-cols-3">
              <Info label={ar ? 'العميل' : 'Customer'}>{(ar ? booking.customer?.name_ar : booking.customer?.name_en) ?? booking.customer?.name_ar ?? '—'}</Info>
              <Info label={ar ? 'الحالة' : 'Status'}>
                <Badge tone={statusTone(booking.status)}>{statusLabel(booking.status, locale)}</Badge>
              </Info>
              <Info label={ar ? 'العملة / سعر الصرف' : 'Currency / FX rate'}>
                <span dir="ltr" className="tabular">
                  {booking.currency} · {booking.fx_rate}
                </span>
              </Info>
              <Info label={ar ? 'تاريخ البدء' : 'Start date'}>{formatDate(booking.start_date, locale)}</Info>
              <Info label={ar ? 'تاريخ الانتهاء' : 'End date'}>{formatDate(booking.end_date, locale)}</Info>
              <Info label={ar ? 'المسافرون' : 'Pax'}>
                <span className="tabular">
                  {booking.pax_adults} {ar ? 'بالغ' : 'adults'} · {booking.pax_children} {ar ? 'طفل' : 'children'} · {booking.pax_infants} {ar ? 'رضيع' : 'infants'}
                </span>
              </Info>
              <Info label={ar ? 'ملاحظات' : 'Notes'} wide>
                {booking.notes || '—'}
              </Info>
              <Info label={ar ? 'المدفوعات / الفواتير المرتبطة' : 'Linked payments / invoices'}>
                <span className="tabular">
                  {booking.payments_count ?? 0} / {booking.invoices_count ?? 0}
                </span>
              </Info>
            </dl>

            {booking.status === 'cancelled' ? (
              <InlineNotice tone="negative">
                {ar ? 'سبب الإلغاء' : 'Cancellation reason'}: {booking.cancellation_reason || '—'}
                {(booking.cancellation_fee_minor ?? 0) > 0 ? ` · ${ar ? 'رسوم الإلغاء' : 'Fee'}: ${money(booking.cancellation_fee_minor)}` : ''}
              </InlineNotice>
            ) : null}

            {negative ? (
              <div className="flex flex-col gap-2">
                <InlineNotice tone="warning">
                  {ar
                    ? 'هامش الربح سالب. لا يمكن تأكيد الحجز قبل موافقة المدير على الهامش.'
                    : 'This booking has a negative margin. It cannot be confirmed until a manager approves the margin.'}
                </InlineNotice>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="label">{ar ? 'حالة موافقة الهامش' : 'Margin approval'}:</span>
                  <Badge tone={approval ? statusTone(approval) : 'neutral'}>{approval ? statusLabel(approval, locale) : ar ? 'غير مطلوبة بعد' : 'Not requested'}</Badge>
                  {booking.margin_approval_reason ? <span className="text-small text-text-subtle">{booking.margin_approval_reason}</span> : null}
                  {canRequest ? (
                    <Button variant="secondary" onClick={() => setDialog('margin')}>
                      {ar ? 'طلب موافقة على الهامش' : 'Request margin approval'}
                    </Button>
                  ) : null}
                  {canApprove ? (
                    <Button disabled={busy} onClick={() => approveMargin.mutate()}>
                      {ar ? 'اعتماد الهامش' : 'Approve margin'}
                    </Button>
                  ) : null}
                </div>
              </div>
            ) : null}

            <div>
              <h3 className="mb-2 text-sm font-semibold">{ar ? 'البنود' : 'Items'}</h3>
              <div className="rounded-12 border border-border-default">
                <Table>
                  <thead>
                    <tr>
                      <Th>{ar ? 'الخدمة' : 'Service'}</Th>
                      <Th>{ar ? 'المورد' : 'Supplier'}</Th>
                      <Th className="text-end">{ar ? 'الكمية' : 'Qty'}</Th>
                      <Th className="text-end">{ar ? 'تكلفة الوحدة' : 'Unit cost'}</Th>
                      <Th className="text-end">{ar ? 'سعر الوحدة' : 'Unit price'}</Th>
                      <Th className="text-end">{ar ? 'الضريبة' : 'Tax'}</Th>
                      <Th className="text-end">{ar ? 'الإجمالي' : 'Total'}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {(booking.items ?? []).map((item, index) => (
                      <tr key={item.id ?? index}>
                        <Td>
                          <div className="font-medium">{serviceLabel(item.service_type, locale)}</div>
                          <div className="text-small text-text-subtle">{item.description}</div>
                        </Td>
                        <Td>{supplierName(item)}</Td>
                        <Td className="text-end tabular">{item.quantity}</Td>
                        <Td className="text-end tabular">{formatMoney(item.unit_cost_minor, item.cost_currency, locale)}</Td>
                        <Td className="text-end tabular">{formatMoney(item.unit_sell_minor, item.sell_currency, locale)}</Td>
                        <Td className="text-end tabular">
                          {formatMoney(item.tax_minor ?? 0, booking.currency, locale)}
                          {item.tax_profile_name ? <div className="text-small text-text-subtle">{item.tax_profile_name}</div> : null}
                        </Td>
                        <Td className="text-end tabular">{formatMoney(item.total_sell_minor ?? 0, item.sell_currency, locale)}</Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </div>
            </div>

            <dl className="ms-auto grid w-full max-w-sm grid-cols-2 gap-x-4 gap-y-1 text-medium">
              <Row label={ar ? 'المجموع قبل الضريبة' : 'Subtotal'} value={money(netSell)} />
              <Row label={ar ? 'الضريبة' : 'Tax'} value={money(booking.tax_minor)} />
              <Row label={ar ? 'إجمالي البيع' : 'Total sell'} value={money(booking.total_sell_minor)} strong />
              <Row label={ar ? 'إجمالي التكلفة' : 'Total cost'} value={money(booking.total_cost_minor)} />
              <Row label={ar ? 'الهامش' : 'Margin'} value={money(booking.margin_minor)} strong negative={negative} />
              <Row label={ar ? 'المدفوع' : 'Paid'} value={money(booking.paid_minor)} />
              <Row label={ar ? 'المتبقي' : 'Balance'} value={money(booking.balance_minor)} strong />
            </dl>

            <div>
              <h3 className="mb-2 text-sm font-semibold">{ar ? 'سجل الحالات' : 'Status history'}</h3>
              {(booking.status_history ?? []).length === 0 ? (
                <p className="text-small text-text-subtle">—</p>
              ) : (
                <ol className="flex flex-col gap-2">
                  {(booking.status_history ?? []).map((row, index) => (
                    <li key={index} className="text-medium flex flex-wrap items-center gap-2 border-b border-border-subtle pb-2">
                      {row.from ? <Badge tone={statusTone(row.from)}>{statusLabel(row.from, locale)}</Badge> : <span>—</span>}
                      <span aria-hidden="true">{ar ? '←' : '→'}</span>
                      <Badge tone={statusTone(row.to)}>{statusLabel(row.to, locale)}</Badge>
                      <span className="text-small text-text-subtle">{formatDateTime(row.at, locale)}</span>
                      {row.reason ? <span className="text-small text-text-subtle">· {row.reason}</span> : null}
                    </li>
                  ))}
                </ol>
              )}
            </div>
          </div>
        ) : null}
      </Modal>

      <Modal
        open={Boolean(bookingId) && dialog === 'cancel'}
        title={ar ? 'إلغاء الحجز' : 'Cancel booking'}
        onClose={closeDialog}
        footer={
          <>
            <Button variant="secondary" onClick={closeDialog}>
              {ar ? 'تراجع' : 'Back'}
            </Button>
            <Button variant="danger" disabled={cancel.isPending} onClick={() => cancel.mutate()}>
              {cancel.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : ar ? 'تأكيد الإلغاء' : 'Confirm cancellation'}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <Field label={ar ? 'سبب الإلغاء' : 'Cancellation reason'} required>
            <Textarea value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />
          </Field>
          <Field
            label={`${ar ? 'رسوم الإلغاء' : 'Cancellation fee'} (${booking?.currency ?? ''})`}
            hint={ar ? 'اختياري. تُقيَّد كإيراد على العميل.' : 'Optional. Charged to the customer as revenue.'}
          >
            <Input value={fee} onChange={(event) => setFee(event.target.value)} inputMode="decimal" placeholder="0.00" dir="ltr" />
          </Field>
          {dialogError ? <InlineNotice tone="negative">{dialogError}</InlineNotice> : null}
        </div>
      </Modal>

      <Modal
        open={Boolean(bookingId) && dialog === 'margin'}
        title={ar ? 'طلب موافقة على الهامش' : 'Request margin approval'}
        onClose={closeDialog}
        footer={
          <>
            <Button variant="secondary" onClick={closeDialog}>
              {ar ? 'تراجع' : 'Back'}
            </Button>
            <Button disabled={requestMargin.isPending} onClick={() => requestMargin.mutate()}>
              {requestMargin.isPending ? (ar ? 'جارٍ الإرسال…' : 'Sending…') : ar ? 'إرسال الطلب' : 'Send request'}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <Field label={ar ? 'السبب' : 'Reason'} required>
            <Textarea value={reason} maxLength={1000} onChange={(event) => setReason(event.target.value)} />
          </Field>
          {dialogError ? <InlineNotice tone="negative">{dialogError}</InlineNotice> : null}
        </div>
      </Modal>
    </>
  )
}

function Info({ label, children, wide }: { label: string; children: ReactNode; wide?: boolean }) {
  return (
    <div className={wide ? 'sm:col-span-2' : undefined}>
      <dt className="text-small text-text-subtle">{label}</dt>
      <dd className="text-medium mt-1">{children}</dd>
    </div>
  )
}

function Row({ label, value, strong, negative }: { label: string; value: string; strong?: boolean; negative?: boolean }) {
  return (
    <>
      <dt className="text-text-subtle">{label}</dt>
      <dd className={`tabular text-end ${strong ? 'font-semibold' : ''} ${negative ? 'text-danger-text' : ''}`}>{value}</dd>
    </>
  )
}
