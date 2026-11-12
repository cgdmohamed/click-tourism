import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { useActiveCurrencyCodes } from '../lib/useCurrencies'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import { useBaseCurrency } from '../lib/useSetting'
import { useRemoteOptions } from '../lib/useRemoteOptions'
import PartyPicker from '../components/PartyPicker'
import type { Booking, Paginated, Payment, Refund } from '../lib/types'
import type { PaymentMethod } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  PageHeader,
  Pagination,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
  statusTone,
  type SelectOption,
} from '../components/ui'
import { localizedName } from '../lib/names'
import { paymentMethodLabel, statusLabel } from '../lib/labels'
import { ReasonModal } from '../components/ReasonModal'
import { ApplyToInvoiceModal, type ApplyTarget } from '../components/ApplyToInvoiceModal'

type CashAccount = { id: string; name_ar: string; name_en: string; currency: string; type: 'cash' | 'bank' }
type OpenCustomerInvoice = { id: string; number: string; currency: string; outstanding_minor: number; issue_date: string }

const bookingToOption = (booking: Booking): SelectOption => ({
  value: booking.id,
  label: `${booking.number} — ${toDecimal(booking.total_sell_minor, booking.currency)} ${booking.currency}`,
})

export default function Payments() {
  const currencyCodes = useActiveCurrencyCodes()
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [applyTarget, setApplyTarget] = useState<ApplyTarget | null>(null)
  const [cancelTarget, setCancelTarget] = useState<Payment | null>(null)
  const [cancelError, setCancelError] = useState('')
  const [refundTarget, setRefundTarget] = useState<Payment | null>(null)
  const [refundForm, setRefundForm] = useState({ amount: '', reason: '' })
  const [refundError, setRefundError] = useState('')
  const [refundsFor, setRefundsFor] = useState<Payment | null>(null)
  const [approveTarget, setApproveTarget] = useState<Refund | null>(null)
  const [approveFx, setApproveFx] = useState('')
  const [approveError, setApproveError] = useState('')
  const [form, setForm] = useState({
    customer_id: '',
    booking_id: '',
    invoice_id: '',
    cash_account_id: '',
    payment_method_id: '',
    method: 'cash',
    amount: '',
    currency: 'EGP',
    paid_at: todayISO(),
    fx_rate: '1',
  })

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const paymentsQuery = useQuery({
    queryKey: ['payments', search, page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Payment>>('/payments', {
        params: { search: search || undefined, page, per_page: 20 },
      })
      return data
    },
  })

  const payments = paymentsQuery.data?.data ?? []

  const cashQuery = useQuery({
    queryKey: ['cash-accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
  })

  const methodsQuery = useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const { data } = await api.get<{ data: PaymentMethod[] }>('/payment-methods')
      return data.data
    },
  })

  const refundsQuery = useQuery({
    queryKey: ['refunds', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Refund>>('/refunds', { params: { per_page: 100 } })
      return data.data
    },
  })
  const refunds = refundsQuery.data ?? []

  /** Money still refundable: the receipt minus pending and approved refunds. */
  const refundableMinor = (payment: Payment) =>
    payment.amount_minor -
    refunds
      .filter((refund) => refund.payment_id === payment.id && (refund.status === 'requested' || refund.status === 'approved'))
      .reduce((sum, refund) => sum + refund.amount_minor, 0)

  const cancelPayment = useMutation({
    mutationFn: async (reason: string) => {
      if (!cancelTarget) throw new Error('No payment selected')
      await api.post(`/payments/${cancelTarget.id}/cancel`, { reason })
    },
    onSuccess: async () => {
      setCancelTarget(null)
      setCancelError('')
      await queryClient.invalidateQueries({ queryKey: ['payments'] })
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setCancelError(errorMessage(cause)),
  })

  const requestRefund = useMutation({
    mutationFn: async () => {
      if (!refundTarget) throw new Error('No payment selected')
      const amountMinor = toMinor(refundForm.amount, refundTarget.currency)
      if (amountMinor <= 0 || amountMinor > refundableMinor(refundTarget)) {
        throw new Error(locale === 'ar' ? 'المبلغ يتجاوز المتاح للاسترداد' : 'Amount exceeds the refundable amount')
      }
      await api.post(
        '/refunds',
        { payment_id: refundTarget.id, amount_minor: amountMinor, reason: refundForm.reason },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setRefundTarget(null)
      setRefundError('')
      await queryClient.invalidateQueries({ queryKey: ['refunds'] })
    },
    onError: (cause) => setRefundError(errorMessage(cause)),
  })

  const baseCurrency = useBaseCurrency()

  const approveRefund = useMutation({
    mutationFn: async ({ refund, fxRate }: { refund: Refund; fxRate: string }) => {
      await api.post(
        `/refunds/${refund.id}/approve`,
        fxRate.trim() ? { fx_rate: fxRate.trim() } : undefined,
      )
    },
    onSuccess: async () => {
      setApproveTarget(null)
      setApproveFx('')
      setApproveError('')
      await queryClient.invalidateQueries({ queryKey: ['refunds'] })
      await queryClient.invalidateQueries({ queryKey: ['payments'] })
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
    },
    onError: (cause) => setApproveError(errorMessage(cause)),
  })

  // Bookings of the chosen customer, searchable by number on the server.
  const bookingParams = useMemo(() => ({ customer_id: form.customer_id || undefined }), [form.customer_id])
  const bookingPicker = useRemoteOptions<Booking>({
    endpoint: '/bookings',
    keyPrefix: 'bookings',
    params: bookingParams,
    toOption: bookingToOption,
    value: form.booking_id,
  })

  // One issued invoice can have many linked receipts. Selecting it prefills
  // the outstanding amount, which remains editable for partial collection.
  const invoiceParams = useMemo(() => ({
    type: 'customer_invoice',
    party_type: 'customer',
    party_id: form.customer_id || undefined,
    currency: form.currency,
    open: 1,
  }), [form.customer_id, form.currency])
  const invoicePicker = useRemoteOptions<OpenCustomerInvoice>({
    endpoint: '/invoices',
    keyPrefix: 'customer-collection-invoices',
    params: invoiceParams,
    value: form.invoice_id,
    enabled: open && !!form.customer_id,
    toOption: (invoice) => ({
      value: invoice.id,
      label: `${invoice.number} · ${toDecimal(invoice.outstanding_minor, invoice.currency)} ${invoice.currency}`,
    }),
  })
  const selectedInvoice = invoicePicker.rows.find((invoice) => invoice.id === form.invoice_id)
  const chooseInvoice = (invoiceId: string) => {
    const invoice = invoicePicker.rows.find((item) => item.id === invoiceId)
    setForm((current) => ({
      ...current,
      invoice_id: invoiceId,
      booking_id: '',
      amount: invoice ? toDecimal(invoice.outstanding_minor, invoice.currency) : '',
    }))
  }

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const amountMinor = toMinor(form.amount, form.currency)
      if (form.invoice_id) {
        if (!selectedInvoice || !form.payment_method_id || amountMinor <= 0 || amountMinor > selectedInvoice.outstanding_minor) {
          throw new Error(locale === 'ar' ? 'اختر فاتورة وطريقة تحصيل ومبلغًا لا يتجاوز المستحق.' : 'Choose an invoice, payment method, and amount within the outstanding balance.')
        }
        await api.post(`/invoices/${form.invoice_id}/collections`, {
          receipts: [{
            amount_minor: amountMinor,
            cash_account_id: form.cash_account_id,
            payment_method_id: form.payment_method_id,
            paid_at: form.paid_at,
            ...(form.currency !== baseCurrency ? { fx_rate: form.fx_rate } : {}),
          }],
        }, { headers: { 'Idempotency-Key': idempotencyKey() } })
        return
      }
      const payload = {
        customer_id: form.customer_id,
        booking_id: form.booking_id || null,
        branch_id: activeBranch.id,
        cash_account_id: form.cash_account_id,
        payment_method_id: form.payment_method_id || null,
        method: form.method,
        amount_minor: amountMinor,
        currency: form.currency,
        fx_rate: form.fx_rate,
        paid_at: form.paid_at,
        allocations: form.booking_id ? [{ booking_id: form.booking_id, amount_minor: amountMinor }] : [],
      }
      const { data } = await api.post<{ data: Payment }>('/payments', payload, {
        headers: { 'Idempotency-Key': idempotencyKey() },
      })
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm({
        customer_id: '',
        booking_id: '',
        invoice_id: '',
        cash_account_id: '',
        payment_method_id: '',
        method: 'cash',
        amount: '',
        currency: 'EGP',
        paid_at: todayISO(),
        fx_rate: '1',
      })
      await queryClient.invalidateQueries({ queryKey: ['payments'] })
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
      await queryClient.invalidateQueries({ queryKey: ['invoices'] })
      await queryClient.invalidateQueries({ queryKey: ['invoice-profile'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const handleMethodChange = (methodId: string) => {
    const selectedMethod = (methodsQuery.data ?? []).find((m) => m.id === methodId)
    setForm((current) => ({
      ...current,
      payment_method_id: methodId,
      method: selectedMethod?.type ?? 'cash',
      cash_account_id: selectedMethod?.default_cash_account_id ?? '',
    }))
  }

  return (
    <div>
      <PageHeader
        title={locale === 'ar' ? 'تحصيلات العملاء' : 'Customer collections'}
        subtitle={locale === 'ar' ? 'سندات القبض والدفعات الجزئية والدفعات المقدمة' : 'Receipts, partial payments, and advances'}
        icon="creditCard"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newPayment')}</Button>}
      />

      <Card>
        <div className="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder={t('search')}
            className="max-w-xs"
          />
        </div>
        {paymentsQuery.isLoading ? <Loading /> : null}
        {paymentsQuery.isError ? (
          <ErrorState message={errorMessage(paymentsQuery.error)} onRetry={() => void paymentsQuery.refetch()} />
        ) : null}
        {paymentsQuery.data && payments.length === 0 ? <EmptyState /> : null}
        {payments.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('date')}</Th>
                <Th>{t('customer')}</Th>
                <Th>{t('paymentMethod')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('amount')}</Th>
                <Th className="text-end">{locale === 'ar' ? 'غير مخصص' : 'Unallocated'}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {payments.map((payment) => (
                <tr key={payment.id}>
                  <Td className="font-medium">{payment.number}</Td>
                  <Td>{formatDate(payment.paid_at, locale)}</Td>
                  <Td>{payment.customer ? (localizedName(payment.customer, locale)) : payment.customer_id}</Td>
                  <Td>{payment.payment_method ? (localizedName(payment.payment_method, locale) || payment.payment_method.name_ar) : paymentMethodLabel(payment.method, locale)}</Td>
                  <Td>
                    <Badge tone={statusTone(payment.status)}>{statusLabel(payment.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(payment.amount_minor, payment.currency)} {payment.currency}
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(payment.unallocated_minor, payment.currency)} {payment.currency}
                  </Td>
                  <Td className="text-end">
                    {payment.status === 'posted' && payment.unallocated_minor > 0 && can('payments.create') ? (
                      <Button
                        variant="ghost"
                        onClick={() =>
                          setApplyTarget({
                            kind: 'customer',
                            paymentId: payment.id,
                            paymentLabel: payment.number,
                            partyId: payment.customer_id,
                            currency: payment.currency,
                            availableMinor: payment.unallocated_minor,
                          })
                        }
                      >
                        {locale === 'ar' ? 'تطبيق على فاتورة' : 'Apply to invoice'}
                      </Button>
                    ) : null}
                    {payment.status === 'posted' && can('refunds.create') && refundableMinor(payment) > 0 ? (
                      <Button
                        variant="ghost"
                        onClick={() => {
                          setRefundError('')
                          setRefundForm({ amount: toDecimal(refundableMinor(payment), payment.currency), reason: '' })
                          setRefundTarget(payment)
                        }}
                      >
                        {locale === 'ar' ? 'استرداد' : 'Refund'}
                      </Button>
                    ) : null}
                    {refunds.some((refund) => refund.payment_id === payment.id) ? (
                      <Button variant="ghost" onClick={() => setRefundsFor(payment)}>
                        {locale === 'ar' ? 'الاستردادات' : 'Refunds'}
                      </Button>
                    ) : null}
                    {payment.status === 'posted' && can('payments.reverse') ? (
                      <Button
                        variant="ghost"
                        onClick={() => {
                          setCancelError('')
                          setCancelTarget(payment)
                        }}
                      >
                        {locale === 'ar' ? 'إلغاء السند' : 'Cancel receipt'}
                      </Button>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
        <Pagination
          page={paymentsQuery.data?.meta.current_page ?? 1}
          lastPage={paymentsQuery.data?.meta.last_page ?? 1}
          onChange={setPage}
        />
      </Card>

      <Modal
        open={open}
        title={t('newPayment')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={create.isPending || !form.customer_id || !form.cash_account_id || (form.invoice_id !== '' && !form.payment_method_id)} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('customer')} required>
            <PartyPicker
              kind="customer"
              value={form.customer_id}
              onChange={(customer_id) => setForm({ ...form, customer_id, booking_id: '', invoice_id: '', amount: '' })}
              placeholder={t('selectCustomer')}
            />
          </Field>
          <Field label={locale === 'ar' ? 'فاتورة العميل' : 'Customer invoice'}>
            <SearchableSelect
              value={form.invoice_id}
              onChange={chooseInvoice}
              placeholder={locale === 'ar' ? 'اختر الفاتورة (أو اتركها فارغة لدفعة مقدمة)' : 'Choose invoice (or leave blank for advance)'}
              clearLabel={locale === 'ar' ? 'بدون فاتورة — دفعة مقدمة' : 'No invoice — advance'}
              options={invoicePicker.options}
              onSearch={invoicePicker.setSearch}
              hasMore={invoicePicker.hasMore}
              disabled={!form.customer_id}
            />
          </Field>
          <Field label={t('bookings')}>
            <SearchableSelect
              value={form.booking_id}
              onChange={(booking_id) => setForm({ ...form, booking_id, invoice_id: '', amount: '' })}
              placeholder={locale === 'ar' ? 'دفعة مقدمة' : 'Advance'}
              clearLabel={locale === 'ar' ? 'دفعة مقدمة' : 'Advance'}
              options={bookingPicker.options}
              disabled={!!form.invoice_id}
              onSearch={bookingPicker.setSearch}
              hasMore={bookingPicker.hasMore}
            />
          </Field>
          <Field label={locale === 'ar' ? 'حساب الخزينة/البنك' : 'Cash / bank account'} required>
            <SearchableSelect
              value={form.cash_account_id}
              onChange={(cash_account_id) => setForm({ ...form, cash_account_id })}
              placeholder={t('selectAccount')}
              disabled={!!(methodsQuery.data ?? []).find((method) =>
                method.id === form.payment_method_id && method.default_cash_account_id)}
              options={(cashQuery.data ?? [])
                .filter((account) => account.currency === form.currency)
                .filter((account) => {
                  const selected = (methodsQuery.data ?? []).find((method) => method.id === form.payment_method_id)
                  return !selected?.default_cash_account_id || selected.default_cash_account_id === account.id
                })
                .map((account) => ({
                  value: account.id,
                  label: `${localizedName(account, locale)} (${account.currency})`,
                }))}
            />
          </Field>
          <Field label={t('paymentMethod')}>
            <Select value={form.payment_method_id} onChange={(event) => handleMethodChange(event.target.value)}>
              <option value="">{locale === 'ar' ? 'بدون طريقة محددة' : 'Unspecified method'}</option>
              {(methodsQuery.data ?? []).map((method) => (
                <option key={method.id} value={method.id}>
                  {localizedName(method, locale)}
                </option>
              ))}
            </Select>
            <Link to="/payment-methods" className="mt-2 inline-block text-small text-brand-1-accent hover:underline">
              {locale === 'ar' ? 'إدارة طرق الدفع' : 'Manage payment methods'}
            </Link>
          </Field>
          <Field label={t('amount')} required hint={selectedInvoice
            ? (locale === 'ar' ? `المستحق: ${toDecimal(selectedInvoice.outstanding_minor, selectedInvoice.currency)} ${selectedInvoice.currency} — يمكنك تقليل المبلغ للتحصيل الجزئي` : `Outstanding: ${toDecimal(selectedInvoice.outstanding_minor, selectedInvoice.currency)} ${selectedInvoice.currency}. Edit for partial payment.`)
            : undefined}>
            <Input value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value, invoice_id: '', amount: '', cash_account_id: '' })}>
              {currencyCodes.map((code) => (
                <option key={code} value={code}>{code}</option>
              ))}
            </Select>
          </Field>
          <Field label={t('date')} required>
            <Input type="date" value={form.paid_at} onChange={(event) => setForm({ ...form, paid_at: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'سعر الصرف' : 'FX rate'}>
            <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <ApplyToInvoiceModal target={applyTarget} onClose={() => setApplyTarget(null)} />

      <ReasonModal
        open={cancelTarget !== null}
        title={locale === 'ar' ? `إلغاء السند ${cancelTarget?.number ?? ''}` : `Cancel receipt ${cancelTarget?.number ?? ''}`}
        description={
          locale === 'ar'
            ? 'سيتم عكس قيود السند وتحرير التخصيص على الحجوزات. لا يمكن الإلغاء مع وجود استردادات أو تطبيقات على فواتير.'
            : 'The receipt postings are reversed and booking allocations released. Not possible while refunds or invoice applications exist.'
        }
        confirmLabel={locale === 'ar' ? 'إلغاء السند' : 'Cancel receipt'}
        pending={cancelPayment.isPending}
        error={cancelError}
        onClose={() => setCancelTarget(null)}
        onConfirm={(reason) => cancelPayment.mutate(reason)}
      />

      <Modal
        open={refundTarget !== null}
        title={locale === 'ar' ? `طلب استرداد — ${refundTarget?.number ?? ''}` : `Request refund — ${refundTarget?.number ?? ''}`}
        onClose={() => setRefundTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRefundTarget(null)}>
              {t('cancel')}
            </Button>
            <Button disabled={requestRefund.isPending || !refundForm.amount || !refundForm.reason.trim()} onClick={() => requestRefund.mutate()}>
              {requestRefund.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        {refundTarget ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label={t('amount')}
              required
              hint={`${locale === 'ar' ? 'المتاح للاسترداد' : 'Available'}: ${toDecimal(refundableMinor(refundTarget), refundTarget.currency)} ${refundTarget.currency}`}
            >
              <Input value={refundForm.amount} onChange={(event) => setRefundForm({ ...refundForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
            <Field label={locale === 'ar' ? 'السبب' : 'Reason'} required>
              <Input value={refundForm.reason} maxLength={255} onChange={(event) => setRefundForm({ ...refundForm, reason: event.target.value })} />
            </Field>
          </div>
        ) : null}
        {refundError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{refundError}</p> : null}
      </Modal>

      <Modal
        open={refundsFor !== null}
        title={locale === 'ar' ? `استردادات السند ${refundsFor?.number ?? ''}` : `Refunds of ${refundsFor?.number ?? ''}`}
        onClose={() => setRefundsFor(null)}
      >
        <Table>
          <thead>
            <tr>
              <Th>{t('code')}</Th>
              <Th className="text-end">{t('amount')}</Th>
              <Th>{t('status')}</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {refunds
              .filter((refund) => refund.payment_id === refundsFor?.id)
              .map((refund) => (
                <tr key={refund.id}>
                  <Td className="font-medium">{refund.number}</Td>
                  <Td className="text-end tabular">
                    {toDecimal(refund.amount_minor, refund.currency)} {refund.currency}
                  </Td>
                  <Td>
                    <Badge tone={statusTone(refund.status)}>{statusLabel(refund.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end">
                    {refund.status === 'requested' && can('refunds.approve') ? (
                      <Button
                        variant="secondary"
                        disabled={approveRefund.isPending}
                        onClick={() => { setApproveError(''); setApproveFx(''); setApproveTarget(refund) }}
                      >
                        {locale === 'ar' ? 'اعتماد' : 'Approve'}
                      </Button>
                    ) : null}
                  </Td>
                </tr>
              ))}
          </tbody>
        </Table>
      </Modal>

      <Modal
        open={approveTarget !== null}
        title={locale === 'ar' ? `اعتماد الاسترداد ${approveTarget?.number ?? ''}` : `Approve refund ${approveTarget?.number ?? ''}`}
        onClose={() => setApproveTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setApproveTarget(null)}>{t('cancel')}</Button>
            <Button
              disabled={approveRefund.isPending}
              onClick={() => approveTarget && approveRefund.mutate({ refund: approveTarget, fxRate: approveFx })}
            >
              {locale === 'ar' ? 'اعتماد' : 'Approve'}
            </Button>
          </>
        }
      >
        {approveTarget ? (
          <div className="grid gap-4">
            <p className="text-small text-text-subtle">
              {locale === 'ar' ? 'المبلغ' : 'Amount'}: {toDecimal(approveTarget.amount_minor, approveTarget.currency)} {approveTarget.currency}
            </p>
            {approveTarget.currency !== baseCurrency ? (
              <Field
                label={locale === 'ar' ? 'سعر الصرف (اختياري)' : 'FX rate (optional)'}
                hint={locale === 'ar'
                  ? 'اتركه فارغًا لاستخدام سعر الصرف المحفوظ في سند القبض.'
                  : "Leave blank to use the receipt's saved FX rate."}
              >
                <Input value={approveFx} onChange={(event) => setApproveFx(event.target.value)} inputMode="decimal" dir="ltr" />
              </Field>
            ) : null}
            {approveError ? <p className="rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{approveError}</p> : null}
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
