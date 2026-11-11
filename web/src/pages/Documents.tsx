import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, formatDateTime, toMinor, todayISO } from '../lib/format'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  Input,
  Loading,
  Modal,
  Money,
  PageHeader,
  Pagination,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import { statusLabel } from '../lib/labels'

type DocType = 'receipt_voucher' | 'payment_voucher' | 'transfer_voucher' | 'adjustment_voucher' | 'refund_voucher'
type PartyType = 'customer' | 'supplier' | 'employee'
type Party = { id: string; name_ar: string; name_en: string }

type DocLine = {
  account: { code: string; name_ar: string; name_en: string } | null
  description: string | null
  debit_minor: number
  credit_minor: number
  tax_minor: number
  party_type: PartyType | null
  party_id: number | null
}

type Document = {
  id: string
  type: DocType
  series: string
  number: string
  party_type: PartyType | null
  party_id: number | null
  party: Party | null
  branch_id: string | null
  booking_id: number | null
  cash_account_id: string | null
  currency: string
  fx_rate: string
  fx_rate_date: string | null
  status: 'draft' | 'submitted' | 'approved' | 'posted' | 'voided' | 'rejected' | 'cancelled'
  subtotal_minor: number
  tax_minor: number
  total_minor: number
  journal_entry_id: string | null
  reversal_of_id: string | null
  reversal_reason: string | null
  memo: string | null
  submitted_at: string | null
  approved_at: string | null
  posted_at: string | null
  lines?: DocLine[]
}

type JournalEntry = {
  id: string
  number: string
  entry_date: string
  status: string
  memo: string | null
  currency: string
  total_minor: number
  lines?: Array<{
    account: { code: string; name_ar: string; name_en: string } | null
    description?: string | null
    debit_minor: number
    credit_minor: number
  }>
}

type CashAccount = { id: string; cash_account_id?: string; name_ar: string; name_en: string; currency: string; registered: boolean; is_active?: boolean }
type CurrencyRow = { code: string; is_active: boolean }

const DOC_TYPES: DocType[] = ['receipt_voucher', 'payment_voucher', 'transfer_voucher', 'adjustment_voucher', 'refund_voucher']

const typeLabels: Record<DocType, { ar: string; en: string }> = {
  receipt_voucher: { ar: 'سند قبض', en: 'Receipt voucher' },
  payment_voucher: { ar: 'سند صرف', en: 'Payment voucher' },
  transfer_voucher: { ar: 'سند تحويل داخلي', en: 'Transfer voucher' },
  adjustment_voucher: { ar: 'سند تسوية', en: 'Adjustment voucher' },
  refund_voucher: { ar: 'سند استرداد', en: 'Refund voucher' },
}

const partyLabels: Record<PartyType, { ar: string; en: string }> = {
  customer: { ar: 'عميل', en: 'Customer' },
  supplier: { ar: 'مورد', en: 'Supplier' },
  employee: { ar: 'موظف', en: 'Employee' },
}

const emptyLine = { account_id: '', description: '', debit: '', credit: '', tax: '' }

const emptyForm = {
  type: 'receipt_voucher' as DocType,
  party_type: '' as '' | PartyType,
  party_id: '',
  document_date: todayISO(),
  currency: 'EGP',
  fx_rate: '',
  cash_account_id: '',
  memo: '',
  amount: '',
  simpleMode: true,
  lines: [{ ...emptyLine }],
}

export default function Documents() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const partyLabel = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [page, setPage] = useState(1)
  const [typeFilter, setTypeFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm, party_type: 'customer' as '' | PartyType })
  const [detailId, setDetailId] = useState<string | null>(null)
  const [rejectId, setRejectId] = useState<string | null>(null)
  const [correctId, setCorrectId] = useState<string | null>(null)
  const [reason, setReason] = useState('')

  const documentsQuery = useQuery({
    queryKey: ['accounting-documents', page, typeFilter, statusFilter, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Document[]; meta: { current_page: number; last_page: number; total: number } }>('/accounting-documents', {
        params: {
          page,
          per_page: 20,
          ...(typeFilter ? { type: typeFilter } : {}),
          ...(statusFilter ? { status: statusFilter } : {}),
        },
      })
      return data
    },
  })

  const partiesQuery = useQuery({
    queryKey: ['parties', 'documents'],
    queryFn: async () => {
      const [customers, suppliers, employees] = await Promise.all([
        api.get<{ data: Party[] }>('/customers', { params: { per_page: 100 } }),
        api.get<{ data: Party[] }>('/suppliers', { params: { per_page: 100 } }),
        api.get<{ data: Party[] }>('/employees', { params: { per_page: 100 } }),
      ])
      return {
        customers: customers.data.data,
        suppliers: suppliers.data.data,
        employees: employees.data.data,
      }
    },
    enabled: open,
  })

  const accountsQuery = useQuery({
    queryKey: ['accounts', 'documents'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: string; code: string; name_ar: string; name_en: string; is_postable: boolean; is_active: boolean }> }>('/accounts')
      return data.data
    },
    enabled: open,
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies', 'documents'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: open,
  })

  const cashQuery = useQuery({
    queryKey: ['cash-accounts', 'documents'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
    enabled: open,
  })

  const detailQuery = useQuery({
    queryKey: ['accounting-document', detailId],
    queryFn: async () => {
      const { data } = await api.get<{ data: Document }>(`/accounting-documents/${detailId}`)
      return data.data
    },
    enabled: detailId !== null,
  })

  const journalQuery = useQuery({
    queryKey: ['journal-entry', detailQuery.data?.journal_entry_id],
    queryFn: async () => {
      const { data } = await api.get<{ data: JournalEntry }>(`/journal-entries/${detailQuery.data?.journal_entry_id}`)
      return data.data
    },
    enabled: detailQuery.data?.journal_entry_id != null,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['accounting-documents'] })
    await queryClient.invalidateQueries({ queryKey: ['accounting-document'] })
  }

  const partyOptions = (() => {
    const rows =
      form.party_type === 'customer'
        ? partiesQuery.data?.customers
        : form.party_type === 'supplier'
          ? partiesQuery.data?.suppliers
          : form.party_type === 'employee'
            ? partiesQuery.data?.employees
            : []
    return (rows ?? []).map((party) => ({ value: party.id, label: partyLabel(party.name_ar, party.name_en) }))
  })()

  const accountOptions = (accountsQuery.data ?? [])
    .filter((account) => account.is_postable && account.is_active)
    .map((account) => ({ value: account.id, label: `${account.code} — ${locale === 'ar' ? account.name_ar : account.name_en}` }))

  const cashOptions = (cashQuery.data ?? [])
    .filter((account) => account.registered && account.is_active !== false)
    .map((account) => ({ value: account.cash_account_id ?? account.id, label: locale === 'ar' ? account.name_ar : account.name_en }))

  const updateLine = (index: number, patch: Partial<typeof emptyLine>) =>
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }))

  const addLine = () => setForm((current) => ({ ...current, lines: [...current.lines, { ...emptyLine }] }))

  const removeLine = (index: number) =>
    setForm((current) => {
      const lines = current.lines.filter((_, i) => i !== index)
      return { ...current, lines: lines.length > 0 ? lines : [{ ...emptyLine }] }
    })

  const formTotals = form.lines.reduce(
    (totals, line) => ({
      debit: totals.debit + toMinor(line.debit || '0', form.currency),
      credit: totals.credit + toMinor(line.credit || '0', form.currency),
      tax: totals.tax + toMinor(line.tax || '0', form.currency),
    }),
    { debit: 0, credit: 0, tax: 0 },
  )
  const balanced = formTotals.debit === formTotals.credit && formTotals.debit > 0
  const supportsSimple = form.type === 'receipt_voucher' || form.type === 'payment_voucher'
  const simple = supportsSimple && form.simpleMode
  const expectedParty = form.type === 'receipt_voucher' ? 'customer' : 'supplier'
  const selectedCash = (cashQuery.data ?? []).find((account) => (account.cash_account_id ?? account.id) === form.cash_account_id)
  const canSaveSimple = Boolean(form.party_type === expectedParty && form.party_id && form.cash_account_id
    && selectedCash?.currency === form.currency && toMinor(form.amount || '0', form.currency) > 0)
  const canSave = simple ? canSaveSimple : balanced

  const create = useMutation({
    mutationFn: async (submit: boolean) => {
      await api.post(
        '/accounting-documents',
        {
          type: form.type,
          party_type: form.party_type || undefined,
          party_id: form.party_id || undefined,
          document_date: form.document_date,
          currency: form.currency,
          fx_rate: form.fx_rate || undefined,
          cash_account_id: form.cash_account_id || undefined,
          memo: form.memo || undefined,
          simple_mode: simple,
          ...(simple ? { amount_minor: toMinor(form.amount, form.currency) } : {}),
          ...(!simple ? { lines: form.lines
            .filter((line) => line.account_id)
            .map((line) => ({
              account_id: line.account_id,
              description: line.description || undefined,
              debit_minor: toMinor(line.debit || '0', form.currency),
              credit_minor: toMinor(line.credit || '0', form.currency),
              tax_minor: toMinor(line.tax || '0', form.currency),
            })) } : {}),
          submit,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setOpen(false)
      setForm({ ...emptyForm, party_type: 'customer' })
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const step = useMutation({
    mutationFn: async ({ id, path }: { id: string; path: 'submit' | 'approve' | 'post' | 'cancel' | 'reopen' }) => {
      await api.post(`/accounting-documents/${id}/${path}`, undefined, {
        headers: path === 'approve' || path === 'post' ? { 'Idempotency-Key': idempotencyKey() } : undefined,
      })
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const withReason = useMutation({
    mutationFn: async () => {
      if (!rejectId && !correctId) return
      if (rejectId) {
        await api.post(`/accounting-documents/${rejectId}/reject`, { reason })
      } else if (correctId) {
        await api.post(`/accounting-documents/${correctId}/correct`, { reason }, { headers: { 'Idempotency-Key': idempotencyKey() } })
      }
    },
    onSuccess: async () => {
      setRejectId(null)
      setCorrectId(null)
      setReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const data = documentsQuery.data
  const detail = detailQuery.data

  return (
    <div>
      <PageHeader
        title={L('السندات المحاسبية', 'Accounting vouchers')}
        subtitle={L('سندات القبض والصرف والتحويل والتسوية مع دورة اعتماد وترحيل', 'Receipt, payment, transfer and adjustment vouchers with approval and posting')}
        icon="clipboardCheck"
        actions={
          can('documents.create') ? (
            <Button icon="plus" onClick={() => { setError(''); setOpen(true) }}>{L('سند جديد', 'New voucher')}</Button>
          ) : null
        }
      />

      {error && !open && !detailId && !rejectId && !correctId ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
          <Field label={L('النوع', 'Type')}>
            <Select value={typeFilter} onChange={(event) => { setTypeFilter(event.target.value); setPage(1) }}>
              <option value="">{L('الكل', 'All')}</option>
              {DOC_TYPES.map((value) => (
                <option key={value} value={value}>{L(typeLabels[value].ar, typeLabels[value].en)}</option>
              ))}
            </Select>
          </Field>
          <Field label={t('status')}>
            <Select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }}>
              <option value="">{L('الكل', 'All')}</option>
              {['draft', 'submitted', 'approved', 'posted', 'rejected', 'cancelled'].map((value) => (
                <option key={value} value={value}>{value}</option>
              ))}
            </Select>
          </Field>
        </div>

        {documentsQuery.isLoading ? <Loading /> : null}
        {documentsQuery.isError ? <ErrorState message={errorMessage(documentsQuery.error)} onRetry={() => void documentsQuery.refetch()} /> : null}
        {data && data.data.length === 0 ? <EmptyState /> : null}
        {data && data.data.length > 0 ? (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>{t('date')}</Th>
                  <Th>{L('الرقم', 'No.')}</Th>
                  <Th>{L('النوع', 'Type')}</Th>
                  <Th>{L('الطرف', 'Party')}</Th>
                  <Th>{t('memo')}</Th>
                  <Th className="text-end">{t('total')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((document) => (
                  <tr key={document.id}>
                    <Td className="tabular">{formatDate(document.fx_rate_date, locale)}</Td>
                    <Td className="tabular">{document.number}</Td>
                    <Td>
                      <Badge tone="brand">{L(typeLabels[document.type].ar, typeLabels[document.type].en)}</Badge>
                    </Td>
                    <Td>
                      {document.party ? partyLabel(document.party.name_ar, document.party.name_en) : '—'}
                      {document.party_type ? (
                        <div className="text-small text-text-subtle">
                          {L(partyLabels[document.party_type].ar, partyLabels[document.party_type].en)}
                        </div>
                      ) : null}
                    </Td>
                    <Td>
                      {document.memo ?? '—'}
                      {document.reversal_reason ? <div className="text-small text-text-subtle">{document.reversal_reason}</div> : null}
                    </Td>
                    <Td className="text-end"><Money amountMinor={document.total_minor} currency={document.currency} /></Td>
                    <Td><Badge tone={statusTone(document.status)}>{statusLabel(document.status, locale)}</Badge></Td>
                    <Td className="text-end">
                      <Button variant="ghost" onClick={() => setDetailId(document.id)}>{L('تفاصيل', 'Details')}</Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onChange={setPage} />
          </>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={L('سند جديد', 'New voucher')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <span className={canSave ? 'me-auto text-sm text-success-text' : 'me-auto text-sm text-danger-text'}>
              {simple ? L('تسوية رصيد قائم', 'Settle existing balance') : `${t('debit')}: ${formTotals.debit / 100} · ${t('credit')}: ${formTotals.credit / 100}`}
            </span>
            <Button variant="secondary" onClick={() => setOpen(false)}>{t('cancel')}</Button>
            <Button variant="secondary" onClick={() => create.mutate(false)} disabled={create.isPending || !canSave}>
              {L('حفظ كمسودة', 'Save draft')}
            </Button>
            <Button onClick={() => create.mutate(true)} disabled={create.isPending || !canSave}>
              {L('حفظ وإرسال', 'Save & submit')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('النوع', 'Type')} required>
            <Select value={form.type} onChange={(event) => {
              const type = event.target.value as DocType
              setForm({ ...form, type, party_type: type === 'receipt_voucher' ? 'customer' : type === 'payment_voucher' ? 'supplier' : '', party_id: '', cash_account_id: '', amount: '' })
            }}>
              {DOC_TYPES.map((value) => (
                <option key={value} value={value}>{L(typeLabels[value].ar, typeLabels[value].en)}</option>
              ))}
            </Select>
          </Field>
          {supportsSimple ? (
            <div className="sm:col-span-2">
              <label className="flex items-center gap-2 text-small">
                <input type="checkbox" checked={!simple} onChange={(event) => setForm({ ...form, simpleMode: !event.target.checked })} />
                {L('وضع محاسبي متقدم (مدين / دائن)', 'Advanced accounting mode (debit / credit)')}
              </label>
              {simple ? <p className="mt-2 text-small text-text-subtle">{L('لتسوية ذمم عميل أو مورد قائمة فقط. للدفعات المقدمة والاستردادات استخدم العملية المخصصة أو الوضع المتقدم.', 'Only for settling existing customer/supplier balances. Use the dedicated flow or advanced mode for advances and refunds.')}</p> : null}
            </div>
          ) : null}
          {simple ? null : <Field label={L('نوع الطرف', 'Party type')}>
            <Select
              value={form.party_type}
              onChange={(event) => setForm({ ...form, party_type: event.target.value as '' | PartyType, party_id: '' })}
            >
              <option value="">—</option>
              {(['customer', 'supplier', 'employee'] as PartyType[]).map((value) => (
                <option key={value} value={value}>{L(partyLabels[value].ar, partyLabels[value].en)}</option>
              ))}
            </Select>
          </Field>}
          {simple ? <Field label={form.type === 'receipt_voucher' ? L('العميل', 'Customer') : L('المورد', 'Supplier')} required>
            <SearchableSelect
              value={form.party_id}
              onChange={(value) => setForm({ ...form, party_type: expectedParty, party_id: value })}
              options={((expectedParty === 'customer' ? partiesQuery.data?.customers : partiesQuery.data?.suppliers) ?? []).map((party) => ({ value: party.id, label: partyLabel(party.name_ar, party.name_en) }))}
              placeholder={L('اختر', 'Select')}
            />
          </Field> : null}
          {!simple && form.party_type ? (
            <Field label={L('الطرف', 'Party')}>
              <SearchableSelect
                value={form.party_id}
                onChange={(value) => setForm({ ...form, party_id: value })}
                placeholder={L('اختر الطرف', 'Select party')}
                options={partyOptions}
              />
            </Field>
          ) : null}
          <Field label={t('date')} required>
            <Input type="date" value={form.document_date} onChange={(event) => setForm({ ...form, document_date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
              {(currenciesQuery.data ?? [{ code: 'EGP', is_active: true }])
                .filter((row) => row.is_active)
                .map((row) => (
                  <option key={row.code} value={row.code}>{row.code}</option>
                ))}
            </Select>
          </Field>
          <Field label={L('سعر الصرف (للعملات الأجنبية)', 'Exchange rate (foreign currency)')}>
            <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={simple ? L('الخزينة أو البنك', 'Cash or bank') : L('حساب النقدية (اختياري)', 'Cash account (optional)')}>
            <SearchableSelect
              value={form.cash_account_id}
              onChange={(value) => setForm({ ...form, cash_account_id: value })}
              placeholder={L('اختر', 'Select')}
              options={simple ? cashOptions.filter((option) => (cashQuery.data ?? []).some((account) => (account.cash_account_id ?? account.id) === option.value && account.currency === form.currency)) : cashOptions}
            />
          </Field>
          <Field label={t('memo')}>
            <Input value={form.memo} onChange={(event) => setForm({ ...form, memo: event.target.value })} />
          </Field>
        </div>

        {simple ? (
          <div className="mt-4">
            <Field label={L('مبلغ التسوية', 'Settlement amount')} required>
              <Input value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
          </div>
        ) : (
        <div className="mt-4 border-t border-border-subtle pt-4">
          <h3 className="text-medium mb-3">{L('بنود السند', 'Voucher lines')}</h3>
          <div className="space-y-2">
            {form.lines.map((line, index) => (
              <div key={index} className="grid gap-2 rounded-12 border border-border-default p-3 sm:grid-cols-12">
                <div className="sm:col-span-4">
                  <SearchableSelect
                    value={line.account_id}
                    onChange={(value) => updateLine(index, { account_id: value })}
                    options={accountOptions}
                    placeholder={t('selectAccount')}
                  />
                </div>
                <div className="sm:col-span-3">
                  <Input
                    placeholder={t('description')}
                    value={line.description}
                    onChange={(event) => updateLine(index, { description: event.target.value })}
                  />
                </div>
                <div className="sm:col-span-2">
                  <Input
                    placeholder={t('debit')}
                    value={line.debit}
                    onChange={(event) => updateLine(index, { debit: event.target.value, credit: '' })}
                    inputMode="decimal"
                    dir="ltr"
                  />
                </div>
                <div className="sm:col-span-2">
                  <Input
                    placeholder={t('credit')}
                    value={line.credit}
                    onChange={(event) => updateLine(index, { credit: event.target.value, debit: '' })}
                    inputMode="decimal"
                    dir="ltr"
                  />
                </div>
                <div className="flex justify-end sm:col-span-1">
                  <IconButton icon="x" label={t('removeLine')} onClick={() => removeLine(index)} />
                </div>
              </div>
            ))}
          </div>
          <div className="mt-2 flex justify-end">
            <Button variant="secondary" onClick={addLine}>{L('إضافة بند', 'Add line')}</Button>
          </div>
        </div>)}
      </Modal>

      <Modal
        open={detailId !== null}
        title={L('تفاصيل السند', 'Voucher details')}
        onClose={() => setDetailId(null)}
        footer={
          <>
            <Button variant="secondary" className="no-print" onClick={() => window.print()}>{L('طباعة', 'Print')}</Button>
            <Button variant="secondary" className="no-print" onClick={() => setDetailId(null)}>{L('إغلاق', 'Close')}</Button>
          </>
        }
      >
        {detailQuery.isLoading ? <Loading /> : null}
        {detailQuery.isError ? <ErrorState message={errorMessage(detailQuery.error)} /> : null}
        {detail ? (
          <div>
            <div className="mb-4 flex flex-wrap items-center gap-3 border-b border-border-subtle pb-4">
              <div>
                <p className="text-medium font-semibold">{detail.number}</p>
                <p className="text-small text-text-subtle">
                  {L(typeLabels[detail.type].ar, typeLabels[detail.type].en)} — {detail.currency}
                  {detail.fx_rate !== '1' ? ` · ${L('سعر الصرف', 'FX rate')} ${detail.fx_rate}` : ''}
                </p>
              </div>
              <div className="ms-auto flex flex-wrap items-center gap-2">
                <Badge tone={statusTone(detail.status)}>{statusLabel(detail.status, locale)}</Badge>
                {detail.status === 'draft' && can('documents.create') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ id: detail.id, path: 'submit' })}>{L('إرسال', 'Submit')}</Button>
                ) : null}
                {detail.status === 'submitted' && can('documents.approve') ? (
                  <>
                    <Button variant="ghost" onClick={() => step.mutate({ id: detail.id, path: 'approve' })}>{L('اعتماد', 'Approve')}</Button>
                    <Button variant="ghost" onClick={() => { setError(''); setRejectId(detail.id) }}>{L('رفض', 'Reject')}</Button>
                  </>
                ) : null}
                {detail.status === 'rejected' && can('documents.create') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ id: detail.id, path: 'reopen' })}>{L('إعادة فتح', 'Reopen')}</Button>
                ) : null}
                {detail.status === 'approved' && can('documents.approve') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ id: detail.id, path: 'post' })}>{t('post')}</Button>
                ) : null}
                {['draft', 'submitted'].includes(detail.status) && can('documents.create') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ id: detail.id, path: 'cancel' })}>{L('إلغاء', 'Cancel')}</Button>
                ) : null}
                {detail.status === 'posted' && can('documents.approve') ? (
                  <Button variant="ghost" onClick={() => { setError(''); setCorrectId(detail.id) }}>{L('سند عكسي', 'Reverse')}</Button>
                ) : null}
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3 text-small text-text-subtle">
              <div>
                <p className="text-text-default">{L('التاريخ', 'Date')}</p>
                <p className="tabular">{formatDate(detail.fx_rate_date, locale)}</p>
              </div>
              <div>
                <p className="text-text-default">{L('الطرف', 'Party')}</p>
                <p>
                  {detail.party
                    ? partyLabel(detail.party.name_ar, detail.party.name_en)
                    : detail.party_type
                      ? L(partyLabels[detail.party_type].ar, partyLabels[detail.party_type].en)
                      : '—'}
                </p>
              </div>
              <div>
                <p className="text-text-default">{L('الرقم التسلسلي', 'Series')}</p>
                <p className="tabular">{detail.series}</p>
              </div>
              <div>
                <p className="text-text-default">{L('المجموع الفرعي', 'Subtotal')}</p>
                <p className="tabular"><Money amountMinor={detail.subtotal_minor} currency={detail.currency} /></p>
              </div>
              <div>
                <p className="text-text-default">{L('الضريبة (بيانات فقط)', 'Tax (metadata)')}</p>
                <p className="tabular"><Money amountMinor={detail.tax_minor} currency={detail.currency} /></p>
              </div>
              <div>
                <p className="text-text-default">{L('الإجمالي', 'Total')}</p>
                <p className="tabular font-semibold"><Money amountMinor={detail.total_minor} currency={detail.currency} /></p>
              </div>
            </div>

            {detail.memo ? <p className="text-small mt-3 text-text-subtle">{t('memo')}: {detail.memo}</p> : null}

            <div className="mt-4">
              <h3 className="text-medium mb-2">{L('بنود السند', 'Voucher lines')}</h3>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('account')}</Th>
                    <Th>{t('description')}</Th>
                    <Th className="text-end">{t('debit')}</Th>
                    <Th className="text-end">{t('credit')}</Th>
                    <Th className="text-end">{t('tax')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {(detail.lines ?? []).map((line, index) => (
                    <tr key={index}>
                      <Td>{line.account?.code} {locale === 'ar' ? line.account?.name_ar : line.account?.name_en}</Td>
                      <Td>{line.description}</Td>
                      <Td className="text-end"><Money amountMinor={line.debit_minor} currency={detail.currency} /></Td>
                      <Td className="text-end"><Money amountMinor={line.credit_minor} currency={detail.currency} /></Td>
                      <Td className="text-end"><Money amountMinor={line.tax_minor} currency={detail.currency} /></Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </div>

            {journalQuery.data ? (
              <div className="mt-4 border-t border-border-subtle pt-4">
                <h3 className="text-medium mb-2">
                  {L('القيد المحاسبي', 'GL entry')} —{' '}
                  <Link className="text-brand-1-accent underline underline-offset-4" to="/journal">
                    {journalQuery.data.number}
                  </Link>
                </h3>
                <p className="text-small mb-2 text-text-subtle">
                  {formatDate(journalQuery.data.entry_date, locale)} · {journalQuery.data.memo ?? '—'}
                </p>
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('account')}</Th>
                      <Th>{t('description')}</Th>
                      <Th className="text-end">{t('debit')}</Th>
                      <Th className="text-end">{t('credit')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {(journalQuery.data.lines ?? []).map((line, index) => (
                      <tr key={index}>
                        <Td>{line.account?.code} {locale === 'ar' ? line.account?.name_ar : line.account?.name_en}</Td>
                        <Td>{line.description}</Td>
                        <Td className="text-end"><Money amountMinor={line.debit_minor} currency={detail.currency} /></Td>
                        <Td className="text-end"><Money amountMinor={line.credit_minor} currency={detail.currency} /></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </div>
            ) : null}

            {detail.reversal_of_id ? (
              <p className="text-small mt-3 text-text-subtle">
                {L('سند عكسي للمستند', 'Reversal of document')} {detail.reversal_of_id}
                {detail.reversal_reason ? ` — ${detail.reversal_reason}` : ''}
              </p>
            ) : null}

            <div className="text-small mt-4 border-t border-border-subtle pt-4 text-text-subtle">
              <h3 className="text-medium mb-2 text-text-default">{L('سجل الاعتماد', 'Audit timeline')}</h3>
              <ul className="space-y-1">
                <li>{L('إرسال', 'Submitted')}: {formatDateTime(detail.submitted_at, locale)}</li>
                <li>{L('اعتماد', 'Approved')}: {formatDateTime(detail.approved_at, locale)}</li>
                <li>{L('ترحيل', 'Posted')}: {formatDateTime(detail.posted_at, locale)}</li>
              </ul>
            </div>
          </div>
        ) : null}
      </Modal>

      <Modal
        open={rejectId !== null || correctId !== null}
        title={rejectId ? L('رفض السند', 'Reject voucher') : L('سند عكسي', 'Reverse voucher')}
        onClose={() => { setRejectId(null); setCorrectId(null) }}
        footer={
          <>
            <Button variant="secondary" onClick={() => { setRejectId(null); setCorrectId(null) }}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => withReason.mutate()} disabled={withReason.isPending || reason.trim() === ''}>
              {rejectId ? L('رفض', 'Reject') : L('إنشاء سند عكسي', 'Create reversal')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-3 text-medium text-text-subtle">
          {rejectId
            ? L('سيتم رفض السند مع تسجيل السبب.', 'The voucher will be rejected and the reason recorded.')
            : L('سيُنشأ سند عكسي مرتبط يُرسل للاعتماد قبل الترحيل.', 'A linked reversal voucher will be created and submitted for approval.')}
        </p>
        <Field label={L('السبب', 'Reason')} required>
          <Input value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
    </div>
  )
}
