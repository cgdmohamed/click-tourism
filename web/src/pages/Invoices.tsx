import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import { useTaxEnabled } from '../lib/useSetting'
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
import { usePartyOptions } from '../lib/usePartyOptions'
import CreditLimitNotice, { type CreditWarning } from '../components/CreditLimitNotice'

type Party = { id: string; name_ar: string; name_en: string }
type InvoiceLine = {
  account: { code: string; name_ar: string; name_en: string } | null
  description: string | null
  quantity?: number
  unit_price_minor?: number | null
  debit_minor: number
  credit_minor: number
  tax_minor: number
}
type Invoice = {
  id: string
  type: 'customer_invoice' | 'supplier_invoice' | 'credit_note'
  series: string
  number: string
  party_type: 'customer' | 'supplier' | null
  party_id: string | null
  party: Party | null
  issue_date: string
  due_date: string | null
  currency: string
  status: 'draft' | 'submitted' | 'approved' | 'issued' | 'voided'
  subtotal_minor: number
  tax_minor: number
  total_minor: number
  outstanding_minor: number
  memo: string | null
  lines: InvoiceLine[]
}
type CurrencyRow = { code: string; is_active: boolean }

const emptyLine = {
  account_id: '',
  description: '',
  quantity: '1',
  unit_price: '',
  debit: '',
  credit: '',
  tax: '',
  tax_account_id: '',
}
const emptyForm = {
  type: 'customer_invoice' as 'customer_invoice' | 'supplier_invoice',
  party_type: 'customer' as 'customer' | 'supplier',
  party_id: '',
  issue_date: todayISO(),
  due_date: '',
  currency: 'EGP',
  memo: '',
  lines: [emptyLine],
  submit: false,
}

export default function Invoices() {
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
  const [simpleMode, setSimpleMode] = useState(true)
  const [detailId, setDetailId] = useState<string | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [creditId, setCreditId] = useState<string | null>(null)
  const [creditAmount, setCreditAmount] = useState('')
  const [creditReason, setCreditReason] = useState('')
  const [creditWarnings, setCreditWarnings] = useState<CreditWarning[]>([])
  const [overrideReason, setOverrideReason] = useState('')

  const invoicesQuery = useQuery({
    queryKey: ['invoices', page, typeFilter, statusFilter, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Invoice[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }>('/invoices', {
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

  const accountsQuery = useQuery({
    queryKey: ['accounts', 'invoices'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: string; code: string; name_ar: string; name_en: string; type: string; is_postable: boolean; is_active: boolean }> }>('/accounts')
      return data.data
    },
    enabled: open,
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies', 'invoices'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: open,
  })

  const detailQuery = useQuery({
    queryKey: ['invoice', detailId],
    queryFn: async () => {
      const { data } = await api.get<{ data: Invoice }>(`/invoices/${detailId}`)
      return data.data
    },
    enabled: detailId !== null,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['invoices'] })
    await queryClient.invalidateQueries({ queryKey: ['invoice'] })
  }

  const accountOptions = (accountsQuery.data ?? [])
    .filter((a) => a.is_postable && a.is_active)
    .map((a) => ({ value: a.id, label: `${a.code} — ${locale === 'ar' ? a.name_ar : a.name_en}` }))
  const defaultRevenueAccount = (accountsQuery.data ?? [])
    .find((a) => a.code === '4100' && a.is_active && a.is_postable)
    ?? (accountsQuery.data ?? []).find((a) => a.type === 'revenue' && a.is_active && a.is_postable)
  const defaultCostAccount = (accountsQuery.data ?? [])
    .find((a) => a.code === '5100' && a.is_active && a.is_postable)
    ?? (accountsQuery.data ?? []).find((a) => a.type === 'expense' && a.is_active && a.is_postable)
  const defaultLineAccount = form.type === 'customer_invoice' ? defaultRevenueAccount : defaultCostAccount

  const data = invoicesQuery.data
  const isCustomer = form.type === 'customer_invoice'
  const partyPicker = usePartyOptions(isCustomer ? 'customer' : 'supplier', {
    enabled: open,
    value: form.party_id,
    locale,
  })
  const partyOptions = partyPicker.options

  const updateLine = (index: number, patch: Partial<typeof emptyLine>) =>
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }))

  const addLine = () =>
    setForm((current) => ({ ...current, lines: [...current.lines, { ...emptyLine }] }))

  const removeLine = (index: number) =>
    setForm((current) => {
      const lines = current.lines.filter((_, i) => i !== index)
      return { ...current, lines: lines.length > 0 ? lines : [{ ...emptyLine }] }
    })

  const lineSubtotal = (line: typeof emptyLine) =>
    simpleMode
      ? (Number(line.quantity) || 0) * toMinor(line.unit_price || '0', form.currency)
      : toMinor(line.debit || '0', form.currency) + toMinor(line.credit || '0', form.currency)

  const { enabled: taxEnabled } = useTaxEnabled()
  const lineTax = (line: { tax: string }) => (taxEnabled ? toMinor(line.tax || '0', form.currency) : 0)

  const formSubtotal = form.lines.reduce((sum, line) => sum + lineSubtotal(line), 0)
  const formTax = form.lines.reduce((sum, line) => sum + lineTax(line), 0)
  const formTotal = formSubtotal + formTax

  const create = useMutation({
    mutationFn: async (submit: boolean) => {
      if (!form.party_id) throw new Error(L('اختر العميل أو المورد أولاً', 'Select a customer or supplier'))
      if (simpleMode && !defaultLineAccount) {
        throw new Error(L('لا يوجد حساب إيراد أو تكلفة مفعل. راجع إعدادات دليل الحسابات.', 'No active revenue or cost account is configured.'))
      }
      const lines = form.lines.map((line) => {
        if (simpleMode) {
          const quantity = Number(line.quantity)
          const unitPrice = toMinor(line.unit_price || '0', form.currency)
          if (!line.description.trim() || !Number.isSafeInteger(quantity) || quantity < 1 || unitPrice <= 0) {
            throw new Error(L('أدخل وصف وكمية وسعر صحيح لكل بند.', 'Enter a valid description, quantity and price for each line.'))
          }
          const amount = quantity * unitPrice
          if (!Number.isSafeInteger(amount)) throw new Error(L('المبلغ كبير جدًا', 'Amount is too large'))
          return {
            account_id: defaultLineAccount!.id,
            description: line.description,
            quantity,
            unit_price_minor: unitPrice,
            debit_minor: form.type === 'supplier_invoice' ? amount : 0,
            credit_minor: form.type === 'customer_invoice' ? amount : 0,
            tax_minor: lineTax(line),
          }
        }
        if (!line.account_id) throw new Error(L('اختر حساب كل بند', 'Select an account for every line'))
        return {
          account_id: line.account_id,
          description: line.description || undefined,
          debit_minor: toMinor(line.debit || '0', form.currency),
          credit_minor: toMinor(line.credit || '0', form.currency),
          tax_minor: lineTax(line),
          tax_account_id: line.tax_account_id || undefined,
        }
      })
      await api.post(
        '/invoices',
        {
          type: form.type,
          party_type: form.party_type,
          party_id: form.party_id,
          issue_date: form.issue_date,
          due_date: form.due_date || undefined,
          currency: form.currency,
          memo: form.memo || undefined,
          lines,
          submit,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setOpen(false)
      setForm(emptyForm)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const step = useMutation({
    mutationFn: async ({ invoice, path }: { invoice: Invoice; path: 'submit' | 'approve' | 'issue' }) => {
      const override = path === 'issue' && can('bookings.override_credit_limit') && overrideReason.trim()
        ? { override_reason: overrideReason.trim() }
        : undefined
      const { data } = await api.post<{ data: { warnings?: CreditWarning[] } }>(
        `/invoices/${invoice.id}/${path}`,
        override,
        { headers: path === 'approve' || path === 'issue' ? { 'Idempotency-Key': idempotencyKey() } : undefined },
      )
      return data.data.warnings ?? []
    },
    onSuccess: async (warnings) => {
      setError('')
      setCreditWarnings(warnings)
      if (warnings.length === 0) setOverrideReason('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const voidInvoice = useMutation({
    mutationFn: async (invoice: Invoice) => {
      await api.post(`/invoices/${invoice.id}/void`)
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const creditNote = useMutation({
    mutationFn: async () => {
      if (!creditId) return
      await api.post(`/invoices/${creditId}/credit-note`, {
        total_minor: creditAmount ? toMinor(creditAmount, 'EGP') : undefined,
        memo: creditReason || undefined,
      })
    },
    onSuccess: async () => {
      setCreditId(null)
      setCreditAmount('')
      setCreditReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const baseCls = 'rounded-12 border px-4 py-2 text-sm font-medium transition'
  const activeCls = 'border-border-selected bg-surface-selected text-text-default'
  const inactiveCls = 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
  const tabButton = (label: string, active: boolean) => (
    <button className={`${baseCls} ${active ? activeCls : inactiveCls}`}>{label}</button>
  )

  return (
    <div>
      <PageHeader
        title={L('الفواتير', 'Invoices')}
        subtitle={L('فواتير العملاء والموردين مع دورة حياة المسودة حتى الإصدار', 'Customer and supplier invoices, from draft to issued')}
        icon="fileText"
        actions={
          can('invoices.create') ? (
            <Button icon="plus" onClick={() => { setError(''); setOpen(true) }}>{L('فاتورة جديدة', 'New invoice')}</Button>
          ) : null
        }
      />

      {error && !open && !detailId && !creditId ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <div className="mb-4 flex flex-wrap gap-2">
        {tabButton(L('الكل', 'All'), !typeFilter && !statusFilter)}
        <button
          onClick={() => { setTypeFilter(''); setStatusFilter(''); setPage(1) }}
          className="text-small text-text-subtle underline underline-offset-4 hover:text-text-default"
        >
          {t('reset')}
        </button>
      </div>

      <Card>
        {invoicesQuery.isLoading ? <Loading /> : null}
        {invoicesQuery.isError ? <ErrorState message={errorMessage(invoicesQuery.error)} onRetry={() => void invoicesQuery.refetch()} /> : null}
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
                  <Th className="text-end">{t('total')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((invoice) => (
                  <tr key={invoice.id}>
                    <Td className="tabular">{formatDate(invoice.issue_date, locale)}</Td>
                    <Td className="tabular">
                      <Link to={`/invoices/${invoice.id}`} className="font-medium text-brand-1-accent hover:underline">
                        {invoice.number}
                      </Link>
                    </Td>
                    <Td>
                      <Badge tone="brand">
                        {invoice.type === 'credit_note' ? L('إشعار دائن', 'Credit note') : invoice.type === 'customer_invoice' ? L('عميل', 'Customer') : L('مورد', 'Supplier')}
                      </Badge>
                    </Td>
                    <Td>{partyLabel(invoice.party?.name_ar ?? '', invoice.party?.name_en ?? '')}</Td>
                    <Td className="text-end"><Money amountMinor={invoice.total_minor} currency={invoice.currency} /></Td>
                    <Td><Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge></Td>
                    <Td className="text-end">
                      <Button variant="ghost" onClick={() => { setError(''); setCreditWarnings([]); setOverrideReason(''); setDetailId(invoice.id) }}>{L('تفاصيل', 'Details')}</Button>
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
        title={L('فاتورة جديدة', 'New invoice')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>{t('cancel')}</Button>
            <Button variant="secondary" onClick={() => create.mutate(false)} disabled={create.isPending}>
              {L('مسودة', 'Draft')}
            </Button>
            <Button onClick={() => create.mutate(true)} disabled={create.isPending}>
              {L('إرسال للاعتماد', 'Submit for approval')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('النوع', 'Type')} required>
            <Select
              value={form.type}
              onChange={(event) =>
                setForm({
                  ...form,
                  type: event.target.value as 'customer_invoice' | 'supplier_invoice',
                  party_type: event.target.value === 'customer_invoice' ? 'customer' : 'supplier',
                  party_id: '',
                })
              }
            >
              <option value="customer_invoice">{L('فاتورة عميل', 'Customer invoice')}</option>
              <option value="supplier_invoice">{L('فاتورة مورد', 'Supplier invoice')}</option>
            </Select>
          </Field>
          <Field label={L('الطرف', 'Party')} required>
            <SearchableSelect
              value={form.party_id}
              onChange={(value) => setForm({ ...form, party_id: value })}
              placeholder={L('اختر الطرف', 'Select party')}
              options={partyOptions}
              onSearch={partyPicker.setSearch}
            />
          </Field>
          <Field label={t('date')} required>
            <Input
              type="date"
              value={form.issue_date}
              onChange={(event) => setForm({ ...form, issue_date: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field label={L('تاريخ الاستحقاق', 'Due date')}>
            <Input
              type="date"
              value={form.due_date}
              onChange={(event) => setForm({ ...form, due_date: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
              {(currenciesQuery.data ?? [{ code: 'EGP', is_active: true }])
                .filter((row) => row.is_active)
                .map((row) => (
                  <option key={row.code} value={row.code}>
                    {row.code}
                  </option>
                ))}
            </Select>
          </Field>
          <Field label={t('memo')}>
            <Input value={form.memo} onChange={(event) => setForm({ ...form, memo: event.target.value })} />
          </Field>
        </div>

        <div className="mt-4 border-t border-border-subtle pt-4">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-medium font-semibold">{L('بنود الفاتورة', 'Line items')}</h3>
            <label className="flex items-center gap-2 text-small text-text-subtle">
              <input type="checkbox" checked={!simpleMode} onChange={(event) => {
                setSimpleMode(!event.target.checked)
                setForm((current) => ({ ...current, lines: [{ ...emptyLine }] }))
              }} />
              {L('وضع محاسبي متقدم (مدين / دائن)', 'Advanced accounting mode (debit / credit)')}
            </label>
          </div>
          {simpleMode ? (
            <p className="mb-3 text-small text-text-subtle">
              {L('أدخل الخدمة والكمية والسعر. سيتم اختيار حساب الإيراد أو التكلفة المناسب تلقائيًا.', 'Enter the service, quantity, and price. The configured revenue or cost account will be selected automatically.')}
            </p>
          ) : null}
          <div className="space-y-2">
            {form.lines.map((line, index) => (
              <div key={index} className="grid gap-2 rounded-12 border border-border-default p-3 sm:grid-cols-12">
                {simpleMode ? (
                  <>
                    <div className="sm:col-span-5">
                      <Field label={L('الخدمة أو الوصف', 'Service / description')} required>
                        <Input
                          value={line.description}
                          onChange={(event) => updateLine(index, { description: event.target.value })}
                          placeholder={L('مثال: حجز فندق', 'e.g. Hotel booking')}
                        />
                      </Field>
                    </div>
                    <div className="sm:col-span-2">
                      <Field label={L('الكمية', 'Quantity')} required>
                        <Input
                          value={line.quantity}
                          onChange={(event) => updateLine(index, { quantity: event.target.value })}
                          type="number"
                          min="1"
                          step="1"
                          dir="ltr"
                        />
                      </Field>
                    </div>
                    <div className="sm:col-span-2">
                      <Field label={L('سعر الوحدة', 'Unit price')} required>
                        <Input
                          value={line.unit_price}
                          onChange={(event) => updateLine(index, { unit_price: event.target.value })}
                          inputMode="decimal"
                          dir="ltr"
                        />
                      </Field>
                    </div>
                    <div className="sm:col-span-2">
                      <Field label={L('الضريبة (مبلغ)', 'Tax amount')}>
                        <Input
                          value={line.tax}
                          onChange={(event) => updateLine(index, { tax: event.target.value })}
                          inputMode="decimal"
                          disabled={!taxEnabled}
                          dir="ltr"
                        />
                      </Field>
                    </div>
                  </>
                ) : (
                  <>
                    <div className="sm:col-span-4">
                      <SearchableSelect
                        value={line.account_id}
                        onChange={(value) => updateLine(index, { account_id: value })}
                        options={accountOptions}
                        placeholder={t('selectAccount')}
                      />
                    </div>
                    <div className="sm:col-span-2">
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
                        inputMode="decimal" dir="ltr"
                      />
                    </div>
                    <div className="sm:col-span-2">
                      <Input
                        placeholder={t('credit')}
                        value={line.credit}
                        onChange={(event) => updateLine(index, { credit: event.target.value, debit: '' })}
                        inputMode="decimal" dir="ltr"
                      />
                    </div>
                    <div className="sm:col-span-1">
                      <Input
                        placeholder={t('tax')}
                        value={line.tax}
                        onChange={(event) => updateLine(index, { tax: event.target.value })}
                        disabled={!taxEnabled}
                        inputMode="decimal" dir="ltr"
                      />
                    </div>
                  </>
                )}
                <div className="flex items-end justify-end sm:col-span-1">
                  <IconButton icon="x" label={t('removeLine')} onClick={() => removeLine(index)} />
                </div>
              </div>
            ))}
          </div>
          {!taxEnabled ? (
            <p className="mt-2 text-small text-text-subtle">{L('الضريبة معطّلة في الإعدادات.', 'Tax is disabled in settings.')}</p>
          ) : null}
          <div className="mt-2 flex justify-end">
            <Button variant="secondary" onClick={addLine}>{L('إضافة بند', 'Add line')}</Button>
          </div>
          <div className="mt-3 flex justify-end text-medium tabular">
            <div className="space-y-1">
              <span>
                {L('المجموع الفرعي', 'Subtotal')}: <Money amountMinor={formSubtotal} currency={form.currency} />
              </span>
              <span>{L('الضريبة', 'Tax')}: <Money amountMinor={formTax} currency={form.currency} /></span>
              <span className="font-semibold">
                {L('المجموع', 'Total')}: <Money amountMinor={formTotal} currency={form.currency} />
              </span>
            </div>
          </div>
        </div>
      </Modal>

      <Modal
        open={detailId !== null}
        title={L('تفاصيل الفاتورة', 'Invoice details')}
        onClose={() => { setDetailId(null); setCreditWarnings([]); setOverrideReason('') }}
        footer={<Button variant="secondary" onClick={() => setDetailId(null)}>{L('إغلاق', 'Close')}</Button>}
      >
        {detailQuery.isLoading ? <Loading /> : null}
        {detailQuery.isError ? <ErrorState message={errorMessage(detailQuery.error)} /> : null}
        {detailQuery.data ? (
          <div>
            <div className="mb-4 flex flex-wrap items-center gap-4 border-b border-border-subtle pb-4">
              <div>
                <p className="text-medium font-semibold">{detailQuery.data.number}</p>
                <p className="text-small text-text-subtle">
                  {detailQuery.data.type === 'credit_note'
                    ? L('إشعار دائن', 'Credit note')
                    : detailQuery.data.party_type === 'customer'
                    ? L('عميل', 'Customer')
                    : L('مورد', 'Supplier')}
                  — {detailQuery.data.currency}
                </p>
              </div>
              <div className="ml-auto flex items-center gap-2">
                <Badge tone={statusTone(detailQuery.data.status)}>{statusLabel(detailQuery.data.status, locale)}</Badge>
                {detailQuery.data.status === 'draft' && can('invoices.create') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ invoice: detailQuery.data, path: 'submit' })}>
                    {L('إرسال', 'Submit')}
                  </Button>
                ) : null}
                {detailQuery.data.status === 'submitted' && can('invoices.approve') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ invoice: detailQuery.data, path: 'approve' })}>
                    {L('اعتماد', 'Approve')}
                  </Button>
                ) : null}
                {detailQuery.data.status === 'approved' && can('invoices.approve') ? (
                  <Button variant="ghost" onClick={() => step.mutate({ invoice: detailQuery.data, path: 'issue' })}>
                    {L('إصدار', 'Issue')}
                  </Button>
                ) : null}
                {['draft', 'submitted'].includes(detailQuery.data.status) && can('invoices.create') ? (
                  <Button variant="ghost" onClick={() => voidInvoice.mutate(detailQuery.data)}>
                    {L('إلغاء', 'Void')}
                  </Button>
                ) : null}
                {detailQuery.data.status === 'issued' && can('invoices.approve') ? (
                  <Button
                    variant="ghost"
                    onClick={() => {
                      setError('')
                      setCreditId(detailQuery.data.id)
                    }}
                  >
                    {L('إشعار دائن', 'Credit note')}
                  </Button>
                ) : null}
              </div>
            </div>

            {error ? (
              <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
            ) : null}
            <CreditLimitNotice
              warnings={creditWarnings}
              blockedMessage={error}
              canOverride={can('bookings.override_credit_limit')}
              overrideReason={overrideReason}
              onOverrideReasonChange={setOverrideReason}
            />

            <div className="grid gap-4 sm:grid-cols-3 text-small text-text-subtle">
              <div>
                <p className="text-text-default">{L('المجموع الفرعي', 'Subtotal')}</p>
                <p className="tabular"><Money amountMinor={detailQuery.data.subtotal_minor} currency={detailQuery.data.currency} /></p>
              </div>
              <div>
                <p className="text-text-default">{L('الضريبة', 'Tax')}</p>
                <p className="tabular"><Money amountMinor={detailQuery.data.tax_minor} currency={detailQuery.data.currency} /></p>
              </div>
              <div>
                <p className="text-text-default">{L('المجموع', 'Total')}</p>
                <p className="tabular font-semibold"><Money amountMinor={detailQuery.data.total_minor} currency={detailQuery.data.currency} /></p>
              </div>
            </div>

            <div className="mt-4">
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
                {(detailQuery.data.lines ?? []).map((line, index) => (
                  <tr key={index}>
                    <Td>{line.account?.code} {locale === 'ar' ? line.account?.name_ar : line.account?.name_en}</Td>
                    <Td>{line.description}</Td>
                    <Td className="text-end"><Money amountMinor={line.debit_minor} currency={detailQuery.data.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.credit_minor} currency={detailQuery.data.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.tax_minor} currency={detailQuery.data.currency} /></Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            </div>

            {detailQuery.data.status === 'issued' ? (
              <div className="mt-3 text-small text-text-subtle">
                <p>{L('المستحق', 'Outstanding')}: <Money amountMinor={detailQuery.data.outstanding_minor} currency={detailQuery.data.currency} /></p>
              </div>
            ) : null}
          </div>
        ) : null}
      </Modal>

      <Modal
        open={creditId !== null}
        title={L('إشعار دائن', 'Credit note')}
        onClose={() => setCreditId(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreditId(null)}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => creditNote.mutate()} disabled={creditNote.isPending || !creditAmount}>
              {L('إصدار إشعار دائن', 'Issue credit note')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-3 text-medium text-text-subtle">{L('الفاتورة', 'Invoice')} — {L('المبلغ', 'Amount')}</p>
        <Field label={`${t('total')} (EGP)`} required>
          <Input
            value={creditAmount}
            onChange={(event) => setCreditAmount(event.target.value)}
            inputMode="decimal"
            dir="ltr"
          />
        </Field>
        <Field label={t('memo')}>
          <Input value={creditReason} onChange={(event) => setCreditReason(event.target.value)} />
        </Field>
      </Modal>
    </div>
  )
}
