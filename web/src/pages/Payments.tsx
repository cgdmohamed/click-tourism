import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import type { Booking, Customer, Paginated, Payment } from '../lib/types'
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
} from '../components/ui'

type CashAccount = { id: string; name_ar: string; name_en: string; currency: string; type: 'cash' | 'bank' }

export default function Payments() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({
    customer_id: '',
    booking_id: '',
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

  const customersQuery = useQuery({
    queryKey: ['customers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Customer[] }>('/customers')
      return data.data
    },
  })

  const bookingsQuery = useQuery({
    queryKey: ['bookings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking[] }>('/bookings')
      return data.data
    },
  })

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

  const customerBookings = useMemo(
    () => (bookingsQuery.data ?? []).filter((booking) => !form.customer_id || booking.customer_id === form.customer_id),
    [bookingsQuery.data, form.customer_id],
  )

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const amountMinor = toMinor(form.amount, form.currency)
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
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const handleMethodChange = (methodId: string) => {
    const selectedMethod = (methodsQuery.data ?? []).find((m) => m.id === methodId)
    setForm((current) => ({
      ...current,
      payment_method_id: methodId,
      method: selectedMethod?.type ?? 'cash',
      cash_account_id: selectedMethod?.default_cash_account_id ?? current.cash_account_id,
    }))
  }

  return (
    <div>
      <PageHeader
        title={t('payments')}
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
              </tr>
            </thead>
            <tbody>
              {payments.map((payment) => (
                <tr key={payment.id}>
                  <Td className="font-medium">{payment.number}</Td>
                  <Td>{formatDate(payment.paid_at, locale)}</Td>
                  <Td>{payment.customer ? (locale === 'ar' ? payment.customer.name_ar : payment.customer.name_en) : payment.customer_id}</Td>
                  <Td>{payment.payment_method?.name_ar || payment.method}</Td>
                  <Td>
                    <Badge tone={statusTone(payment.status)}>{payment.status}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(payment.amount_minor, payment.currency)} {payment.currency}
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(payment.unallocated_minor, payment.currency)} {payment.currency}
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
            <Button disabled={create.isPending || !form.customer_id || !form.cash_account_id} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('customer')} required>
            <SearchableSelect
              value={form.customer_id}
              onChange={(customer_id) => setForm({ ...form, customer_id, booking_id: '' })}
              placeholder={t('selectCustomer')}
              options={(customersQuery.data ?? []).map((customer) => ({
                value: customer.id,
                label: locale === 'ar' ? customer.name_ar : customer.name_en,
                hint: locale === 'ar' ? customer.name_en : customer.name_ar,
              }))}
            />
          </Field>
          <Field label={t('bookings')}>
            <SearchableSelect
              value={form.booking_id}
              onChange={(booking_id) => setForm({ ...form, booking_id })}
              placeholder={locale === 'ar' ? 'دفعة مقدمة' : 'Advance'}
              clearLabel={locale === 'ar' ? 'دفعة مقدمة' : 'Advance'}
              options={customerBookings.map((booking) => ({
                value: booking.id,
                label: `${booking.number} — ${toDecimal(booking.total_sell_minor, booking.currency)} ${booking.currency}`,
              }))}
            />
          </Field>
          <Field label={locale === 'ar' ? 'حساب الخزينة/البنك' : 'Cash / bank account'} required>
            <SearchableSelect
              value={form.cash_account_id}
              onChange={(cash_account_id) => setForm({ ...form, cash_account_id })}
              placeholder={t('selectAccount')}
              options={(cashQuery.data ?? []).map((account) => ({
                value: account.id,
                label: `${locale === 'ar' ? account.name_ar : account.name_en} (${account.currency})`,
              }))}
            />
          </Field>
          <Field label={t('paymentMethod')}>
            <Select value={form.payment_method_id} onChange={(event) => handleMethodChange(event.target.value)}>
              <option value="">{locale === 'ar' ? 'بدون طريقة محددة' : 'Unspecified method'}</option>
              {(methodsQuery.data ?? []).map((method) => (
                <option key={method.id} value={method.id}>
                  {locale === 'ar' ? method.name_ar : method.name_en}
                </option>
              ))}
            </Select>
            <Link to="/payment-methods" className="mt-2 inline-block text-small text-brand-1-accent hover:underline">
              {locale === 'ar' ? 'إدارة طرق الدفع' : 'Manage payment methods'}
            </Link>
          </Field>
          <Field label={t('amount')} required>
            <Input value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
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
    </div>
  )
}
