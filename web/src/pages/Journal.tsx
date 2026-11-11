import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, isValidAmount, parseAmount, toDecimal, todayISO } from '../lib/format'
import type { Account, Customer, JournalEntry, Paginated, Supplier } from '../lib/types'
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
import { statusLabel } from '../lib/labels'

type PartyType = '' | 'customer' | 'supplier'

type DraftLine = {
  account_id: string
  debit: string
  credit: string
  description: string
  party_type: PartyType
  party_id: string
  cost_center_id: string
}

type CostCenterOption = { id: string; code: string; name_ar: string; name_en: string }

const emptyLine = (): DraftLine => ({
  account_id: '',
  debit: '',
  credit: '',
  description: '',
  party_type: '',
  party_id: '',
  cost_center_id: '',
})

type Filters = { from: string; to: string; status: '' | 'draft' | 'posted'; search: string; includeReversals: boolean }

const defaultFilters: Filters = { from: '', to: '', status: '', search: '', includeReversals: true }

export default function Journal() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [memo, setMemo] = useState('')
  const [entryDate, setEntryDate] = useState(todayISO())
  const [currency, setCurrency] = useState('EGP')
  const [fxRate, setFxRate] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine(), emptyLine()])
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<JournalEntry | null>(null)
  const [filters, setFilters] = useState<Filters>(defaultFilters)
  const [searchInput, setSearchInput] = useState('')

  const ar = locale === 'ar'
  const canPickCostCenter = can('cost_centers.view') && can('cost_centers.manage')
  const canPickCustomer = can('customers.view')
  const canPickSupplier = can('suppliers.view')
  const canPickParty = canPickCustomer || canPickSupplier

  // Debounce the free-text search so each keystroke does not hit the API.
  useEffect(() => {
    const handle = window.setTimeout(() => {
      setFilters((current) => (current.search === searchInput.trim() ? current : { ...current, search: searchInput.trim() }))
      setPage(1)
    }, 300)
    return () => window.clearTimeout(handle)
  }, [searchInput])

  const updateFilters = (patch: Partial<Filters>) => {
    setFilters((current) => ({ ...current, ...patch }))
    setPage(1)
  }

  const filtersActive =
    filters.from !== '' || filters.to !== '' || filters.status !== '' || filters.search !== '' || !filters.includeReversals

  const accountsQuery = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
  })

  const entriesQuery = useQuery({
    queryKey: ['journal', page, activeBranch?.id, filters],
    queryFn: async () => {
      const { data } = await api.get<Paginated<JournalEntry>>('/journal-entries', {
        params: {
          page,
          per_page: 20,
          ...(filters.from ? { from: filters.from } : {}),
          ...(filters.to ? { to: filters.to } : {}),
          ...(filters.status ? { status: filters.status } : {}),
          ...(filters.search ? { search: filters.search } : {}),
          ...(filters.includeReversals ? {} : { include_reversals: 0 }),
        },
      })
      return data
    },
  })

  const customersQuery = useQuery({
    queryKey: ['journal-customers', activeBranch?.id],
    enabled: open && canPickCustomer,
    queryFn: async () => {
      const { data } = await api.get<Paginated<Customer>>('/customers', { params: { per_page: 100 } })
      return data.data
    },
  })

  const suppliersQuery = useQuery({
    queryKey: ['journal-suppliers', activeBranch?.id],
    enabled: open && canPickSupplier,
    queryFn: async () => {
      const { data } = await api.get<Paginated<Supplier>>('/suppliers', { params: { per_page: 100 } })
      return data.data
    },
  })

  const costCentersQuery = useQuery({
    queryKey: ['journal-cost-centers'],
    enabled: open && canPickCostCenter,
    queryFn: async () => {
      const { data } = await api.get<{ data: CostCenterOption[] }>('/cost-centers')
      return data.data
    },
  })

  const entries = entriesQuery.data?.data ?? []

  const postableAccounts = useMemo(
    () => (accountsQuery.data ?? []).filter((account) => account.is_postable && account.is_active),
    [accountsQuery.data],
  )

  const accountById = useMemo(() => new Map((accountsQuery.data ?? []).map((account) => [account.id, account])), [accountsQuery.data])

  const partyOptions = useMemo(
    () => ({
      customer: (customersQuery.data ?? []).map((customer) => ({
        value: customer.id,
        label: ar ? customer.name_ar : customer.name_en,
        hint: customer.phone,
      })),
      supplier: (suppliersQuery.data ?? []).map((supplier) => ({
        value: supplier.id,
        label: ar ? supplier.name_ar : supplier.name_en,
        hint: supplier.phone,
      })),
    }),
    [customersQuery.data, suppliersQuery.data, ar],
  )

  const costCenterOptions = useMemo(
    () => (costCentersQuery.data ?? []).map((center) => ({ value: center.id, label: `${center.code} — ${ar ? center.name_ar : center.name_en}` })),
    [costCentersQuery.data, ar],
  )

  // Per-line inline validation: strict amounts and the mandatory party on control accounts.
  const lineErrors = useMemo(
    () =>
      lines.map((line) => {
        const errors: { debit?: string; credit?: string; party?: string } = {}
        const invalid = ar ? 'مبلغ غير صالح' : 'Invalid amount'
        if (line.debit.trim() && !isValidAmount(line.debit, currency)) errors.debit = invalid
        if (line.credit.trim() && !isValidAmount(line.credit, currency)) errors.credit = invalid
        const expected = accountById.get(line.account_id)?.control_party_type
        if (expected && (line.party_type !== expected || !line.party_id)) {
          errors.party =
            expected === 'customer'
              ? ar ? 'حساب العملاء يتطلب اختيار العميل' : 'The receivable account requires a customer'
              : ar ? 'حساب الموردين يتطلب اختيار المورد' : 'The payable account requires a supplier'
        }
        return errors
      }),
    [lines, currency, accountById, ar],
  )

  const hasLineErrors = lineErrors.some((errors) => errors.debit || errors.credit || errors.party)
  const hasCostCenter = lines.some((line) => line.cost_center_id)

  const totals = useMemo(() => {
    const debit = lines.reduce((sum, line) => sum + (parseAmount(line.debit, currency) ?? 0), 0)
    const credit = lines.reduce((sum, line) => sum + (parseAmount(line.credit, currency) ?? 0), 0)
    return { debit, credit, balanced: debit === credit && debit > 0 }
  }, [lines, currency])

  const canSubmit = totals.balanced && !hasLineErrors

  const create = useMutation({
    mutationFn: async (post: boolean) => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        entry_date: entryDate,
        branch_id: activeBranch.id,
        currency,
        ...(fxRate.trim() ? { fx_rate: fxRate.trim(), fx_rate_date: entryDate, fx_rate_source: 'manual' } : {}),
        memo,
        post,
        lines: lines.map((line) => ({
          account_id: line.account_id,
          debit_minor: parseAmount(line.debit, currency) ?? 0,
          credit_minor: parseAmount(line.credit, currency) ?? 0,
          description: line.description,
          ...(line.party_type && line.party_id ? { party_type: line.party_type, party_id: line.party_id } : {}),
        })),
      }
      const { data } = await api.post<{ data: JournalEntry }>('/journal-entries', payload)
      const entry = data.data

      // Cost centers are allocated per posted journal line once the entry exists.
      const failures: string[] = []
      const createdLines = [...(entry.lines ?? [])].sort((a, b) => Number(a.id) - Number(b.id))
      for (const [index, line] of lines.entries()) {
        if (!line.cost_center_id) continue
        const created = createdLines[index]
        if (!created) continue
        try {
          await api.post(`/journal-lines/${created.id}/cost-allocations`, {
            allocations: [{ cost_center_id: line.cost_center_id, allocation_type: 'percent', percent: 100 }],
          })
        } catch (cause) {
          failures.push(`${index + 1}: ${errorMessage(cause)}`)
        }
      }
      return { entry, failures }
    },
    onSuccess: async ({ entry, failures }) => {
      setOpen(false)
      setMemo('')
      setLines([emptyLine(), emptyLine()])
      setError(
        failures.length
          ? `${ar ? `تم إنشاء القيد ${entry.number} لكن تعذّر تحميل مراكز التكلفة على البنود` : `Entry ${entry.number} was created but cost centers could not be allocated on lines`} — ${failures.join(' | ')}`
          : '',
      )
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const postDraft = useMutation({
    mutationFn: async (entry: JournalEntry) => {
      const { data } = await api.post<{ data: JournalEntry }>(`/journal-entries/${entry.id}/post`)
      return data.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reverse = useMutation({
    mutationFn: async (entry: JournalEntry) => {
      const reason = window.prompt(locale === 'ar' ? 'سبب الإلغاء/العكس' : 'Reversal reason')
      if (!reason) return null
      const { data } = await api.post(`/journal-entries/${entry.id}/reverse`, { reason })
      return data
    },
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const updateLine = (index: number, patch: Partial<DraftLine>) => {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  return (
    <div>
      <PageHeader
        title={t('journal')}
        subtitle={locale === 'ar' ? 'قيود اليومية — مدينة = دائنة' : 'Double-entry journal — debits must equal credits'}
        icon="scale"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newJournal')}</Button>}
      />

      {error && !open ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}

      <Card className="mb-4">
        <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
          <div className="lg:col-span-2">
            <Field label={ar ? 'بحث (الرقم أو البيان)' : 'Search (number or memo)'}>
              <Input
                icon="search"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder={ar ? 'رقم القيد أو البيان' : 'Entry number or memo'}
              />
            </Field>
          </div>
          <Field label={ar ? 'من تاريخ' : 'From'}>
            <Input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => updateFilters({ from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'إلى تاريخ' : 'To'}>
            <Input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => updateFilters({ to: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('status')}>
            <Select value={filters.status} onChange={(event) => updateFilters({ status: event.target.value as Filters['status'] })}>
              <option value="">{ar ? 'الكل' : 'All'}</option>
              <option value="draft">{statusLabel('draft', locale)}</option>
              <option value="posted">{statusLabel('posted', locale)}</option>
            </Select>
          </Field>
          <div className="flex flex-col justify-end gap-2">
            <label className="flex h-9 items-center gap-2 text-medium text-text-default">
              <input
                type="checkbox"
                checked={filters.includeReversals}
                onChange={(event) => updateFilters({ includeReversals: event.target.checked })}
              />
              {ar ? 'إظهار القيود العكسية' : 'Include reversals'}
            </label>
          </div>
        </div>
        {filtersActive ? (
          <div className="flex justify-end px-4 pb-4">
            <Button
              variant="ghost"
              onClick={() => {
                setFilters(defaultFilters)
                setSearchInput('')
                setPage(1)
              }}
            >
              {ar ? 'مسح التصفية' : 'Clear filters'}
            </Button>
          </div>
        ) : null}
      </Card>

      <Card>
        {entriesQuery.isLoading ? <Loading /> : null}
        {entriesQuery.isError ? (
          <ErrorState message={errorMessage(entriesQuery.error)} onRetry={() => void entriesQuery.refetch()} />
        ) : null}
        {entriesQuery.data && entries.length === 0 ? <EmptyState /> : null}
        {entries.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('date')}</Th>
                <Th>{t('description')}</Th>
                <Th>{t('currency')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('amount')}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.id}>
                  <Td className="tabular font-medium">{entry.number}</Td>
                  <Td>{formatDate(entry.entry_date, locale)}</Td>
                  <Td>{entry.memo ?? '—'}</Td>
                  <Td>{entry.currency}</Td>
                  <Td>
                    <Badge tone={statusTone(entry.status)}>{statusLabel(entry.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(entry.total_minor, entry.currency)} {entry.currency}
                  </Td>
                  <Td className="text-end">
                    <div className="flex items-center justify-end gap-1">
                    <Button variant="ghost" onClick={() => setDetail(entry)}>
                      {locale === 'ar' ? 'عرض' : 'View'}
                    </Button>
                    {entry.status === 'posted' ? (
                      entry.reversal_of_id ? (
                        <Badge tone="neutral">{locale === 'ar' ? 'قيد عكس' : 'Reversal'}</Badge>
                      ) : entry.reversed_by_id ? (
                        <Badge tone="neutral">{locale === 'ar' ? 'معكوس' : 'Reversed'}</Badge>
                      ) : (
                        <Button variant="ghost" onClick={() => reverse.mutate(entry)}>
                          {locale === 'ar' ? 'عكس' : 'Reverse'}
                        </Button>
                      )
                    ) : (
                      <Button variant="ghost" disabled={postDraft.isPending} onClick={() => postDraft.mutate(entry)}>
                        {t('post')}
                      </Button>
                    )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
        <Pagination
          page={entriesQuery.data?.meta.current_page ?? 1}
          lastPage={entriesQuery.data?.meta.last_page ?? 1}
          onChange={setPage}
        />
      </Card>

      <Modal
        open={open}
        title={t('newJournal')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <span className={totals.balanced ? 'me-auto text-sm text-success-text' : 'me-auto text-sm text-danger-text'}>
              {t('debit')}: {toDecimal(totals.debit, currency)} · {t('credit')}: {toDecimal(totals.credit, currency)}
            </span>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              variant="secondary"
              disabled={create.isPending || !canSubmit || hasCostCenter}
              title={hasCostCenter ? (ar ? 'مراكز التكلفة تُوزَّع على القيود المرحّلة فقط' : 'Cost centers can only be allocated on posted entries') : undefined}
              onClick={() => create.mutate(false)}
            >
              {t('saveDraft')}
            </Button>
            <Button disabled={create.isPending || !canSubmit} onClick={() => create.mutate(true)}>
              {create.isPending ? t('saving') : t('post')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('date')} required>
            <Input type="date" value={entryDate} onChange={(event) => setEntryDate(event.target.value)} dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={currency} onChange={(event) => setCurrency(event.target.value)}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={locale === 'ar' ? 'سعر الصرف (اتركه فارغاً للسعر المسجّل)' : 'FX rate (leave empty for the stored rate)'}>
            <Input value={fxRate} onChange={(event) => setFxRate(event.target.value)} placeholder={locale === 'ar' ? 'تلقائي حسب جدول الأسعار' : 'Automatic from the rate table'} inputMode="decimal" dir="ltr" />
          </Field>
        </div>

        <div className="mt-4">
          <Field label={t('description')}>
            <Input value={memo} onChange={(event) => setMemo(event.target.value)} />
          </Field>
        </div>

        <div className="mt-5 flex flex-col gap-3">
          {lines.map((line, index) => {
            const errors = lineErrors[index] ?? {}
            const expectedParty = accountById.get(line.account_id)?.control_party_type ?? null
            const typeChoices: Array<'customer' | 'supplier'> = expectedParty
              ? [expectedParty]
              : ([canPickCustomer ? 'customer' : null, canPickSupplier ? 'supplier' : null].filter(Boolean) as Array<'customer' | 'supplier'>)
            const showParty = canPickParty || expectedParty !== null
            const showCostCenter = canPickCostCenter
            return (
              <div key={index} className="rounded-12 border border-border-default p-3">
                <div className="grid gap-2 sm:grid-cols-12">
                  <div className="sm:col-span-5">
                    <SearchableSelect
                      value={line.account_id}
                      onChange={(account_id) => {
                        const control = accountById.get(account_id)?.control_party_type ?? null
                        // A control account fixes the party type; switch it (and drop a mismatched party).
                        updateLine(
                          index,
                          control && line.party_type !== control
                            ? { account_id, party_type: control, party_id: '' }
                            : { account_id },
                        )
                      }}
                      placeholder={t('selectAccount')}
                      options={postableAccounts.map((account) => ({
                        value: account.id,
                        label: `${account.code} — ${ar ? account.name_ar : account.name_en}`,
                      }))}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder={t('debit')}
                      value={line.debit}
                      onChange={(event) => updateLine(index, { debit: event.target.value, credit: '' })}
                      inputMode="decimal"
                      dir="ltr"
                      aria-invalid={errors.debit ? true : undefined}
                    />
                    {errors.debit ? <span className="text-small mt-1 block text-danger-text">{errors.debit}</span> : null}
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder={t('credit')}
                      value={line.credit}
                      onChange={(event) => updateLine(index, { credit: event.target.value, debit: '' })}
                      inputMode="decimal"
                      dir="ltr"
                      aria-invalid={errors.credit ? true : undefined}
                    />
                    {errors.credit ? <span className="text-small mt-1 block text-danger-text">{errors.credit}</span> : null}
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder={t('description')}
                      value={line.description}
                      onChange={(event) => updateLine(index, { description: event.target.value })}
                    />
                  </div>
                  <div className="sm:col-span-1">
                    <Button
                      variant="ghost"
                      className="w-full"
                      icon="x"
                      onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                      disabled={lines.length <= 2}
                      aria-label={t('removeLine')}
                    />
                  </div>
                </div>
                {showParty || showCostCenter ? (
                  <div className="mt-2 grid gap-2 sm:grid-cols-12">
                    {showParty ? (
                      <>
                        <div className="sm:col-span-3">
                          <Select
                            value={line.party_type}
                            disabled={expectedParty !== null}
                            aria-label={ar ? 'نوع الطرف' : 'Party type'}
                            onChange={(event) => updateLine(index, { party_type: event.target.value as PartyType, party_id: '' })}
                          >
                            {expectedParty ? null : <option value="">{ar ? 'بدون طرف' : 'No party'}</option>}
                            {typeChoices.map((type) => (
                              <option key={type} value={type}>
                                {type === 'customer' ? (ar ? 'عميل' : 'Customer') : ar ? 'مورد' : 'Supplier'}
                              </option>
                            ))}
                          </Select>
                        </div>
                        <div className="sm:col-span-4">
                          <SearchableSelect
                            value={line.party_id}
                            disabled={!line.party_type}
                            onChange={(party_id) => updateLine(index, { party_id })}
                            placeholder={
                              line.party_type === 'supplier'
                                ? ar ? 'اختر المورد' : 'Select supplier'
                                : line.party_type === 'customer'
                                  ? ar ? 'اختر العميل' : 'Select customer'
                                  : ar ? 'اختر نوع الطرف أولاً' : 'Choose a party type first'
                            }
                            options={line.party_type ? partyOptions[line.party_type] : []}
                          />
                        </div>
                      </>
                    ) : null}
                    {showCostCenter ? (
                      <div className={showParty ? 'sm:col-span-5' : 'sm:col-span-12'}>
                        <SearchableSelect
                          value={line.cost_center_id}
                          onChange={(cost_center_id) => updateLine(index, { cost_center_id })}
                          placeholder={ar ? 'مركز التكلفة (اختياري)' : 'Cost center (optional)'}
                          clearLabel={ar ? 'بدون مركز تكلفة' : 'No cost center'}
                          options={costCenterOptions}
                        />
                      </div>
                    ) : null}
                    {errors.party ? <p className="text-small text-danger-text sm:col-span-12">{errors.party}</p> : null}
                  </div>
                ) : null}
              </div>
            )
          })}
          <Button variant="secondary" onClick={() => setLines((current) => [...current, emptyLine()])}>
            + {t('addLine')}
          </Button>
        </div>

        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <Modal
        open={detail !== null}
        title={detail ? `${detail.number} — ${formatDate(detail.entry_date, locale)}` : ''}
        onClose={() => setDetail(null)}
        footer={<Button variant="secondary" onClick={() => setDetail(null)}>{t('cancel')}</Button>}
      >
        {detail ? (
          <>
            <p className="mb-3 text-medium text-text-subtle">
              {statusLabel(detail.status, locale)} · {detail.currency}
              {detail.memo ? ` · ${detail.memo}` : ''}
            </p>
            <Table>
              <thead>
                <tr>
                  <Th>{locale === 'ar' ? 'الحساب' : 'Account'}</Th>
                  <Th>{t('description')}</Th>
                  <Th className="text-end">{t('debit')}</Th>
                  <Th className="text-end">{t('credit')}</Th>
                </tr>
              </thead>
              <tbody>
                {(detail.lines ?? []).map((line) => (
                  <tr key={line.id}>
                    <Td className="tabular">
                      {line.account ? `${line.account.code} — ${locale === 'ar' ? line.account.name_ar : line.account.name_en}` : '—'}
                    </Td>
                    <Td>{line.description ?? '—'}</Td>
                    <Td className="text-end tabular">{line.debit_minor ? toDecimal(line.debit_minor, detail.currency) : '—'}</Td>
                    <Td className="text-end tabular">{line.credit_minor ? toDecimal(line.credit_minor, detail.currency) : '—'}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </>
        ) : null}
      </Modal>
    </div>
  )
}
