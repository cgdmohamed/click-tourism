import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import { usePartyOptions } from '../lib/usePartyOptions'
import { useRemoteOptions } from '../lib/useRemoteOptions'
import type { Paginated, Supplier, SupplierObligation, SupplierPayment } from '../lib/types'
import type { SelectOption } from '../components/ui'
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
import { statusLabel as statusLabelOf } from '../lib/labels'
import { ReasonModal } from '../components/ReasonModal'
import { ApplyToInvoiceModal, type ApplyTarget } from '../components/ApplyToInvoiceModal'

type CashAccount = { id: string; name_ar: string; name_en: string; currency: string; type: 'cash' | 'bank' }

type Tab = 'obligations' | 'payments'

type IssuedInvoice = { id: string; number: string; currency: string; total_minor: number; outstanding_minor: number }

const invoiceToOption = (invoice: IssuedInvoice): SelectOption => ({ value: invoice.id, label: invoice.number })

const obligationToOption = (obligation: SupplierObligation): SelectOption => ({
  value: obligation.id,
  label: `${obligation.invoice_reference ?? obligation.id} — ${toDecimal(obligation.balance_minor, obligation.currency)} ${obligation.currency}`,
})

const emptyObligationForm = {
  supplier_id: '',
  amount: '',
  currency: 'EGP',
  invoice_reference: '',
  invoice_id: '',
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
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()

  const [tab, setTab] = useState<Tab>('obligations')
  const [error, setError] = useState('')
  const [invoiceTarget, setInvoiceTarget] = useState<ApplyTarget | null>(null)
  const [recognizeTarget, setRecognizeTarget] = useState<SupplierObligation | null>(null)
  const [recognizeInvoiceId, setRecognizeInvoiceId] = useState('')

  const [obligationOpen, setObligationOpen] = useState(false)
  const [obligationForm, setObligationForm] = useState(emptyObligationForm)

  const [paymentOpen, setPaymentOpen] = useState(false)
  const [paymentForm, setPaymentForm] = useState(emptyPaymentForm)

  const [applyPayment, setApplyPayment] = useState<SupplierPayment | null>(null)
  const [cancelPayment, setCancelPayment] = useState<SupplierPayment | null>(null)
  const [cancelError, setCancelError] = useState('')
  const [applyForm, setApplyForm] = useState({ supplier_obligation_id: '', amount: '' })

  const [obligationPage, setObligationPage] = useState(1)
  const [paymentPage, setPaymentPage] = useState(1)

  const obligationsQuery = useQuery({
    queryKey: ['supplier-obligations', 'list', obligationPage, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<SupplierObligation>>('/supplier-obligations', {
        params: { page: obligationPage, per_page: 20 },
      })
      return data
    },
  })

  const paymentsQuery = useQuery({
    queryKey: ['supplier-payments', 'list', paymentPage, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<SupplierPayment>>('/supplier-payments', {
        params: { page: paymentPage, per_page: 20 },
      })
      return data
    },
  })

  const cashQuery = useQuery({
    queryKey: ['cash-accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
  })

  const obligations = obligationsQuery.data?.data ?? []
  const payments = paymentsQuery.data?.data ?? []

  // The plain `/suppliers` list stays for lookups; the pickers search on the
  // server so a supplier beyond the first page can still be selected.
  const obligationSupplierPicker = usePartyOptions('supplier', { value: obligationForm.supplier_id, locale })
  const paymentSupplierPicker = usePartyOptions('supplier', { value: paymentForm.supplier_id, locale })

  // Issued supplier invoices of the chosen supplier, found by number on the server
  // (the amount must match the obligation exactly, as before).
  const obligationAmountMinor = toMinor(obligationForm.amount || '0', obligationForm.currency)
  const createInvoiceParams = useMemo(
    () => ({ type: 'supplier_invoice', status: 'issued', party_type: 'supplier', party_id: obligationForm.supplier_id, currency: obligationForm.currency }),
    [obligationForm.supplier_id, obligationForm.currency],
  )
  const matchesCreateAmount = useCallback(
    (invoice: IssuedInvoice) => invoice.total_minor === obligationAmountMinor && invoice.outstanding_minor === obligationAmountMinor,
    [obligationAmountMinor],
  )
  const createInvoicePicker = useRemoteOptions<IssuedInvoice>({
    endpoint: '/invoices',
    keyPrefix: 'issued-supplier-invoices',
    params: createInvoiceParams,
    toOption: invoiceToOption,
    select: matchesCreateAmount,
    value: obligationForm.invoice_id,
    enabled: obligationOpen && obligationForm.supplier_id !== '',
  })

  const recognizeInvoiceParams = useMemo(
    () => ({ type: 'supplier_invoice', status: 'issued', party_type: 'supplier', party_id: recognizeTarget?.supplier_id ?? '', currency: recognizeTarget?.currency }),
    [recognizeTarget?.supplier_id, recognizeTarget?.currency],
  )
  const recognizeAmountMinor = recognizeTarget?.amount_minor
  const matchesRecognizeAmount = useCallback(
    (invoice: IssuedInvoice) => invoice.total_minor === recognizeAmountMinor && invoice.outstanding_minor === recognizeAmountMinor,
    [recognizeAmountMinor],
  )
  const recognizeInvoicePicker = useRemoteOptions<IssuedInvoice>({
    endpoint: '/invoices',
    keyPrefix: 'issued-supplier-invoices',
    params: recognizeInvoiceParams,
    toOption: invoiceToOption,
    select: matchesRecognizeAmount,
    value: recognizeInvoiceId,
    enabled: recognizeTarget !== null && Boolean(recognizeTarget.supplier_id),
  })

  // Unpaid, recognized obligations of the chosen supplier (server-filtered, searchable).
  const paymentObligationParams = useMemo(
    () => ({ supplier_id: paymentForm.supplier_id, payable: 1 }),
    [paymentForm.supplier_id],
  )
  const paymentObligationPicker = useRemoteOptions<SupplierObligation>({
    endpoint: '/supplier-obligations',
    keyPrefix: 'supplier-obligations',
    params: paymentObligationParams,
    toOption: obligationToOption,
    value: paymentForm.supplier_obligation_id,
    enabled: paymentForm.supplier_id !== '',
  })
  const paymentObligations = paymentObligationPicker.rows

  const applyObligationParams = useMemo(
    () => ({ supplier_id: applyPayment?.supplier_id, payable: 1, currency: applyPayment?.currency }),
    [applyPayment?.supplier_id, applyPayment?.currency],
  )
  const applyObligationPicker = useRemoteOptions<SupplierObligation>({
    endpoint: '/supplier-obligations',
    keyPrefix: 'supplier-obligations',
    params: applyObligationParams,
    toOption: obligationToOption,
    value: applyForm.supplier_obligation_id,
    enabled: Boolean(applyPayment?.supplier_id),
  })
  const openObligations = applyObligationPicker.rows

  const supplierName = (supplier?: Supplier | null) =>
    supplier ? (locale === 'ar' ? supplier.name_ar : supplier.name_en) : '—'

  const statusLabel = (status: string) => {
    if (status === 'partially_paid') return t('partiallyPaid')
    return status
  }

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
        invoice_id: obligationForm.invoice_id || null,
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

  const recognizeObligation = useMutation({
    mutationFn: async () => {
      if (!recognizeTarget || !recognizeInvoiceId) throw new Error('Select an issued invoice')
      await api.post(`/supplier-obligations/${recognizeTarget.id}/recognize`, { invoice_id: recognizeInvoiceId })
    },
    onSuccess: async () => {
      setRecognizeTarget(null)
      setRecognizeInvoiceId('')
      setError('')
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

  const cancelSupplierPayment = useMutation({
    mutationFn: async (reason: string) => {
      if (!cancelPayment) throw new Error('No supplier payment selected')
      await api.post(`/supplier-payments/${cancelPayment.id}/cancel`, { reason })
    },
    onSuccess: async () => {
      setCancelPayment(null)
      setCancelError('')
      await queryClient.invalidateQueries({ queryKey: ['supplier-payments'] })
      await queryClient.invalidateQueries({ queryKey: ['supplier-obligations'] })
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setCancelError(errorMessage(cause)),
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
    const picker = target === 'obligation' ? obligationSupplierPicker : paymentSupplierPicker
    const currency = picker.rows.find((item) => item.id === supplierId)?.settlement_currency ?? 'EGP'

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
        icon="handshake"
        actions={
          <>
            {can('supplier_obligations.create') ? (
              <Button variant="secondary" icon="plus" onClick={() => { setError(''); setObligationOpen(true) }}>
                {t('newObligation')}
              </Button>
            ) : null}
            {can('supplier_payments.create') ? (
              <Button icon="plus" onClick={() => startPayment()}>{t('newSupplierPayment')}</Button>
            ) : null}
          </>
        }
      />

      {error && !obligationOpen && !paymentOpen && !applyPayment ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <div className="mb-4 flex gap-2">
        <button
          className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
            tab === 'obligations' ? 'border-border-selected bg-surface-selected text-text-default' : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
          }`}
          onClick={() => setTab('obligations')}
        >
          {t('obligations')}
        </button>
        <button
          className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
            tab === 'payments' ? 'border-border-selected bg-surface-selected text-text-default' : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
          }`}
          onClick={() => setTab('payments')}
        >
          {t('supplierPayments')}
        </button>
      </div>

      {tab === 'obligations' ? (
        <Card>
          <div className="border-b border-border-subtle px-5 py-3 text-small text-text-subtle">
            {L('الالتزام يبدأ كسجل تشغيلي. يُعترف بالدين فقط عند ربطه بفاتورة مورد صادرة ومُرحّلة؛ لن ينشئ الربط قيدًا محاسبيًا ثانيًا.', 'Obligations start operational. AP is recognized only after linking an issued, posted supplier invoice; linking never posts a second journal.')}
          </div>
          {obligationsQuery.isLoading ? <Loading /> : null}
          {obligationsQuery.isError ? (
            <ErrorState message={errorMessage(obligationsQuery.error)} onRetry={() => void obligationsQuery.refetch()} />
          ) : null}
          {obligationsQuery.data && obligations.length === 0 ? <EmptyState /> : null}
          {obligations.length > 0 ? (
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
                {obligations.map((obligation) => (
                  <tr key={obligation.id}>
                    <Td>{supplierName(obligation.supplier)}</Td>
                    <Td dir="ltr">{obligation.invoice_reference ?? '—'}</Td>
                    <Td>
                      <Badge tone={statusTone(obligation.status)}>{statusLabel(obligation.status)}</Badge>
                      <div className="mt-1 text-small text-text-subtle">{obligation.accounting_status === 'recognized' ? L('مُعترف به محاسبيًا', 'Recognized in GL') : L('غير مُرحّل', 'Not posted')}</div>
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
                      {obligation.accounting_status !== 'recognized' && obligation.paid_minor === 0 && can('supplier_obligations.create') ? (
                        <Button variant="secondary" onClick={() => { setError(''); setRecognizeTarget(obligation); setRecognizeInvoiceId('') }}>
                          {L('ربط فاتورة مُرحّلة', 'Link posted invoice')}
                        </Button>
                      ) : null}
                      {obligation.accounting_status === 'recognized' && obligation.balance_minor > 0 && can('supplier_payments.create') ? (
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
          <Pagination
            page={obligationsQuery.data?.meta.current_page ?? 1}
            lastPage={obligationsQuery.data?.meta.last_page ?? 1}
            onChange={setObligationPage}
          />
        </Card>
      ) : (
        <Card>
          {paymentsQuery.isLoading ? <Loading /> : null}
          {paymentsQuery.isError ? (
            <ErrorState message={errorMessage(paymentsQuery.error)} onRetry={() => void paymentsQuery.refetch()} />
          ) : null}
          {paymentsQuery.data && payments.length === 0 ? <EmptyState /> : null}
          {payments.length > 0 ? (
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
                {payments.map((payment) => (
                  <tr key={payment.id}>
                    <Td>{supplierName(payment.supplier)}</Td>
                    <Td>{formatDate(payment.paid_at, locale)}</Td>
                    <Td>
                      <Badge tone={statusTone(payment.status)}>{statusLabelOf(payment.status, locale)}</Badge>
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
                      {payment.unallocated_minor > 0 && can('supplier_payments.create') && payment.supplier?.id ? (
                        <Button
                          variant="ghost"
                          onClick={() =>
                            setInvoiceTarget({
                              kind: 'supplier',
                              paymentId: payment.id,
                              paymentLabel: supplierName(payment.supplier),
                              partyId: payment.supplier?.id ?? '',
                              currency: payment.currency,
                              availableMinor: payment.unallocated_minor,
                            })
                          }
                        >
                          {locale === 'ar' ? 'تطبيق على فاتورة' : 'Apply to invoice'}
                        </Button>
                      ) : null}
                      {payment.status === 'posted' && can('supplier_payments.reverse') ? (
                        <Button
                          variant="ghost"
                          onClick={() => {
                            setCancelError('')
                            setCancelPayment(payment)
                          }}
                        >
                          {locale === 'ar' ? 'إلغاء الدفعة' : 'Cancel payment'}
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
            onChange={setPaymentPage}
          />
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
            <SearchableSelect
              value={obligationForm.supplier_id}
              onChange={(supplier_id) => onSupplierChange(supplier_id, 'obligation')}
              placeholder={t('selectSupplier')}
              options={obligationSupplierPicker.options}
              onSearch={obligationSupplierPicker.setSearch}
              hasMore={obligationSupplierPicker.hasMore}
            />
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
          <Field label={L('فاتورة مورد صادرة (اختياري)', 'Posted supplier invoice (optional)')}>
            <SearchableSelect
              value={obligationForm.invoice_id}
              onChange={(invoice_id) => setObligationForm({ ...obligationForm, invoice_id })}
              options={createInvoicePicker.options}
              onSearch={createInvoicePicker.setSearch}
              hasMore={createInvoicePicker.hasMore}
              placeholder={L('بدون ربط — التزام تشغيلي فقط', 'Unlinked — operational only')}
            />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <Modal
        open={recognizeTarget !== null}
        title={L('ربط التزام بفاتورة مُرحّلة', 'Link obligation to posted invoice')}
        onClose={() => setRecognizeTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRecognizeTarget(null)}>{t('cancel')}</Button>
            <Button disabled={!recognizeInvoiceId || recognizeObligation.isPending} onClick={() => recognizeObligation.mutate()}>
              {L('ربط دون إنشاء قيد جديد', 'Link without new posting')}
            </Button>
          </>
        }
      >
        <Field label={L('فاتورة المورد المعتمدة والمُصدرة', 'Issued supplier invoice')} required>
          <SearchableSelect
            value={recognizeInvoiceId}
            onChange={setRecognizeInvoiceId}
            options={recognizeInvoicePicker.options}
            onSearch={recognizeInvoicePicker.setSearch}
            hasMore={recognizeInvoicePicker.hasMore}
            placeholder={L('اختر فاتورة مطابقة', 'Choose matching invoice')}
          />
        </Field>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
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
            <SearchableSelect
              value={paymentForm.supplier_id}
              onChange={(supplier_id) => onSupplierChange(supplier_id, 'payment')}
              placeholder={t('selectSupplier')}
              options={paymentSupplierPicker.options}
              onSearch={paymentSupplierPicker.setSearch}
              hasMore={paymentSupplierPicker.hasMore}
            />
          </Field>
          <Field label={t('obligation')} hint={paymentForm.supplier_id && paymentObligations.length === 0 ? t('noOpenObligations') : undefined}>
            <SearchableSelect
              value={paymentForm.supplier_obligation_id}
              onChange={(supplier_obligation_id) => {
                const obligation = paymentObligations.find((item) => item.id === supplier_obligation_id)
                setPaymentForm({
                  ...paymentForm,
                  supplier_obligation_id,
                  currency: obligation?.currency ?? paymentForm.currency,
                  amount: obligation ? toDecimal(obligation.balance_minor, obligation.currency) : paymentForm.amount,
                })
              }}
              placeholder={t('advance')}
              clearLabel={t('advance')}
              options={paymentObligationPicker.options}
              onSearch={paymentObligationPicker.setSearch}
              hasMore={paymentObligationPicker.hasMore}
            />
          </Field>
          <Field label={locale === 'ar' ? 'حساب الخزينة/البنك' : 'Cash / bank account'} required>
            <SearchableSelect
              value={paymentForm.cash_account_id}
              onChange={(cash_account_id) => setPaymentForm({ ...paymentForm, cash_account_id })}
              placeholder={t('selectAccount')}
              options={(cashQuery.data ?? []).map((account) => ({
                value: account.id,
                label: `${locale === 'ar' ? account.name_ar : account.name_en} (${account.currency})`,
              }))}
            />
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
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
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
              <SearchableSelect
                value={applyForm.supplier_obligation_id}
                onChange={(supplier_obligation_id) => {
                  const obligation = openObligations.find((item) => item.id === supplier_obligation_id)
                  setApplyForm({
                    ...applyForm,
                    supplier_obligation_id,
                    amount: obligation
                      ? toDecimal(
                          Math.min(obligation.balance_minor, applyPayment.unallocated_minor),
                          obligation.currency,
                        )
                      : applyForm.amount,
                  })
                }}
                placeholder={t('selectObligation')}
                options={applyObligationPicker.options}
                onSearch={applyObligationPicker.setSearch}
                hasMore={applyObligationPicker.hasMore}
              />
            </Field>
            <Field label={t('amount')} required>
              <Input value={applyForm.amount} onChange={(event) => setApplyForm({ ...applyForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
          </div>
        ) : null}
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <ApplyToInvoiceModal target={invoiceTarget} onClose={() => setInvoiceTarget(null)} />

      <ReasonModal
        open={cancelPayment !== null}
        title={locale === 'ar' ? 'إلغاء دفعة المورد' : 'Cancel supplier payment'}
        description={
          locale === 'ar'
            ? 'سيتم عكس قيد الدفعة. لا يمكن الإلغاء قبل فك تطبيقها على الالتزامات أو الفواتير.'
            : 'The payment posting is reversed. Not possible until it is unapplied from obligations and invoices.'
        }
        confirmLabel={locale === 'ar' ? 'إلغاء الدفعة' : 'Cancel payment'}
        pending={cancelSupplierPayment.isPending}
        error={cancelError}
        onClose={() => setCancelPayment(null)}
        onConfirm={(reason) => cancelSupplierPayment.mutate(reason)}
      />
    </div>
  )
}
