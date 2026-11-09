import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import type { Supplier, SupplierObligation, SupplierPayment } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th, statusTone } from '../components/ui'

type CashAccount = { id: string; name_ar: string; name_en: string; currency: string; type: 'cash' | 'bank' }

type Tab = 'obligations' | 'payments'

const emptyObligationForm = {
  supplier_id: '',
  amount: '',
  currency: 'EGP',
  invoice_reference: '',
}

const emptyPaymentForm = {
  supplier_id: '',
  supplier_obligation_id: '',
  cash_account_id: '',
  amount: '',
  currency: 'EGP',
  paid_at: todayISO(),
}

export default function SupplierSettlements() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()

  const [tab, setTab] = useState<Tab>('obligations')
  const [error, setError] = useState('')

  const [obligationOpen, setObligationOpen] = useState(false)
  const [obligationForm, setObligationForm] = useState(emptyObligationForm)

  const [paymentOpen, setPaymentOpen] = useState(false)
  const [paymentForm, setPaymentForm] = useState(emptyPaymentForm)

  const [applyPayment, setApplyPayment] = useState<SupplierPayment | null>(null)
  const [applyForm, setApplyForm] = useState({ supplier_obligation_id: '', amount: '' })

  const obligationsQuery = useQuery({
    queryKey: ['supplier-obligations', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: SupplierObligation[] }>('/supplier-obligations')
      return data.data
    },
  })

  const paymentsQuery = useQuery({
    queryKey: ['supplier-payments', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: SupplierPayment[] }>('/supplier-payments')
      return data.data
    },
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Supplier[] }>('/suppliers')
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

  const suppliers = suppliersQuery.data ?? []
  const obligations = useMemo(() => obligationsQuery.data ?? [], [obligationsQuery.data])

  const supplierName = (supplier?: Supplier | null) =>
    supplier ? (locale === 'ar' ? supplier.name_ar : supplier.name_en) : '—'

  const statusLabel = (status: string) => {
    if (status === 'partially_paid') return t('partiallyPaid')
    return status
  }

  const openObligations = useMemo(
    () =>
      obligations.filter(
        (obligation) =>
          obligation.status !== 'paid' &&
          (!applyPayment || obligation.supplier_id === applyPayment.supplier_id) &&
          (!applyPayment || obligation.currency === applyPayment.currency),
      ),
    [obligations, applyPayment],
  )

  const paymentObligations = useMemo(
    () => obligations.filter((obligation) => obligation.supplier_id === paymentForm.supplier_id && obligation.status !== 'paid'),
    [obligations, paymentForm.supplier_id],
  )

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['supplier-obligations'] })
    await queryClient.invalidateQueries({ queryKey: ['supplier-payments'] })
  }

  const createObligation = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        supplier_id: obligationForm.supplier_id,
        branch_id: activeBranch.id,
        amount_minor: toMinor(obligationForm.amount, obligationForm.currency),
        currency: obligationForm.currency,
        invoice_reference: obligationForm.invoice_reference || null,
      }
      const { data } = await api.post<{ data: SupplierObligation }>('/supplier-obligations', payload)
      return data.data
    },
    onSuccess: async () => {
      setObligationOpen(false)
      setError('')
      setObligationForm(emptyObligationForm)
      await invalidate()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createPayment = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        supplier_id: paymentForm.supplier_id,
        supplier_obligation_id: paymentForm.supplier_obligation_id || null,
        branch_id: activeBranch.id,
        cash_account_id: paymentForm.cash_account_id,
        amount_minor: toMinor(paymentForm.amount, paymentForm.currency),
        currency: paymentForm.currency,
        paid_at: paymentForm.paid_at,
      }
      const { data } = await api.post<{ data: SupplierPayment }>('/supplier-payments', payload, {
        headers: { 'Idempotency-Key': idempotencyKey() },
      })
      return data.data
    },
    onSuccess: async () => {
      setPaymentOpen(false)
      setError('')
      setPaymentForm(emptyPaymentForm)
      await invalidate()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const apply = useMutation({
    mutationFn: async () => {
      if (!applyPayment) throw new Error('No supplier payment selected')
      const { data } = await api.post<{ data: SupplierPayment }>(
        `/supplier-payments/${applyPayment.id}/apply`,
        {
          supplier_obligation_id: applyForm.supplier_obligation_id,
          amount_minor: toMinor(applyForm.amount, applyPayment.currency),
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
      return data.data
    },
    onSuccess: async () => {
      setApplyPayment(null)
      setApplyForm({ supplier_obligation_id: '', amount: '' })
      setError('')
      await invalidate()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const startPayment = (obligation?: SupplierObligation) => {
    setError('')
    if (obligation) {
      setPaymentForm({
        ...emptyPaymentForm,
        supplier_id: obligation.supplier_id ?? '',
        supplier_obligation_id: obligation.id,
        amount: toDecimal(obligation.balance_minor, obligation.currency),
        currency: obligation.currency,
      })
    } else {
      setPaymentForm(emptyPaymentForm)
    }
    setPaymentOpen(true)
  }

  const startApply = (payment: SupplierPayment) => {
    setError('')
    setApplyPayment(payment)
    setApplyForm({
      supplier_obligation_id: '',
      amount: toDecimal(payment.unallocated_minor, payment.currency),
    })
  }

  const onSupplierChange = (supplierId: string, target: 'obligation' | 'payment') => {
    const supplier = suppliers.find((item) => item.id === supplierId)
    const currency = supplier?.settlement_currency ?? 'EGP'

    if (target === 'obligation') {
      setObligationForm({ ...obligationForm, supplier_id: supplierId, currency })
    } else {
      setPaymentForm({ ...paymentForm, supplier_id: supplierId, supplier_obligation_id: '', currency })
    }
  }

  return (
    <div>
      <PageHeader
        title={t('supplierSettlements')}
        subtitle={
          locale === 'ar'
            ? 'التزامات الموردين والمدفوعات والدفعات المقدمة'
            : 'Supplier obligations, payments, and advances'
        }
        actions={
          <>
            {can('supplier_obligations.create') ? (
              <Button variant="secondary" onClick={() => { setError(''); setObligationOpen(true) }}>
                {t('newObligation')}
              </Button>
            ) : null}
            {can('supplier_payments.create') ? (
              <Button onClick={() => startPayment()}>{t('newSupplierPayment')}</Button>
            ) : null}
          </>
        }
      />

      {error && !obligationOpen && !paymentOpen && !applyPayment ? (
        <p className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p>
      ) : null}

      <div className="mb-4 flex gap-2">
        <button
          className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${
            tab === 'obligations' ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-line bg-surface text-muted hover:bg-canvas'
          }`}
          onClick={() => setTab('obligations')}
        >
          {t('obligations')}
        </button>
        <button
          className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${
            tab === 'payments' ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-line bg-surface text-muted hover:bg-canvas'
          }`}
          onClick={() => setTab('payments')}
        >
          {t('supplierPayments')}
        </button>
      </div>

      {tab === 'obligations' ? (
        <Card>
          {obligationsQuery.isLoading ? <Loading /> : null}
          {obligationsQuery.isError ? (
            <ErrorState message={errorMessage(obligationsQuery.error)} onRetry={() => void obligationsQuery.refetch()} />
          ) : null}
          {obligationsQuery.data?.length === 0 ? <EmptyState /> : null}
          {obligationsQuery.data && obligationsQuery.data.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{t('supplier')}</Th>
                  <Th>{t('invoiceReference')}</Th>
                  <Th>{t('status')}</Th>
                  <Th className="text-end">{t('amount')}</Th>
                  <Th className="text-end">{t('paidAmount')}</Th>
                  <Th className="text-end">{t('remaining')}</Th>
                  <Th>{t('actions')}</Th>
                </tr>
              </thead>
              <tbody>
                {obligationsQuery.data.map((obligation) => (
                  <tr key={obligation.id}>
                    <Td>{supplierName(obligation.supplier)}</Td>
                    <Td dir="ltr">{obligation.invoice_reference ?? '—'}</Td>
                    <Td>
                      <Badge tone={statusTone(obligation.status)}>{statusLabel(obligation.status)}</Badge>
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(obligation.amount_minor, obligation.currency)} {obligation.currency}
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(obligation.paid_minor, obligation.currency)} {obligation.currency}
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(obligation.balance_minor, obligation.currency)} {obligation.currency}
                    </Td>
                    <Td>
                      {obligation.balance_minor > 0 && can('supplier_payments.create') ? (
                        <Button variant="secondary" onClick={() => startPayment(obligation)}>
                          {t('pay')}
                        </Button>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      ) : (
        <Card>
          {paymentsQuery.isLoading ? <Loading /> : null}
          {paymentsQuery.isError ? (
            <ErrorState message={errorMessage(paymentsQuery.error)} onRetry={() => void paymentsQuery.refetch()} />
          ) : null}
          {paymentsQuery.data?.length === 0 ? <EmptyState /> : null}
          {paymentsQuery.data && paymentsQuery.data.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{t('supplier')}</Th>
                  <Th>{t('date')}</Th>
                  <Th>{t('status')}</Th>
                  <Th className="text-end">{t('amount')}</Th>
                  <Th className="text-end">{t('applied')}</Th>
                  <Th className="text-end">{t('unapplied')}</Th>
                  <Th>{t('actions')}</Th>
                </tr>
              </thead>
              <tbody>
                {paymentsQuery.data.map((payment) => (
                  <tr key={payment.id}>
                    <Td>{supplierName(payment.supplier)}</Td>
                    <Td>{formatDate(payment.paid_at, locale)}</Td>
                    <Td>
                      <Badge tone={statusTone(payment.status)}>{payment.status}</Badge>
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(payment.amount_minor, payment.currency)} {payment.currency}
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(payment.applied_minor, payment.currency)} {payment.currency}
                    </Td>
                    <Td className="text-end tabular">
                      {toDecimal(payment.unallocated_minor, payment.currency)} {payment.currency}
                    </Td>
                    <Td>
                      {payment.unallocated_minor > 0 && can('supplier_payments.create') ? (
                        <Button variant="secondary" onClick={() => startApply(payment)}>
                          {t('applyAdvance')}
                        </Button>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      )}

      <Modal
        open={obligationOpen}
        title={t('newObligation')}
        onClose={() => setObligationOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setObligationOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              disabled={createObligation.isPending || !obligationForm.supplier_id || !obligationForm.amount}
              onClick={() => createObligation.mutate()}
            >
              {createObligation.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('supplier')} required>
            <Select value={obligationForm.supplier_id} onChange={(event) => onSupplierChange(event.target.value, 'obligation')}>
              <option value="">{t('selectSupplier')}</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplierName(supplier)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('currency')} required>
            <Select value={obligationForm.currency} onChange={(event) => setObligationForm({ ...obligationForm, currency: event.target.value })}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={t('amount')} required>
            <Input value={obligationForm.amount} onChange={(event) => setObligationForm({ ...obligationForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={t('invoiceReference')}>
            <Input
              value={obligationForm.invoice_reference}
              onChange={(event) => setObligationForm({ ...obligationForm, invoice_reference: event.target.value })}
              dir="ltr"
            />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
      </Modal>

      <Modal
        open={paymentOpen}
        title={t('newSupplierPayment')}
        onClose={() => setPaymentOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setPaymentOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              disabled={createPayment.isPending || !paymentForm.supplier_id || !paymentForm.cash_account_id || !paymentForm.amount}
              onClick={() => createPayment.mutate()}
            >
              {createPayment.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('supplier')} required>
            <Select value={paymentForm.supplier_id} onChange={(event) => onSupplierChange(event.target.value, 'payment')}>
              <option value="">{t('selectSupplier')}</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplierName(supplier)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('obligation')} hint={paymentForm.supplier_id && paymentObligations.length === 0 ? t('noOpenObligations') : undefined}>
            <Select
              value={paymentForm.supplier_obligation_id}
              onChange={(event) => {
                const obligation = paymentObligations.find((item) => item.id === event.target.value)
                setPaymentForm({
                  ...paymentForm,
                  supplier_obligation_id: event.target.value,
                  currency: obligation?.currency ?? paymentForm.currency,
                  amount: obligation ? toDecimal(obligation.balance_minor, obligation.currency) : paymentForm.amount,
                })
              }}
            >
              <option value="">{t('advance')}</option>
              {paymentObligations.map((obligation) => (
                <option key={obligation.id} value={obligation.id}>
                  {obligation.invoice_reference ?? obligation.id} — {toDecimal(obligation.balance_minor, obligation.currency)} {obligation.currency}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={locale === 'ar' ? 'حساب الخزينة/البنك' : 'Cash / bank account'} required>
            <Select value={paymentForm.cash_account_id} onChange={(event) => setPaymentForm({ ...paymentForm, cash_account_id: event.target.value })}>
              <option value="">{locale === 'ar' ? 'اختر الحساب' : 'Select account'}</option>
              {(cashQuery.data ?? []).map((account) => (
                <option key={account.id} value={account.id}>
                  {locale === 'ar' ? account.name_ar : account.name_en} ({account.currency})
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('amount')} required>
            <Input value={paymentForm.amount} onChange={(event) => setPaymentForm({ ...paymentForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={paymentForm.currency} onChange={(event) => setPaymentForm({ ...paymentForm, currency: event.target.value })}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={t('date')} required>
            <Input type="date" value={paymentForm.paid_at} onChange={(event) => setPaymentForm({ ...paymentForm, paid_at: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
      </Modal>

      <Modal
        open={applyPayment !== null}
        title={t('applyAdvance')}
        onClose={() => setApplyPayment(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setApplyPayment(null)}>
              {t('cancel')}
            </Button>
            <Button
              disabled={apply.isPending || !applyForm.supplier_obligation_id || !applyForm.amount}
              onClick={() => apply.mutate()}
            >
              {apply.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        {applyPayment ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('supplier')}>
              <Input value={supplierName(applyPayment.supplier)} readOnly />
            </Field>
            <Field label={t('unapplied')}>
              <Input value={`${toDecimal(applyPayment.unallocated_minor, applyPayment.currency)} ${applyPayment.currency}`} readOnly dir="ltr" />
            </Field>
            <Field label={t('obligation')} required hint={openObligations.length === 0 ? t('noOpenObligations') : undefined}>
              <Select
                value={applyForm.supplier_obligation_id}
                onChange={(event) => {
                  const obligation = openObligations.find((item) => item.id === event.target.value)
                  setApplyForm({
                    ...applyForm,
                    supplier_obligation_id: event.target.value,
                    amount: obligation
                      ? toDecimal(
                          Math.min(obligation.balance_minor, applyPayment.unallocated_minor),
                          obligation.currency,
                        )
                      : applyForm.amount,
                  })
                }}
              >
                <option value="">{t('selectObligation')}</option>
                {openObligations.map((obligation) => (
                  <option key={obligation.id} value={obligation.id}>
                    {obligation.invoice_reference ?? obligation.id} — {toDecimal(obligation.balance_minor, obligation.currency)} {obligation.currency}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={t('amount')} required>
              <Input value={applyForm.amount} onChange={(event) => setApplyForm({ ...applyForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
          </div>
        ) : null}
        {error ? <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
      </Modal>
    </div>
  )
}
