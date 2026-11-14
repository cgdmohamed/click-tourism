import { DateField } from '../components/DateField'
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import { useDefaultTaxRate, useTaxEnabled, useTaxInclusive } from '../lib/useSetting'
import { taxMinor } from '../lib/tax'
import type { InvoicePrefill } from '../lib/attachments'
import type { Booking, Paginated } from '../lib/types'
import {
  Badge,
  Button,
  Card,
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
import { localizedName } from '../lib/names'
import { statusLabel } from '../lib/labels'
import { usePartyOptions } from '../lib/usePartyOptions'
import CreditLimitNotice, { type CreditWarning } from '../components/CreditLimitNotice'
import { InvoiceCollectionModal } from '../components/InvoiceCollectionModal'
import { AiBadge } from '../components/AiBadge'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'
import { useDeepLinkNew } from '../lib/useDeepLinkNew'
import QuickCreateCustomer from '../components/QuickCreateCustomer'
import { FilterControl, PartyKindFilterField, useFilters } from '../components/FilterDrawer'
import { ACTIONS_COL, ListEmpty, ListSkeleton, ListTable, ListTh } from '../components/ListKit'

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
  created_via?: string | null
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
  tax_applied: false,
  tax_account_id: '',
  booking_item_id: '',
}
const emptyForm = {
  type: 'customer_invoice' as 'customer_invoice' | 'supplier_invoice',
  party_type: 'customer' as 'customer' | 'supplier',
  party_id: '',
  booking_id: '',
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
  const [searchParams] = useSearchParams()
  const bookingFilter = searchParams.get('booking_id') ?? ''
  const urlSearch = searchParams.get('search') ?? ''
  const urlPartyType = searchParams.get('party_type')
  const urlPartyId = searchParams.get('party_id')
  const urlParty = (urlPartyType === 'customer' || urlPartyType === 'supplier') && urlPartyId ? `${urlPartyType}:${urlPartyId}` : ''
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const partyLabel = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState(urlSearch)
  const [error, setError] = useState('')
  const [quickParty, setQuickParty] = useState(false)
  const [open, setOpen] = useState(false)

  // A confirmed document extraction opens the form pre-filled; the user still picks the party and saves it
  // through the normal rules. The state is cleared so a refresh does not reopen it.
  const location = useLocation()
  const navigate = useNavigate()
  const prefill = (location.state as { invoicePrefill?: InvoicePrefill } | null)?.invoicePrefill
  useEffect(() => {
    if (!prefill) return
    setForm((current) => ({
      ...current,
      type: 'supplier_invoice',
      party_type: 'supplier',
      party_id: '',
      issue_date: prefill.issue_date ?? current.issue_date,
      due_date: prefill.due_date ?? '',
      currency: prefill.currency ?? current.currency,
      memo: prefill.memo ?? '',
      lines: [{ ...emptyLine, description: prefill.description ?? '', unit_price: prefill.amount ?? '' }],
    }))
    setOpen(true)
    navigate(location.pathname, { replace: true, state: null })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [prefill])
  const [simpleMode, setSimpleMode] = useState(true)
  useDeepLinkNew(can('invoices.create'), (value, params) => {
    if (value !== 'invoice') return
    const supplier = params.get('party_type') === 'supplier' && params.get('party_id')
    setForm((current) => ({
      ...current,
      ...(supplier ? { type: 'supplier_invoice' as const, party_type: 'supplier' as const, party_id: params.get('party_id') as string } : {}),
    }))
    setError('')
    setOpen(true)
  })
  const [detailId, setDetailId] = useState<string | null>(null)
  const [collectOpen, setCollectOpen] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [creditId, setCreditId] = useState<string | null>(null)
  const [creditAmount, setCreditAmount] = useState('')
  const [creditReason, setCreditReason] = useState('')
  const [creditWarnings, setCreditWarnings] = useState<CreditWarning[]>([])
  const [overrideReason, setOverrideReason] = useState('')

  // DEC-002: a supplier invoice may name the booking (and the booking service per line) it pays for.
  const linkableBookings = useQuery({
    queryKey: ['bookings-linkable', activeBranch?.id],
    queryFn: async () => (await api.get<Paginated<Booking>>('/bookings', { params: { per_page: 100 } })).data.data,
    enabled: open && form.type === 'supplier_invoice',
  })
  const linkedBooking = useQuery({
    queryKey: ['booking', form.booking_id],
    queryFn: async () => (await api.get<{ data: Booking }>(`/bookings/${form.booking_id}`)).data.data,
    enabled: open && form.type === 'supplier_invoice' && form.booking_id !== '',
  })

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const typeLabel = (type: Invoice['type']) =>
    type === 'credit_note' ? L('إشعار دائن', 'Credit note') : type === 'customer_invoice' ? L('عميل', 'Customer') : L('مورد', 'Supplier')

  const filters = useFilters(
    [
      {
        key: 'type',
        label: L('النوع', 'Type'),
        type: 'select',
        options: (['customer_invoice', 'supplier_invoice', 'credit_note'] as const).map((type) => ({ value: type, label: typeLabel(type) })),
        allLabel: L('كل الأنواع', 'All types'),
      },
      {
        key: 'status',
        label: t('status'),
        type: 'select',
        options: ['draft', 'submitted', 'approved', 'issued', 'voided'].map((status) => ({ value: status, label: statusLabel(status, locale) })),
        allLabel: L('كل الحالات', 'All statuses'),
      },
      {
        key: 'party',
        label: L('الطرف', 'Party'),
        type: 'custom',
        render: (value, set) => <PartyKindFilterField value={value} set={set} />,
      },
      {
        key: 'overdue',
        label: L('الاستحقاق', 'Due'),
        type: 'select',
        options: [{ value: '1', label: L('متأخرة فقط', 'Overdue only') }],
        allLabel: L('الكل', 'All'),
      },
      { key: 'from', label: L('من تاريخ الإصدار', 'Issued from'), type: 'date' },
      { key: 'to', label: L('إلى تاريخ الإصدار', 'Issued to'), type: 'date' },
      { key: 'due_from', label: L('من تاريخ الاستحقاق', 'Due from'), type: 'date' },
      { key: 'due_to', label: L('إلى تاريخ الاستحقاق', 'Due to'), type: 'date' },
    ],
    {
      onApply: () => setPage(1),
      initial: {
        ...(urlParty ? { party: urlParty } : {}),
        ...(searchParams.get('overdue') === '1' ? { overdue: '1' } : {}),
        ...(['customer_invoice', 'supplier_invoice', 'credit_note'].includes(searchParams.get('type') ?? '') ? { type: searchParams.get('type') as string } : {}),
      },
      initialLabels: urlParty && searchParams.get('party_name') ? { party: searchParams.get('party_name') as string } : undefined,
    },
  )
  const [partyKind, partyId] = filters.applied.party ? (filters.applied.party.split(':') as [string, string]) : ['', '']

  const invoicesQuery = useQuery({
    queryKey: ['invoices', page, filters.applied, search, bookingFilter, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Invoice[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }>('/invoices', {
        params: {
          page,
          per_page: 20,
          ...(filters.applied.type ? { type: filters.applied.type } : {}),
          ...(filters.applied.status ? { status: filters.applied.status } : {}),
          ...(partyId ? { party_type: partyKind, party_id: partyId } : {}),
          ...(filters.applied.from ? { from: filters.applied.from } : {}),
          ...(filters.applied.to ? { to: filters.applied.to } : {}),
          ...(filters.applied.due_from ? { due_from: filters.applied.due_from } : {}),
          ...(filters.applied.due_to ? { due_to: filters.applied.due_to } : {}),
          ...(filters.applied.overdue ? { overdue: 1 } : {}),
          ...(search ? { search } : {}),
          ...(bookingFilter ? { booking_id: bookingFilter } : {}),
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
    .map((a) => ({ value: a.id, label: localizedName(a, locale), code: a.code }))
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

  const { enabled: taxEnabled, loaded: taxFlagLoaded } = useTaxEnabled()
  const { rate: defaultTaxRate, loaded: taxRateLoaded } = useDefaultTaxRate()
  const taxInclusive = useTaxInclusive()
  const lineTax = (line: typeof emptyLine) =>
    taxEnabled && line.tax_applied ? taxMinor(lineSubtotal(line), defaultTaxRate, taxInclusive) : 0

  const formGross = form.lines.reduce((sum, line) => sum + lineSubtotal(line), 0)
  const formTax = form.lines.reduce((sum, line) => sum + lineTax(line), 0)
  // Inclusive: entered amounts already contain the tax, so the total is the entered sum.
  const formSubtotal = taxInclusive ? formGross - formTax : formGross
  const formTotal = formSubtotal + formTax

  const create = useMutation({
    mutationFn: async (submit: boolean) => {
      if (taxEnabled && form.lines.some((line) => line.tax_applied) && (!taxFlagLoaded || !taxRateLoaded || Number(defaultTaxRate) <= 0)) {
        throw new Error(L('لا يمكن حساب الضريبة قبل تحميل نسبتها من الإعدادات.', 'Tax settings must be loaded before calculating tax.'))
      }
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
            tax_applied: taxEnabled && line.tax_applied,
            booking_item_id: line.booking_item_id || undefined,
          }
        }
        if (!line.account_id) throw new Error(L('اختر حساب كل بند', 'Select an account for every line'))
        return {
          account_id: line.account_id,
          description: line.description || undefined,
          debit_minor: toMinor(line.debit || '0', form.currency),
          credit_minor: toMinor(line.credit || '0', form.currency),
          tax_minor: lineTax(line),
          tax_applied: taxEnabled && line.tax_applied,
          tax_account_id: line.tax_account_id || undefined,
          booking_item_id: line.booking_item_id || undefined,
        }
      })
      const { data } = await api.post<{ data: { id: string } }>(
        '/invoices',
        {
          type: form.type,
          party_type: form.party_type,
          party_id: form.party_id,
          booking_id: form.type === 'supplier_invoice' && form.booking_id ? form.booking_id : undefined,
          issue_date: form.issue_date,
          due_date: form.due_date || undefined,
          currency: form.currency,
          memo: form.memo || undefined,
          lines,
          submit,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
      return data.data.id
    },
    onSuccess: async (newInvoiceId) => {
      setOpen(false)
      setDetailId(newInvoiceId)
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

  const openDetails = (id: string) => {
    setError('')
    setCreditWarnings([])
    setOverrideReason('')
    setDetailId(id)
  }

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

      {bookingFilter ? (
        <div className="mb-3 flex items-center justify-between rounded-12 border border-border-subtle bg-surface-secondary px-4 py-3 text-small">
          <span>{L('عرض الفواتير الخاصة بالحجز المحدد فقط', 'Showing invoices for the selected booking')}</span>
          <Link to="/invoices" className="font-semibold text-brand-1-accent underline">{L('كل الفواتير', 'All invoices')}</Link>
        </div>
      ) : null}

      {error && !open && !detailId && !creditId ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder={t('search')}
            aria-label={t('search')}
            className="max-w-xs"
          />
          <FilterControl state={filters} />
        </div>
        {invoicesQuery.isLoading ? <ListSkeleton columns={7} /> : null}
        {invoicesQuery.isError ? <ErrorState message={errorMessage(invoicesQuery.error)} onRetry={() => void invoicesQuery.refetch()} /> : null}
        {data && data.data.length === 0 ? (
          <ListEmpty
            filtered={filters.count > 0 || search !== ''}
            onClear={() => {
              filters.clearAll()
              setSearch('')
              setPage(1)
            }}
          />
        ) : null}
        {data && data.data.length > 0 ? (
          <>
            <MobileCards items={data.data} getKey={(invoice) => invoice.id} label={L('الفواتير', 'Invoices')}>
              {(invoice) => (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <Link to={`/invoices/${invoice.id}`} className="text-medium font-semibold text-brand-1-accent underline">
                      {invoice.number}
                    </Link>
                    <span>
                      <Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge>
                      <AiBadge via={invoice.created_via} />
                    </span>
                  </div>
                  <p className="text-medium font-medium">{partyLabel(invoice.party?.name_ar ?? '', invoice.party?.name_en ?? '') || '—'}</p>
                  <dl className="grid grid-cols-2 gap-3">
                    <CardField label={t('date')}>{formatDate(invoice.issue_date, locale)}</CardField>
                    <CardField label={L('النوع', 'Type')}>{typeLabel(invoice.type)}</CardField>
                    <CardField label={t('total')}>
                      <Money amountMinor={invoice.total_minor} currency={invoice.currency} />
                    </CardField>
                    <CardField label={L('المستحق', 'Outstanding')}>
                      {invoice.status === 'issued' ? <Money amountMinor={invoice.outstanding_minor} currency={invoice.currency} /> : '—'}
                    </CardField>
                  </dl>
                  <div>
                    <Button variant="secondary" icon="eye" onClick={() => openDetails(invoice.id)}>{L('تفاصيل', 'Details')}</Button>
                  </div>
                </>
              )}
            </MobileCards>
            <TableOnly>
              <ListTable label={L('الفواتير', 'Invoices')}>
                <thead>
                  <tr>
                    <ListTh>{L('الرقم', 'No.')}</ListTh>
                    <ListTh>{t('date')}</ListTh>
                    <ListTh>{L('النوع', 'Type')}</ListTh>
                    <ListTh>{L('الطرف', 'Party')}</ListTh>
                    <ListTh className="text-end">{t('total')}</ListTh>
                    <ListTh className="text-end">{L('المستحق', 'Outstanding')}</ListTh>
                    <ListTh>{t('status')}</ListTh>
                    <ListTh className={ACTIONS_COL}>
                      <span className="sr-only">{t('actions')}</span>
                    </ListTh>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((invoice) => (
                    <tr key={invoice.id}>
                      <Td className="tabular whitespace-nowrap">
                        <Link to={`/invoices/${invoice.id}`} className="font-medium text-brand-1-accent hover:underline">
                          {invoice.number}
                        </Link>
                        <AiBadge via={invoice.created_via} />
                      </Td>
                      <Td className="tabular whitespace-nowrap">{formatDate(invoice.issue_date, locale)}</Td>
                      <Td className="whitespace-nowrap">{typeLabel(invoice.type)}</Td>
                      <Td>{partyLabel(invoice.party?.name_ar ?? '', invoice.party?.name_en ?? '') || '—'}</Td>
                      <Td className="whitespace-nowrap text-end"><Money amountMinor={invoice.total_minor} currency={invoice.currency} /></Td>
                      <Td className="whitespace-nowrap text-end">
                        {invoice.status === 'issued' ? <Money amountMinor={invoice.outstanding_minor} currency={invoice.currency} /> : '—'}
                      </Td>
                      <Td><Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge></Td>
                      <Td className={ACTIONS_COL}>
                        <IconButton icon="eye" label={`${L('تفاصيل', 'Details')} ${invoice.number}`} onClick={() => openDetails(invoice.id)} />
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </ListTable>
            </TableOnly>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onChange={setPage} total={data.meta.total} perPage={data.meta.per_page} />
          </>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={L('فاتورة جديدة', 'New invoice')}
        onClose={() => { if (!quickParty) setOpen(false) }}
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
                  booking_id: '',
                  lines: form.lines.map((line) => ({ ...line, booking_item_id: '' })),
                })
              }
            >
              <option value="customer_invoice">{L('فاتورة عميل', 'Customer invoice')}</option>
              <option value="supplier_invoice">{L('فاتورة مورد', 'Supplier invoice')}</option>
            </Select>
          </Field>
          <div className="min-w-0">
            <Field label={L('الطرف', 'Party')} required>
              <SearchableSelect
                value={form.party_id}
                onChange={(value) => setForm({ ...form, party_id: value })}
                placeholder={L('اختر الطرف', 'Select party')}
                options={partyOptions}
                onSearch={partyPicker.setSearch}
                hasMore={partyPicker.hasMore}
              />
            </Field>
            {can(isCustomer ? 'customers.create' : 'suppliers.create') ? (
              <div className="mt-1 flex justify-start">
                <Button variant="ghost" icon="plus" className="px-2" onClick={() => setQuickParty(true)}>
                  {isCustomer ? L('إضافة عميل جديد', 'Add new customer') : L('إضافة مورد جديد', 'Add new supplier')}
                </Button>
              </div>
            ) : null}
          </div>
          {form.type === 'supplier_invoice' ? (
            <Field label={L('الحجز المرتبط (اختياري)', 'Linked booking (optional)')}>
              <SearchableSelect
                value={form.booking_id}
                onChange={(value) => setForm({ ...form, booking_id: value, lines: form.lines.map((line) => ({ ...line, booking_item_id: '' })) })}
                placeholder={L('بدون حجز', 'No booking')}
                clearLabel={L('بدون حجز', 'No booking')}
                options={(linkableBookings.data ?? []).map((booking) => ({ value: booking.id, label: booking.number }))}
              />
            </Field>
          ) : null}
          <Field label={t('date')} required>
            <DateField
              
              value={form.issue_date}
              onChange={(event) => setForm({ ...form, issue_date: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field label={L('تاريخ الاستحقاق', 'Due date')}>
            <DateField
              
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
                      {taxEnabled ? <Field label={L('تطبيق الضريبة', 'Apply tax')}>
                        <label className="flex flex-wrap items-center gap-x-2 gap-y-1 text-small">
                          <span className="relative inline-flex h-5 w-9 shrink-0">
                            <input type="checkbox" role="switch" className="peer sr-only" checked={taxEnabled && line.tax_applied}
                              disabled={!taxEnabled || !taxRateLoaded || Number(defaultTaxRate) <= 0}
                              onChange={(event) => updateLine(index, { tax_applied: event.target.checked })} />
                            <span className="absolute inset-0 rounded-full bg-text-subtlest transition-colors peer-checked:bg-brand-1-background peer-focus-visible:ring-2 peer-focus-visible:ring-border-focus peer-focus-visible:ring-offset-1 peer-disabled:opacity-40" />
                            <span className="pointer-events-none absolute start-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4" />
                          </span>
                          <span className={line.tax_applied ? 'font-semibold text-text-default' : 'font-semibold text-text-subtle'}>
                            {line.tax_applied ? L('مفعّلة', 'On') : L('غير مفعّلة', 'Off')}
                          </span>
                          {taxInclusive ? <span className="whitespace-nowrap text-text-subtle">{L('(شاملة)', '(inclusive)')}</span> : null}
                        </label>
                      </Field> : null}
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
                      {taxEnabled ? <label className="flex items-center gap-1 text-small" title={L('الضريبة من الإعدادات', 'Tax from settings')}>
                        <span className="relative inline-flex h-5 w-9 shrink-0">
                          <input type="checkbox" role="switch" className="peer sr-only" checked={taxEnabled && line.tax_applied}
                            disabled={!taxEnabled || !taxRateLoaded || Number(defaultTaxRate) <= 0}
                            onChange={(event) => updateLine(index, { tax_applied: event.target.checked })} />
                          <span className="absolute inset-0 rounded-full bg-text-subtlest transition-colors peer-checked:bg-brand-1-background peer-focus-visible:ring-2 peer-focus-visible:ring-border-focus peer-focus-visible:ring-offset-1 peer-disabled:opacity-40" />
                          <span className="pointer-events-none absolute start-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4" />
                        </span>
                        {L('ضريبة', 'Tax')}
                      </label> : null}
                    </div>
                  </>
                )}
                <div className="flex items-end justify-end sm:col-span-1">
                  <IconButton icon="x" label={t('removeLine')} onClick={() => removeLine(index)} />
                </div>
                {form.type === 'supplier_invoice' && form.booking_id ? (
                  <div className="sm:col-span-12">
                    <Field label={L('الخدمة في الحجز (اختياري)', 'Booking service (optional)')}>
                      <Select value={line.booking_item_id} onChange={(event) => updateLine(index, { booking_item_id: event.target.value })}>
                        <option value="">{L('بدون ربط بخدمة', 'Not linked to a service')}</option>
                        {(linkedBooking.data?.items ?? []).map((item) => (
                          <option key={item.id} value={item.id}>{item.description}</option>
                        ))}
                      </Select>
                    </Field>
                  </div>
                ) : null}
              </div>
            ))}
          </div>
          {!taxEnabled ? (
            <p className="mt-2 text-small text-text-subtle">{L('الضريبة معطّلة في الإعدادات.', 'Tax is disabled in settings.')}</p>
          ) : null}
          <div className="mt-2 flex justify-end">
            <Button variant="secondary" onClick={addLine}>{L('إضافة بند', 'Add line')}</Button>
          </div>
          <dl className="mt-3 ms-auto w-full max-w-xs space-y-1 text-medium tabular">
            <div className="flex items-center justify-between gap-6">
              <dt className="text-text-subtle">{L('المجموع الفرعي', 'Subtotal')}</dt>
              <dd><Money amountMinor={formSubtotal} currency={form.currency} /></dd>
            </div>
            {taxEnabled ? (
              <div className="flex items-center justify-between gap-6">
                <dt className="text-text-subtle">{L('الضريبة', 'Tax')}</dt>
                <dd><Money amountMinor={formTax} currency={form.currency} /></dd>
              </div>
            ) : null}
            <div className="flex items-center justify-between gap-6 border-t border-border-subtle pt-2 font-semibold">
              <dt>{L('المجموع', 'Total')}</dt>
              <dd><Money amountMinor={formTotal} currency={form.currency} /></dd>
            </div>
          </dl>
        </div>
      </Modal>
      <QuickCreateCustomer
        kind={isCustomer ? 'customer' : 'supplier'}
        open={quickParty}
        onClose={() => setQuickParty(false)}
        onCreated={(party) => { setForm((current) => ({ ...current, party_id: party.id })); setQuickParty(false) }}
      />

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
                {detailQuery.data.type === 'customer_invoice' && detailQuery.data.status === 'issued' && detailQuery.data.outstanding_minor > 0 && can('payments.create') ? (
                  <Button onClick={() => setCollectOpen(true)}>
                    {L('تحصيل الفاتورة', 'Collect invoice')}
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
                  <Th className="text-end">{L('الكمية', 'Qty')}</Th>
                  <Th className="text-end">{L('سعر الوحدة', 'Unit price')}</Th>
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
                    <Td className="text-end tabular">{line.quantity ?? '—'}</Td>
                    <Td className="text-end">{line.unit_price_minor != null ? <Money amountMinor={line.unit_price_minor} currency={detailQuery.data.currency} /> : '—'}</Td>
                    <Td className="text-end"><Money amountMinor={line.debit_minor} currency={detailQuery.data.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.credit_minor} currency={detailQuery.data.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.tax_minor} currency={detailQuery.data.currency} /></Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            </div>

            {detailQuery.data.status === 'issued' && detailQuery.data.outstanding_minor > 0 ? (
              <div className="mt-3 text-small text-text-subtle">
                {detailQuery.data.type === 'customer_invoice' ? (
                  <p>
                    {L('للتحصيل: سجل سند قبض من التحصيلات، وبعدها اختر «تطبيق على فاتورة» لتخفيض المستحق. السند غير المطبق يظل دفعة مقدمة.', 'To collect, create a receipt in Payments and apply it to this invoice. Unapplied receipts remain advances.')}
                    {' '}<Link className="font-semibold text-brand-1-accent underline" to="/payments">{L('فتح التحصيلات', 'Open Payments')}</Link>
                  </p>
                ) : (
                  <p><Link className="font-semibold text-brand-1-accent underline" to="/supplier-settlements">{L('فتح مدفوعات الموردين', 'Open supplier payments')}</Link></p>
                )}
              </div>
            ) : null}
            {detailQuery.data.status === 'issued' ? (
              <div className="mt-3 text-small text-text-subtle">
                <p>{L('المستحق', 'Outstanding')}: <Money amountMinor={detailQuery.data.outstanding_minor} currency={detailQuery.data.currency} /></p>
              </div>
            ) : null}
          </div>
        ) : null}
      </Modal>

      <InvoiceCollectionModal
        invoice={collectOpen && detailQuery.data && detailQuery.data.type === 'customer_invoice' ? detailQuery.data : null}
        onClose={() => setCollectOpen(false)}
      />

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
