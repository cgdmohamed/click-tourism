import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { downloadResponse } from '../lib/download'
import { formatDate, todayISO } from '../lib/format'
import { useBaseCurrency } from '../lib/useSetting'
import type { Account, JournalEntry } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, InlineNotice, Loading, Modal, Money, PageHeader, Pagination, SearchableSelect, Select, Spinner, Td, Th as UiTh, cx } from '../components/ui'
import { ActionsMenu } from '../components/ActionsMenu'
import { FilterButton, FilterChips, FilterDrawer, PartyFilterField, useFilters } from '../components/FilterDrawer'
import type { FilterDef } from '../components/FilterDrawer'
import { Icon } from '../components/icons'
import { SourceDocument } from '../components/profile'
import ManagementReportView from './ManagementReports'
import { isManagementReport } from '../lib/managementReports'
import type { ManagementReportKey } from '../lib/managementReports'
import type { IconName } from '../components/icons'
import { localizedName } from '../lib/names'
import { BackgroundExports } from '../components/BackgroundExports'
import { ScheduledReports } from '../components/ScheduledReports'

type CurrencyRow = { code: string; is_active: boolean }
type PartyType = 'customer' | 'supplier' | 'employee'
type CostCenterRow = { id: string; code: string; name_ar: string; name_en: string }

type ReportKey =
  | 'trial-balance'
  | 'general-ledger'
  | 'income-statement'
  | 'balance-sheet'
  | 'aging'
  | 'aging-reconciliation'
  | 'party-statement'
  | 'cost-center-pl'
  | 'cost-center-unallocated'
  | ManagementReportKey

type SourceReference = {
  source_type: string | null
  source_id: number | null
  source_reference: string | null
  source_public_id: string | null
  party_type: string | null
  party_id: number | null
  party: { public_id: string; name_ar: string; name_en: string } | null
}

type LedgerLine = SourceReference & {
  entry_id: string
  number: string
  entry_date: string
  memo: string | null
  description: string | null
  account_code: string
  currency: string
  debit_minor: number
  credit_minor: number
  base_debit_minor: number
  base_credit_minor: number
  balance_minor: number
}

type GeneralLedger = {
  currency: string
  basis: 'base' | 'original'
  account: Account
  from: string
  to: string
  opening_minor: number
  total_debit_minor: number
  total_credit_minor: number
  closing_minor: number
  lines: LedgerLine[]
}

type LedgerResponse = {
  data: GeneralLedger
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

type StatementLine = {
  code: string
  name_ar: string
  name_en: string
  amount_minor: number
}

type TrialBalanceRow = StatementLine & {
  account_id: string
  type: Account['type']
  level: number
  opening_debit_minor: number
  opening_credit_minor: number
  opening_balance_minor: number
  debit_minor: number
  credit_minor: number
  closing_debit_minor: number
  closing_credit_minor: number
  closing_balance_minor: number
  balance_minor: number
  abnormal: boolean
}

type TrialBalance = {
  currency: string
  basis: 'base' | 'original'
  from: string
  to: string
  max_level: number | null
  cost_center_id: number | null
  adjustments: 'include' | 'exclude' | 'only'
  rows: TrialBalanceRow[]
  total_debit_minor: number
  total_credit_minor: number
  total_opening_debit_minor: number
  total_opening_credit_minor: number
  total_closing_debit_minor: number
  total_closing_credit_minor: number
}

type IncomeStatement = {
  currency: string
  from: string
  to: string
  revenue: StatementLine[]
  expenses: StatementLine[]
  cost_of_services: StatementLine[]
  operating_expenses: StatementLine[]
  total_revenue_minor: number
  total_expense_minor: number
  total_cost_of_services_minor: number
  total_operating_expenses_minor: number
  gross_profit_minor: number
  operating_profit_minor: number
  net_profit_minor: number
  comparison: IncomeStatement | null
  variance: {
    total_revenue_minor: number
    total_expense_minor: number
    total_cost_of_services_minor: number
    total_operating_expenses_minor: number
    gross_profit_minor: number
    operating_profit_minor: number
    net_profit_minor: number
  } | null
}

type BalanceSheet = {
  currency: string
  as_of: string
  assets: StatementLine[]
  liabilities: StatementLine[]
  equity: StatementLine[]
  // 'by_account' when every balance-sheet account is classified, else 'mixed' (code-prefix fallback).
  classification: 'by_account' | 'mixed'
  unclassified_accounts?: string[]
  current_assets: StatementLine[]
  non_current_assets: StatementLine[]
  current_liabilities: StatementLine[]
  non_current_liabilities: StatementLine[]
  current_assets_minor: number
  non_current_assets_minor: number
  current_liabilities_minor: number
  non_current_liabilities_minor: number
  current_earnings_minor: number
  total_assets_minor: number
  total_liabilities_minor: number
  total_equity_minor: number
  total_liabilities_and_equity_minor: number
  balanced: boolean
  comparison: BalanceSheet | null
  variance: {
    total_assets_minor: number
    total_liabilities_minor: number
    total_equity_minor: number
    current_assets_minor: number
    non_current_assets_minor: number
    current_liabilities_minor: number
    non_current_liabilities_minor: number
    current_earnings_minor: number
  } | null
}

type Aging = {
  currency: string
  as_of: string
  type: 'ar' | 'ap'
  buckets: Array<{ label: string; amount_minor: number }>
  total_minor: number
}

type ReconciliationBucket = { key: string; amount_minor: number }

type AgingReconciliation = {
  type: 'ar' | 'ap'
  party_type: PartyType
  currency: string
  as_of: string
  ledger: {
    control_account: Account
    signed_balance_minor: number
    balance_minor: number
  }
  operational: {
    open_invoices_minor: number
    invoice_count: number
    unapplied_advances_minor: number
    buckets: ReconciliationBucket[]
  }
  advances: {
    account: Account | null
    ledger_minor: number
    operational_minor: number
    difference_minor: number
    reconciled: boolean
  }
  difference_minor: number
  reconciled: boolean
  differences: Array<{ code: string; label_ar: string; label_en: string; amount_minor: number }>
  parties: Array<{
    party_type: PartyType
    party_id: number
    public_id: string | null
    name_ar: string | null
    name_en: string | null
    ledger_minor: number
    open_invoices_minor: number
    difference_minor: number
  }>
}

type PartyStatement = {
  party_type: PartyType
  party: { id: string; name_ar: string; name_en: string }
  currency: string
  basis: 'base' | 'original'
  from: string
  to: string
  control_account: Account
  opening_minor: number
  total_debit_minor: number
  total_credit_minor: number
  closing_minor: number
  subaccount_closing_minor: number
  tagged_control_closing_minor: number
  reconciliation: {
    ledger_closing_minor: number
    normalized_ledger_minor: number
    operational_open_invoices_minor: number
    unapplied_credits_minor: number
    difference_minor: number
    reconciled: boolean
    differences: Array<{ code: string; label_ar: string; label_en: string; amount_minor: number }>
  }
  lines: LedgerLine[]
  total: number
}

type Paginated<T> = {
  data: T
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

const firstOfYear = () => `${new Date().getFullYear()}-01-01`

/** The filters each report uses (the same parameters the page has always sent). */
function visibleKeys(report: ReportKey, values: Record<string, string>): string[] {
  if (report === 'trial-balance') return ['from', 'to', 'currency', 'max_level', 'cost_center', 'adjustments']
  if (report === 'general-ledger') return ['ledger_account', 'party', 'from', 'to', 'currency']
  if (report === 'income-statement') return ['from', 'to', 'compare', ...(values.compare ? ['compare_from', 'compare_to'] : [])]
  if (report === 'balance-sheet') return ['to', 'compare', ...(values.compare ? ['compare_as_of'] : [])]
  if (report === 'aging' || report === 'aging-reconciliation') return ['aging_type', 'to']
  if (report === 'party-statement') return ['party', 'from', 'to', 'currency']
  return ['from', 'to']
}

const splitParty = (value: string): { type: '' | PartyType; id: string } => {
  const [type, ...rest] = value.split(':')
  return { type: (type as PartyType) || '', id: rest.join(':') }
}

/** Edge cues for a horizontally scrolling strip or table: a fade on the side that has more to scroll to. */
function useScrollEdges(watch?: unknown) {
  const ref = useRef<HTMLDivElement>(null)
  const [edges, setEdges] = useState({ start: false, end: false })
  const update = useCallback(() => {
    const el = ref.current
    if (!el) return
    const offset = Math.abs(el.scrollLeft)
    setEdges({ start: offset > 2, end: offset + el.clientWidth < el.scrollWidth - 2 })
  }, [])
  useEffect(() => {
    update()
    window.addEventListener('resize', update)
    return () => window.removeEventListener('resize', update)
  }, [update, watch])
  return { ref, edges, update }
}

function EdgeCues({ edges }: { edges: { start: boolean; end: boolean } }) {
  const { locale } = useI18n()
  const rtl = locale === 'ar'
  const fade = (toward: 'left' | 'right') => ({ backgroundImage: `linear-gradient(to ${toward}, var(--color-surface-default), transparent)` })
  return (
    <>
      {edges.start ? <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 start-0 z-20 w-8" style={fade(rtl ? 'left' : 'right')} /> : null}
      {edges.end ? <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 end-0 z-20 w-8" style={fade(rtl ? 'right' : 'left')} /> : null}
    </>
  )
}

/** A single scrolling row of buttons; the pressed one is scrolled into view so the user never loses it. */
function ScrollStrip({ children, label, activeKey }: { children: ReactNode; label: string; activeKey: string }) {
  const { ref, edges, update } = useScrollEdges(activeKey)
  useEffect(() => {
    ref.current?.querySelector('[aria-pressed="true"]')?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
  }, [activeKey, ref])
  return (
    <div className="relative">
      <div ref={ref} role="group" aria-label={label} onScroll={update} className="flex gap-2 overflow-x-auto py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {children}
      </div>
      <EdgeCues edges={edges} />
    </div>
  )
}

/** Report tables: horizontal scroll with an edge cue, and (for long ones) a header that stays while the rows scroll. */
function Table({ children, tall = false }: { children: ReactNode; tall?: boolean }) {
  const { ref, edges, update } = useScrollEdges()
  return (
    <div className="relative">
      <div ref={ref} onScroll={update} className={cx('overflow-auto', tall && 'max-h-[min(70vh,44rem)]')} tabIndex={0}>
        <table className="text-medium w-full min-w-[640px] border-collapse">{children}</table>
      </div>
      <EdgeCues edges={edges} />
    </div>
  )
}

function Th({ children, className }: { children?: ReactNode; className?: string }) {
  return <UiTh className={cx('sticky top-0 z-10 whitespace-nowrap', className)}>{children}</UiTh>
}

/** Right-aligned tabular money that never wraps; zero is muted. */
function MoneyTd({ amount, currency, className }: { amount?: number | null; currency: string; className?: string }) {
  return (
    <Td className={cx('tabular whitespace-nowrap text-end', amount === 0 && 'text-text-subtle!', className)}>
      <Money amountMinor={amount} currency={currency} />
    </Td>
  )
}

function ReportSkeleton() {
  const { t } = useI18n()
  return (
    <div role="status" aria-live="polite" className="px-5 py-4">
      <span className="sr-only">{t('loading')}</span>
      <div aria-hidden="true">
        {Array.from({ length: 7 }, (_, row) => (
          <div key={row} className="flex items-center gap-4 border-b border-border-subtle py-3 last:border-b-0">
            <span className="h-4 w-16 rounded-8 bg-surface-subtlest motion-safe:animate-pulse" />
            <span className="h-4 flex-1 rounded-8 bg-surface-subtle motion-safe:animate-pulse" />
            <span className="hidden h-4 w-24 rounded-8 bg-surface-subtle motion-safe:animate-pulse sm:block" />
            <span className="h-4 w-28 rounded-8 bg-surface-subtle motion-safe:animate-pulse" />
          </div>
        ))}
      </div>
    </div>
  )
}

/** Chosen by the drawer: a party type and then the party. Stored as "type:id". */
function PartyControl({ value, set, allLabel, typeLabel }: { value: string; set: (value: string, label?: string) => void; allLabel: string; typeLabel: string }) {
  const { t, locale } = useI18n()
  const { type, id } = splitParty(value)
  const employee = locale === 'ar' ? 'موظف' : 'Employee'
  return (
    <div className="flex flex-col gap-3">
      <Select value={type} aria-label={typeLabel} onChange={(event) => set(event.target.value ? `${event.target.value}:` : '')}>
        <option value="">{allLabel}</option>
        <option value="customer">{t('customer')}</option>
        <option value="supplier">{t('supplier')}</option>
        <option value="employee">{employee}</option>
      </Select>
      {type ? <PartyFilterField kind={type} value={id} set={(next, label) => set(`${type}:${next}`, label)} /> : null}
    </div>
  )
}

export default function Reports() {
  const { t, locale } = useI18n()
  const ar = locale === 'ar'
  const { activeBranch, can } = useAuth()
  const baseCurrency = useBaseCurrency()
  const [report, setReport] = useState<ReportKey>('trial-balance')
  const [entryId, setEntryId] = useState<string | null>(null)
  const [exporting, setExporting] = useState<null | 'csv' | 'xlsx' | 'pdf'>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [ledgerPage, setLedgerPage] = useState(1)
  const [statementPage, setStatementPage] = useState(1)

  const isLedger = report === 'general-ledger'
  const isStatement = report === 'party-statement'

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: report === 'trial-balance' || isLedger || isStatement,
  })

  const costCentersQuery = useQuery({
    queryKey: ['cost-centers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CostCenterRow[] }>('/cost-centers')
      return data.data
    },
    enabled: report === 'trial-balance',
  })

  const accountsQuery = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
    enabled: isLedger,
  })

  // One definition per filter; each report shows the subset it uses (visibleKeys). Values are kept when the
  // user switches to a report that uses the same filter and reset when it does not.
  const asOfReport = report === 'balance-sheet' || report === 'aging' || report === 'aging-reconciliation'
  const comparisonSuffix = ` (${ar ? 'مقارنة' : 'comparison'})`
  const defs: FilterDef[] = [
    { key: 'ledger_account', type: 'custom', label: t('account'), render: (value, set) => {
        const options = (accountsQuery.data ?? []).map((account) => ({ value: account.id, label: localizedName(account, locale), code: account.code }))
        return (
          <SearchableSelect
            value={value}
            options={options}
            placeholder={t('selectAccount')}
            ariaLabel={t('account')}
            onChange={(next) => {
              const account = (accountsQuery.data ?? []).find((row) => row.id === next)
              set(next, account ? `${account.code} — ${localizedName(account, locale)}` : undefined)
            }}
          />
        )
      } },
    { key: 'party', type: 'custom', label: t('party'), format: (value) => {
        const { type } = splitParty(value)
        return type === 'customer' ? t('customer') : type === 'supplier' ? t('supplier') : ar ? 'موظف' : 'Employee'
      }, render: (value, set) => <PartyControl value={value} set={set} allLabel={isStatement ? (ar ? 'اختر النوع' : 'Select type') : t('allParties')} typeLabel={t('party')} /> },
    { key: 'from', type: 'date', label: t('from'), default: firstOfYear() },
    { key: 'to', type: 'date', label: asOfReport ? t('asOf') : t('to'), default: todayISO() },
    { key: 'aging_type', type: 'custom', label: t('type'), default: 'ar', format: (value) => (value === 'ap' ? (ar ? 'دائنون (موردون)' : 'Payables (AP)') : ar ? 'مدينون (عملاء)' : 'Receivables (AR)'), render: (value, set) => (
        <Select value={value} aria-label={t('type')} onChange={(event) => set(event.target.value)}>
          <option value="ar">{ar ? 'مدينون (عملاء)' : 'Receivables (AR)'}</option>
          <option value="ap">{ar ? 'دائنون (موردون)' : 'Payables (AP)'}</option>
        </Select>
      ) },
    { key: 'currency', type: 'select', label: t('currency'), allLabel: t('baseCurrencyBasis'), options: (currenciesQuery.data ?? []).filter((row) => row.is_active).map((row) => ({ value: row.code, label: row.code })) },
    { key: 'max_level', type: 'select', label: ar ? 'أقصى مستوى' : 'Max level', allLabel: ar ? 'كل المستويات' : 'All levels', options: [1, 2, 3, 4, 5].map((level) => ({ value: String(level), label: String(level) })) },
    { key: 'cost_center', type: 'select', label: ar ? 'مركز التكلفة' : 'Cost center', allLabel: ar ? 'كل المراكز' : 'All centers', options: (costCentersQuery.data ?? []).map((center) => ({ value: center.id, label: `${center.code} — ${localizedName(center, locale)}` })) },
    { key: 'adjustments', type: 'select', label: ar ? 'التسويات' : 'Adjustments', allLabel: ar ? 'شاملة' : 'Include', options: [
        { value: 'exclude', label: ar ? 'بدون تسويات' : 'Exclude' },
        { value: 'only', label: ar ? 'التسويات فقط' : 'Only adjustments' },
      ] },
    { key: 'compare', type: 'select', label: ar ? 'مقارنة بفترة سابقة' : 'Compare with prior period', allLabel: ar ? 'بدون مقارنة' : 'No comparison', options: [{ value: '1', label: ar ? 'مقارنة' : 'Compare' }] },
    { key: 'compare_from', type: 'date', label: `${t('from')}${comparisonSuffix}`, default: firstOfYear() },
    { key: 'compare_to', type: 'date', label: `${t('to')}${comparisonSuffix}`, default: todayISO() },
    { key: 'compare_as_of', type: 'date', label: `${t('asOf')}${comparisonSuffix}`, default: todayISO() },
  ]

  const filters = useFilters(defs, {
    onApply: () => {
      setLedgerPage(1)
      setStatementPage(1)
    },
  })
  const applied = filters.applied
  const shownApplied = new Set(visibleKeys(report, applied))
  const shownDraft = new Set(visibleKeys(report, filters.draft))

  // Filters the new report does not use go back to their defaults, one per render (a commit replaces the whole set).
  const firstUnused = defs.find((def) => !shownApplied.has(def.key) && (applied[def.key] ?? '') !== (def.default ?? ''))?.key
  const clearOneRef = useRef(filters.clearOne)
  clearOneRef.current = filters.clearOne
  useEffect(() => {
    if (firstUnused) clearOneRef.current(firstUnused)
  }, [firstUnused])

  const from = applied.from
  const to = applied.to
  const currency = applied.currency
  const ledgerAccount = applied.ledger_account
  const { type: partyType, id: partyId } = splitParty(applied.party)
  const agingType = applied.aging_type === 'ap' ? 'ap' : 'ar'
  const compare = applied.compare !== ''
  const draftParty = splitParty(filters.draft.party)
  const missing: 'account' | 'party' | null =
    isLedger && !ledgerAccount ? 'account' : isStatement && !partyId ? 'party' : null
  const draftMissing: 'account' | 'party' | null =
    isLedger && !filters.draft.ledger_account ? 'account' : isStatement && !draftParty.id ? 'party' : null
  const missingText = (kind: 'account' | 'party') =>
    kind === 'account'
      ? ar ? 'دفتر الأستاذ يحتاج حسابًا: اختر الحساب للمتابعة.' : 'The general ledger needs an account: choose one to continue.'
      : ar ? 'كشف الحساب يحتاج طرفًا: اختر النوع ثم الطرف للمتابعة.' : 'The party statement needs a party: choose the type and then the party to continue.'

  const params = useMemo(() => {
    if (report === 'trial-balance') {
      return {
        from,
        to,
        ...(currency ? { currency } : {}),
        ...(applied.max_level ? { max_level: Number(applied.max_level) } : {}),
        ...(applied.cost_center ? { cost_center_id: applied.cost_center } : {}),
        ...(applied.adjustments ? { adjustments: applied.adjustments } : {}),
      }
    }
    if (report === 'income-statement') {
      return { from, to, ...(compare ? { compare_from: applied.compare_from, compare_to: applied.compare_to } : {}) }
    }
    if (report === 'balance-sheet') {
      return { as_of: to, ...(compare ? { compare_as_of: applied.compare_as_of } : {}) }
    }
    if (report === 'cost-center-pl' || report === 'cost-center-unallocated' || isManagementReport(report)) {
      return { from, to }
    }
    if (report === 'party-statement') {
      return {
        party_type: partyType || 'customer',
        party_id: partyId,
        from,
        to,
        ...(currency ? { currency } : {}),
      }
    }
    return { type: agingType, as_of: to }
  }, [report, from, to, agingType, currency, applied.max_level, applied.cost_center, applied.adjustments, compare, applied.compare_from, applied.compare_to, applied.compare_as_of, partyType, partyId])

  const ledgerParams = useMemo(
    () => ({
      account_id: ledgerAccount,
      from,
      to,
      ...(currency ? { currency } : {}),
      ...(partyType && partyId ? { party_type: partyType, party_id: partyId } : {}),
    }),
    [ledgerAccount, from, to, currency, partyType, partyId],
  )

  const query = useQuery({
    queryKey: ['report', report, params, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: unknown }>(`/reports/${report}`, { params })
      return data.data
    },
    enabled: !isLedger && !isStatement,
  })

  const ledgerQuery = useQuery({
    queryKey: ['report', 'general-ledger', ledgerParams, ledgerPage, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<LedgerResponse>('/reports/general-ledger', {
        params: { ...ledgerParams, page: ledgerPage, per_page: 50 },
      })
      return data
    },
    enabled: isLedger && ledgerAccount !== '',
  })

  const statementQuery = useQuery({
    queryKey: ['report', 'party-statement', params, statementPage, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<PartyStatement>>('/reports/party-statement', {
        params: { ...params, page: statementPage, per_page: 50 },
      })
      return data
    },
    enabled: isStatement && partyId !== '',
  })

  const entryQuery = useQuery({
    queryKey: ['journal-entry', entryId],
    queryFn: async () => {
      const { data } = await api.get<{ data: JournalEntry & { source_type?: string | null } }>(`/journal-entries/${entryId}`)
      return data.data
    },
    enabled: entryId !== null,
  })

  const canExport = can('reports.export') && missing === null

  const download = async (format: 'csv' | 'xlsx' | 'pdf') => {
    setExporting(format)
    setExportError(null)
    try {
      const url = isLedger ? '/reports/general-ledger/export' : `/reports/${report}/export`
      const reportParams = isLedger ? ledgerParams : params
      const response = await api.get<Blob>(url, {
        params: { ...reportParams, format, language: locale },
        responseType: 'blob',
      })
      downloadResponse(response, `${report}.${format}`)
    } catch {
      setExportError(t('exportFailed'))
    } finally {
      setExporting(null)
    }
  }

  const sourceLabel = (sourceType?: string | null) =>
    !sourceType || sourceType === 'manual' ? t('manualEntry') : sourceType.replace(/_/g, ' ')

  const active = isLedger ? ledgerQuery : isStatement ? statementQuery : query

  const tabs: Array<{ key: ReportKey; ar: string; en: string; icon: IconName }> = [
    { key: 'trial-balance', ar: 'ميزان المراجعة', en: 'Trial balance', icon: 'scale' },
    { key: 'general-ledger', ar: 'دفتر الأستاذ', en: 'General ledger', icon: 'ledger' },
    { key: 'income-statement', ar: 'قائمة الدخل', en: 'Income statement', icon: 'trendUp' },
    { key: 'balance-sheet', ar: 'المركز المالي', en: 'Balance sheet', icon: 'landmark' },
    { key: 'aging', ar: 'أعمار الديون', en: 'Aging', icon: 'clock' },
    { key: 'aging-reconciliation', ar: 'مطابقة الأعمار', en: 'Aging reconciliation', icon: 'listChecks' },
    { key: 'party-statement', ar: 'كشف حساب طرف', en: 'Party statement', icon: 'users' },
    { key: 'profitability', ar: 'الربحية', en: 'Profitability', icon: 'trendUp' },
    { key: 'sales', ar: 'المبيعات', en: 'Sales', icon: 'chart' },
    { key: 'commissions', ar: 'العمولات', en: 'Commissions', icon: 'coins' },
    { key: 'tax', ar: 'الضرائب', en: 'Tax', icon: 'receipt' },
    ...(can('cost_centers.view') ? [
      { key: 'cost-center-pl' as const, ar: 'أرباح مراكز التكلفة', en: 'Cost center P&L', icon: 'trendUp' as const },
      { key: 'cost-center-unallocated' as const, ar: 'قيود غير موزعة', en: 'Unallocated entries', icon: 'listChecks' as const },
    ] : []),
  ]

  const tabGroups: Array<{ id: string; ar: string; en: string; tabs: typeof tabs }> = [
    { id: 'financial', ar: 'القوائم المالية', en: 'Financial statements', keys: ['trial-balance', 'general-ledger', 'income-statement', 'balance-sheet'] },
    { id: 'parties', ar: 'الذمم المدينة والدائنة', en: 'Receivables and payables', keys: ['aging', 'aging-reconciliation', 'party-statement'] },
    { id: 'management', ar: 'التقارير الإدارية', en: 'Management', keys: ['profitability', 'sales', 'commissions', 'tax', 'cost-center-pl', 'cost-center-unallocated'] },
  ].map(({ keys, ...group }) => ({ ...group, tabs: tabs.filter((tab) => keys.includes(tab.key)) })).filter((group) => group.tabs.length > 0)
  const activeGroup = tabGroups.find((group) => group.tabs.some((tab) => tab.key === report)) ?? tabGroups[0]
  const activeTab = tabs.find((tab) => tab.key === report)

  // What the report covers, always in view: the period (or the as-of date) as a chip, then the other applied filters.
  const periodText = asOfReport ? `${t('asOf')} ${formatDate(to, locale)}` : `${formatDate(from, locale)} → ${formatDate(to, locale)}`
  const otherChips = filters.chips.filter((chip) => shownApplied.has(chip.key) && chip.key !== 'from' && chip.key !== 'to')
  const periodChanged = filters.chips.some((chip) => chip.key === 'from' || chip.key === 'to')
  const view = {
    ...filters,
    defs: defs.filter((def) => shownDraft.has(def.key)),
    chips: otherChips,
    count: otherChips.length + (periodChanged && (shownApplied.has('from') || shownApplied.has('to')) ? 1 : 0),
  }
  const filterSummary = [periodText, ...otherChips.map((chip) => `${chip.label}: ${chip.value}`)].join(' · ')
  const emptyFor = (icon: IconName) => (
    <EmptyState
      icon={icon}
      title={ar ? 'لا توجد بيانات لهذه الفلاتر' : 'No data for these filters'}
      hint={`${activeTab ? (ar ? activeTab.ar : activeTab.en) : ''}: ${filterSummary}`}
      action={
        <Button variant="secondary" icon="filter" onClick={filters.openDrawer}>
          {ar ? 'تغيير الفلاتر' : 'Change filters'}
        </Button>
      }
    />
  )

  const label = (line: StatementLine) => (localizedName(line, locale))

  const summaryLine = (text: string, amountMinor: number, currency: string, variance?: number, emphasis = false) => (
    <div className={cx('flex items-center justify-between gap-4 border-t border-border-subtle px-5 py-3 text-sm', emphasis && 'font-semibold')}>
      <span>{text}</span>
      <span className="whitespace-nowrap">
        <Money amountMinor={amountMinor} currency={currency} />
        {variance !== undefined ? (
          <span className="ms-2 text-text-subtle">
            (<Money amountMinor={variance} currency={currency} />)
          </span>
        ) : null}
      </span>
    </div>
  )

  const statementRows = (lines: StatementLine[], currency: string, comparisonLines?: StatementLine[]) => {
    const comparing = comparisonLines !== undefined
    if (lines.length === 0 && !(comparisonLines && comparisonLines.length > 0)) return <EmptyState />

    const current = new Map(lines.map((line) => [line.code, line]))
    const prior = new Map((comparisonLines ?? []).map((line) => [line.code, line]))
    const codes = [...new Set([...current.keys(), ...prior.keys()])].sort()

    return (
      <Table>
        <thead>
          <tr>
            <Th>{t('code')}</Th>
            <Th>{t('name')}</Th>
            <Th className="text-end">{t('amount')}</Th>
            {comparing ? <Th className="text-end">{locale === 'ar' ? 'المقارنة' : 'Comparison'}</Th> : null}
            {comparing ? <Th className="text-end">{locale === 'ar' ? 'الفرق' : 'Variance'}</Th> : null}
          </tr>
        </thead>
        <tbody>
          {codes.map((code) => {
            const line = current.get(code) ?? prior.get(code)!
            const now = current.get(code)?.amount_minor ?? 0
            const before = prior.get(code)?.amount_minor ?? 0
            return (
              <tr key={code}>
                <Td className="tabular">{code}</Td>
                <Td>{label(line)}</Td>
                <MoneyTd amount={now} currency={currency} />
                {comparing ? <MoneyTd amount={before} currency={currency} /> : null}
                {comparing ? <MoneyTd amount={now - before} currency={currency} /> : null}
              </tr>
            )
          })}
        </tbody>
      </Table>
    )
  }

  const switchGroup = (group: (typeof tabGroups)[number]) => {
    if (group.id !== activeGroup.id) setReport(group.tabs[0].key)
  }

  return (
    <div>
      <PageHeader
        title={t('reports')}
        subtitle={locale === 'ar' ? 'تقارير مالية مبنية على القيود المُرحّلة فقط' : 'Financial reports based on posted entries only'}
        icon="chart"
        actions={
          can('cost_centers.view') || can('assets.view') ? (
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-small">
              {can('cost_centers.view') ? <Link to="/cost-centers" className="text-brand-1-accent underline">{locale === 'ar' ? 'إدارة مراكز التكلفة' : 'Manage cost centers'}</Link> : null}
              {can('assets.view') ? <Link to="/fixed-assets" className="text-brand-1-accent underline">{locale === 'ar' ? 'الأصول الثابتة والإهلاك' : 'Fixed assets and depreciation'}</Link> : null}
            </div>
          ) : undefined
        }
      />

      <nav aria-label={ar ? 'التقارير' : 'Reports'} className="mb-3 flex flex-col gap-1">
        <ScrollStrip label={ar ? 'مجموعات التقارير' : 'Report groups'} activeKey={activeGroup.id}>
          {tabGroups.map((group) => (
            <button
              key={group.id}
              type="button"
              aria-pressed={activeGroup.id === group.id}
              onClick={() => switchGroup(group)}
              className={cx(
                'text-small shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-border-focus',
                activeGroup.id === group.id ? 'bg-action-primary-background text-action-primary-text' : 'text-text-subtle hover:bg-surface-hovered hover:text-text-default',
              )}
            >
              {ar ? group.ar : group.en}
            </button>
          ))}
        </ScrollStrip>
        <ScrollStrip label={ar ? activeGroup.ar : activeGroup.en} activeKey={report}>
          {activeGroup.tabs.map((tab) => (
            <button
              key={tab.key}
              type="button"
              aria-pressed={report === tab.key}
              onClick={() => setReport(tab.key)}
              className={cx(
                'inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-12 border px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-border-focus',
                report === tab.key
                  ? 'border-border-selected bg-surface-selected text-text-default'
                  : 'border-border-subtle bg-surface-default text-text-subtle hover:text-text-default',
              )}
            >
              <Icon name={tab.icon} className={cx('h-4 w-4', report === tab.key && 'text-brand-1-accent')} />
              {ar ? tab.ar : tab.en}
            </button>
          ))}
        </ScrollStrip>
      </nav>

      <div className="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <FilterButton count={view.count} onClick={filters.openDrawer} expanded={filters.open} />
          <span className="text-small inline-flex items-center gap-1 rounded-full border border-border-subtle bg-surface-default py-0.5 px-3 text-text-default">
            <span className="text-text-subtle">{asOfReport ? t('asOf') : ar ? 'الفترة' : 'Period'}:</span>
            <span className="tabular font-medium">{asOfReport ? formatDate(to, locale) : `${formatDate(from, locale)} → ${formatDate(to, locale)}`}</span>
          </span>
          <FilterChips state={view} />
        </div>
        <div className="flex items-center gap-2">
          {exporting ? (
            <span role="status" className="text-small inline-flex items-center gap-2 text-text-subtle">
              <Spinner className="h-4 w-4" />
              {ar ? 'جارٍ التصدير…' : 'Exporting…'}
            </span>
          ) : null}
          <ActionsMenu
            icon="download"
            items={[
              { key: 'print', label: t('print'), icon: 'printer', onSelect: () => window.print(), disabled: missing !== null },
              ...(can('reports.export')
                ? ([
                    'separator',
                    { key: 'csv', label: t('exportCsv'), icon: 'download', onSelect: () => void download('csv'), disabled: !canExport || exporting !== null, busy: exporting === 'csv' },
                    { key: 'xlsx', label: t('exportXlsx'), icon: 'download', onSelect: () => void download('xlsx'), disabled: !canExport || exporting !== null, busy: exporting === 'xlsx' },
                    { key: 'pdf', label: t('exportPdf'), icon: 'download', onSelect: () => void download('pdf'), disabled: !canExport || exporting !== null, busy: exporting === 'pdf' },
                  ] as const)
                : []),
            ]}
          />
        </div>
      </div>

      <FilterDrawer state={view} title={`${ar ? 'الفلاتر' : 'Filters'} · ${activeTab ? (ar ? activeTab.ar : activeTab.en) : ''}`} applyDisabled={draftMissing !== null}>
        {draftMissing ? <InlineNotice tone="warning">{missingText(draftMissing)}</InlineNotice> : null}
      </FilterDrawer>

      {exportError ? <p role="alert" className="mb-2 text-small text-danger-text">{exportError}</p> : null}
      {currency && (report === 'trial-balance' || isLedger || isStatement) ? (
        <p className="mb-2 text-small text-text-subtle">{t('originalAmountsNote')}: {currency}</p>
      ) : null}
      {report === 'trial-balance' && applied.cost_center ? (
        <p className="mb-2 text-small text-text-subtle">
          {locale === 'ar'
            ? 'مركز التكلفة يعرض الجزء المخصص تحليليًا من كل قيد فقط.'
            : 'Cost-center view reports only the analytical portion allocated to each line.'}
        </p>
      ) : null}

      <Card>
        {active.isLoading && active.fetchStatus !== 'idle' ? <ReportSkeleton /> : null}
        {active.isError ? <ErrorState message={errorMessage(active.error)} onRetry={() => void active.refetch()} /> : null}

        {missing ? (
          <EmptyState
            icon={isLedger ? 'ledger' : 'users'}
            title={ar ? 'اختر الفلاتر لعرض التقرير' : 'Choose filters to run this report'}
            hint={missingText(missing)}
            action={
              <Button icon="filter" onClick={filters.openDrawer}>
                {ar ? 'اختيار الفلاتر' : 'Choose filters'}
              </Button>
            }
          />
        ) : null}

        {isLedger && ledgerQuery.data
          ? (() => {
              const { data, meta } = ledgerQuery.data
              const accountName = localizedName(data.account, locale)
              return (
                <>
                  <CardHeader
                    title={`${t('generalLedger')} · ${data.account.code} — ${accountName}`}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="ledger"
                  />
                  <Table tall>
                    <thead>
                      <tr>
                        <Th>{t('date')}</Th>
                        <Th>{t('entryNumber')}</Th>
                        <Th>{t('description')}</Th>
                        <Th>{t('sourceDocument')}</Th>
                        <Th className="text-end">{t('debit')}</Th>
                        <Th className="text-end">{t('credit')}</Th>
                        <Th className="text-end">{t('balance')}</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {meta.current_page === 1 ? (
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td />
                          <Td>{t('openingBalance')}</Td>
                          <Td />
                          <Td />
                          <Td />
                          <MoneyTd amount={data.opening_minor} currency={data.currency} />
                        </tr>
                      ) : null}
                      {data.lines.map((line, index) => (
                        <tr key={`${line.entry_id}-${index}`}>
                          <Td className="tabular">{formatDate(line.entry_date, locale)}</Td>
                          <Td className="tabular">
                            {can('journal.view') ? (
                              <button
                                type="button"
                                className="rounded-8 text-text-default underline underline-offset-2 hover:bg-surface-hovered"
                                onClick={() => setEntryId(line.entry_id)}
                              >
                                {line.number}
                              </button>
                            ) : (
                              line.number
                            )}
                          </Td>
                          <Td>{line.description || line.memo || '—'}</Td>
                          <Td>
                            <SourceDocument line={line} />
                          </Td>
                          <MoneyTd amount={data.basis === 'original' ? line.debit_minor : line.base_debit_minor} currency={data.currency} />
                          <MoneyTd amount={data.basis === 'original' ? line.credit_minor : line.base_credit_minor} currency={data.currency} />
                          <MoneyTd amount={line.balance_minor} currency={data.currency} />
                        </tr>
                      ))}
                      {meta.current_page === meta.last_page ? (
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td />
                          <Td>{t('closingBalance')}</Td>
                          <Td />
                          <MoneyTd amount={data.total_debit_minor} currency={data.currency} />
                          <MoneyTd amount={data.total_credit_minor} currency={data.currency} />
                          <MoneyTd amount={data.closing_minor} currency={data.currency} />
                        </tr>
                      ) : null}
                    </tbody>
                  </Table>
                  <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setLedgerPage} />
                </>
              )
            })()
          : null}

        {query.data && isManagementReport(report) ? <ManagementReportView report={report} data={query.data} /> : null}
        {query.data && report === 'cost-center-pl' ? (() => {
          const result = query.data as {
            centers: Array<{ center: { id: string; code: string; name_ar: string; name_en: string };
              allocated_revenue_minor: number; allocated_expense_minor: number; allocated_net_minor: number }>;
            grand_allocated_revenue_minor: number; grand_allocated_expense_minor: number; grand_allocated_net_minor: number
          }
          return (
            <Card>
              <CardHeader title={locale === 'ar' ? 'أرباح وخسائر مراكز التكلفة' : 'Cost center profit and loss'} icon="trendUp" />
              <Table tall>
                <thead><tr>
                  <Th>{locale === 'ar' ? 'مركز التكلفة' : 'Cost center'}</Th>
                  <Th className="text-end">{locale === 'ar' ? 'الإيرادات الموزعة' : 'Allocated revenue'}</Th>
                  <Th className="text-end">{locale === 'ar' ? 'التكاليف الموزعة' : 'Allocated expenses'}</Th>
                  <Th className="text-end">{locale === 'ar' ? 'الصافي الموزع' : 'Allocated net'}</Th>
                </tr></thead>
                <tbody>
                  {result.centers.map((row) => <tr key={row.center.id}>
                    <Td>{row.center.code} — {localizedName(row.center, locale)}</Td>
                    <MoneyTd amount={row.allocated_revenue_minor} currency={baseCurrency} />
                    <MoneyTd amount={row.allocated_expense_minor} currency={baseCurrency} />
                    <MoneyTd amount={row.allocated_net_minor} currency={baseCurrency} />
                  </tr>)}
                  <tr className="font-semibold">
                    <Td>{locale === 'ar' ? 'الإجمالي' : 'Total'}</Td>
                    <MoneyTd amount={result.grand_allocated_revenue_minor} currency={baseCurrency} />
                    <MoneyTd amount={result.grand_allocated_expense_minor} currency={baseCurrency} />
                    <MoneyTd amount={result.grand_allocated_net_minor} currency={baseCurrency} />
                  </tr>
                </tbody>
              </Table>
            </Card>
          )
        })() : null}
        {query.data && report === 'cost-center-unallocated' ? (() => {
          const result = query.data as {
            unallocated_line_count: number; unallocated_total_minor: number;
            lines: Array<{ journal_entry: string; entry_date: string; account: string; amount_minor: number }>
          }
          return (
            <Card>
              <CardHeader title={locale === 'ar' ? 'القيود غير الموزعة على مراكز التكلفة' : 'Unallocated journal lines'} icon="listChecks" />
              <div className="px-5 py-3 text-small text-text-subtle">
                {locale === 'ar' ? 'عدد البنود:' : 'Line count:'} {result.unallocated_line_count} ·
                {' '}{locale === 'ar' ? 'إجمالي القيمة:' : 'Total amount:'} <Money amountMinor={result.unallocated_total_minor} currency={baseCurrency}/>
              </div>
              {result.lines.length === 0 ? <EmptyState /> : <Table>
                <thead><tr>
                  <Th>{locale === 'ar' ? 'القيد' : 'Journal entry'}</Th>
                  <Th>{locale === 'ar' ? 'التاريخ' : 'Date'}</Th>
                  <Th>{locale === 'ar' ? 'الحساب' : 'Account'}</Th>
                  <Th className="text-end">{locale === 'ar' ? 'القيمة' : 'Amount'}</Th>
                </tr></thead>
                <tbody>{result.lines.map((line, index) => <tr key={index}>
                  <Td>{line.journal_entry}</Td><Td>{formatDate(line.entry_date, locale)}</Td>
                  <Td>{line.account ?? '—'}</Td>
                  <MoneyTd amount={line.amount_minor} currency={baseCurrency} />
                </tr>)}</tbody>
              </Table>}
            </Card>
          )
        })() : null}
        {query.data && report === 'trial-balance'
          ? (() => {
              const data = query.data as TrialBalance
              return (
                <>
                  <CardHeader
                    title={t('trialBalance')}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="scale"
                  />
                  {data.rows.length === 0 ? (
                    emptyFor('scale')
                  ) : (
                    <Table tall>
                      <thead>
                        <tr>
                          <Th>{t('code')}</Th>
                          <Th>{t('name')}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'افتتاحي مدين' : 'Opening debit'}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'افتتاحي دائن' : 'Opening credit'}</Th>
                          <Th className="text-end">{t('debit')}</Th>
                          <Th className="text-end">{t('credit')}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'ختامي مدين' : 'Closing debit'}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'ختامي دائن' : 'Closing credit'}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.rows.map((row) => (
                          <tr key={row.account_id}>
                            <Td className="tabular">
                              {row.code}
                            </Td>
                            <Td>
                              {localizedName(row, locale)}
                              {row.abnormal ? (
                                <span className="ms-2">
                                  <Badge tone="warning">{locale === 'ar' ? 'رصيد غير طبيعي' : 'Abnormal balance'}</Badge>
                                </span>
                              ) : null}
                            </Td>
                            {[row.opening_debit_minor, row.opening_credit_minor, row.debit_minor, row.credit_minor, row.closing_debit_minor, row.closing_credit_minor].map((amount, index) => (
                              <MoneyTd key={index} amount={amount} currency={data.currency} />
                            ))}
                          </tr>
                        ))}
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td>{t('total')}</Td>
                          {[
                            data.total_opening_debit_minor,
                            data.total_opening_credit_minor,
                            data.total_debit_minor,
                            data.total_credit_minor,
                            data.total_closing_debit_minor,
                            data.total_closing_credit_minor,
                          ].map((amount, index) => (
                            <MoneyTd key={index} amount={amount} currency={data.currency} />
                          ))}
                        </tr>
                      </tbody>
                    </Table>
                  )}
                  <div className="px-5 py-3">
                    <InlineNotice tone={data.total_debit_minor === data.total_credit_minor ? 'positive' : 'warning'}>
                      {data.total_debit_minor === data.total_credit_minor
                        ? locale === 'ar'
                          ? 'حركة الفترة متوازنة'
                          : 'Period movements balance'
                        : locale === 'ar'
                          ? 'حركة الفترة غير متوازنة'
                          : 'Period movements do not balance'}
                    </InlineNotice>
                  </div>
                </>
              )
            })()
          : null}

        {query.data && report === 'income-statement'
          ? (() => {
              const data = query.data as IncomeStatement
              return (
                <>
                  <CardHeader
                    title={t('incomeStatement')}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="trendUp"
                  />
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الإيرادات' : 'Revenue'}</div>
                  {statementRows(data.revenue, data.currency, data.comparison?.revenue)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'تكلفة الخدمات' : 'Cost of services'}</div>
                  {statementRows(data.cost_of_services, data.currency, data.comparison?.cost_of_services)}
                  {summaryLine(locale === 'ar' ? 'مجمل الربح' : 'Gross profit', data.gross_profit_minor, data.currency, data.variance?.gross_profit_minor, true)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'المصروفات التشغيلية' : 'Operating expenses'}</div>
                  {statementRows(data.operating_expenses, data.currency, data.comparison?.operating_expenses)}
                  {summaryLine(locale === 'ar' ? 'ربح التشغيل' : 'Operating profit', data.operating_profit_minor, data.currency, data.variance?.operating_profit_minor, true)}
                  <div className="flex flex-wrap justify-end gap-6 border-t border-border-subtle px-5 py-4 text-sm">
                    <span>
                      {locale === 'ar' ? 'إجمالي الإيرادات' : 'Total revenue'}:{' '}
                      <Money amountMinor={data.total_revenue_minor} currency={data.currency} />
                      {data.variance ? (
                        <span className="ms-2 text-text-subtle">
                          (<Money amountMinor={data.variance.total_revenue_minor} currency={data.currency} />)
                        </span>
                      ) : null}
                    </span>
                    <span>
                      {locale === 'ar' ? 'إجمالي المصروفات' : 'Total expenses'}:{' '}
                      <Money amountMinor={data.total_expense_minor} currency={data.currency} />
                      {data.variance ? (
                        <span className="ms-2 text-text-subtle">
                          (<Money amountMinor={data.variance.total_expense_minor} currency={data.currency} />)
                        </span>
                      ) : null}
                    </span>
                    <span className={data.net_profit_minor >= 0 ? 'font-semibold text-success-text' : 'font-semibold text-danger-text'}>
                      {locale === 'ar' ? 'صافي الربح' : 'Net profit'}:{' '}
                      <Money amountMinor={data.net_profit_minor} currency={data.currency} />
                      {data.variance ? (
                        <span className="ms-2 text-text-subtle">
                          (<Money amountMinor={data.variance.net_profit_minor} currency={data.currency} />)
                        </span>
                      ) : null}
                    </span>
                  </div>
                  {data.comparison ? (
                    <p className="px-5 pb-4 text-small text-text-subtle">
                      {locale === 'ar' ? 'فترة المقارنة' : 'Comparison period'}: {formatDate(data.comparison.from, locale)} →{' '}
                      {formatDate(data.comparison.to, locale)}
                    </p>
                  ) : null}
                </>
              )
            })()
          : null}

        {query.data && report === 'balance-sheet'
          ? (() => {
              const data = query.data as BalanceSheet
              return (
                <>
                  <CardHeader title={t('balanceSheet')} subtitle={`${t('asOf')} ${formatDate(data.as_of, locale)}`} icon="landmark" />
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الأصول المتداولة' : 'Current assets'}</div>
                  {statementRows(data.current_assets, data.currency, data.comparison?.current_assets)}
                  {summaryLine(locale === 'ar' ? 'إجمالي الأصول المتداولة' : 'Total current assets', data.current_assets_minor, data.currency, data.variance?.current_assets_minor)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الأصول غير المتداولة' : 'Non-current assets'}</div>
                  {statementRows(data.non_current_assets, data.currency, data.comparison?.non_current_assets)}
                  {summaryLine(locale === 'ar' ? 'إجمالي الأصول غير المتداولة' : 'Total non-current assets', data.non_current_assets_minor, data.currency, data.variance?.non_current_assets_minor)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الالتزامات المتداولة' : 'Current liabilities'}</div>
                  {statementRows(data.current_liabilities, data.currency, data.comparison?.current_liabilities)}
                  {summaryLine(locale === 'ar' ? 'إجمالي الالتزامات المتداولة' : 'Total current liabilities', data.current_liabilities_minor, data.currency, data.variance?.current_liabilities_minor)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الالتزامات غير المتداولة' : 'Non-current liabilities'}</div>
                  {statementRows(data.non_current_liabilities, data.currency, data.comparison?.non_current_liabilities)}
                  {summaryLine(locale === 'ar' ? 'إجمالي الالتزامات غير المتداولة' : 'Total non-current liabilities', data.non_current_liabilities_minor, data.currency, data.variance?.non_current_liabilities_minor)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'حقوق الملكية' : 'Equity'}</div>
                  {statementRows(data.equity, data.currency, data.comparison?.equity)}
                  {data.current_earnings_minor !== 0 ? (
                    <div className="flex items-center justify-between border-t border-border-subtle px-5 py-3 text-sm">
                      <span>{t('currentEarnings')}</span>
                      <span>
                        <Money amountMinor={data.current_earnings_minor} currency={data.currency} />
                        {data.variance ? (
                          <span className="ms-2 text-text-subtle">
                            (<Money amountMinor={data.variance.current_earnings_minor} currency={data.currency} />)
                          </span>
                        ) : null}
                      </span>
                    </div>
                  ) : null}
                  <div className="flex flex-wrap justify-end gap-6 border-t border-border-subtle px-5 py-4 text-sm">
                    <span>
                      {locale === 'ar' ? 'إجمالي الأصول' : 'Total assets'}:{' '}
                      <Money amountMinor={data.total_assets_minor} currency={data.currency} />
                      {data.variance ? (
                        <span className="ms-2 text-text-subtle">
                          (<Money amountMinor={data.variance.total_assets_minor} currency={data.currency} />)
                        </span>
                      ) : null}
                    </span>
                    <span>
                      {locale === 'ar' ? 'الالتزامات + الحقوق' : 'Liabilities + equity'}:{' '}
                      <Money amountMinor={data.total_liabilities_and_equity_minor} currency={data.currency} />
                    </span>
                  </div>
                  {data.unclassified_accounts && data.unclassified_accounts.length > 0 ? (
                    <div className="px-5 pt-3">
                      <InlineNotice tone="warning">
                        {locale === 'ar'
                          ? `حسابات بدون تصنيف (تم تصنيفها مؤقتًا حسب الرمز): ${data.unclassified_accounts.join('، ')}. حدد تصنيفها من صفحة الحسابات.`
                          : `Unclassified accounts (grouped by code for now): ${data.unclassified_accounts.join(', ')}. Set their classification on the Accounts page.`}
                      </InlineNotice>
                    </div>
                  ) : null}
                  <div className="px-5 py-3">
                    <InlineNotice tone={data.balanced ? 'positive' : 'negative'}>
                      {data.balanced
                        ? locale === 'ar'
                          ? 'الميزانية متوازنة'
                          : 'Balance sheet balances'
                        : locale === 'ar'
                          ? 'الميزانية غير متوازنة'
                          : 'Balance sheet does not balance'}
                    </InlineNotice>
                  </div>
                </>
              )
            })()
          : null}

        {query.data && report === 'aging'
          ? (() => {
              const data = query.data as Aging
              return (
                <>
                  <CardHeader
                    title={t('aging')}
                    subtitle={`${agingType === 'ar' ? (locale === 'ar' ? 'مدينون' : 'Receivables') : locale === 'ar' ? 'دائنون' : 'Payables'} · ${formatDate(data.as_of, locale)}`}
                    icon="clock"
                  />
                  {data.buckets.length === 0 ? (
                    emptyFor('clock')
                  ) : (
                    <Table>
                      <thead>
                        <tr>
                          <Th>{locale === 'ar' ? 'الفترة' : 'Bucket'}</Th>
                          <Th className="text-end">{t('amount')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.buckets.map((bucket) => (
                          <tr key={bucket.label}>
                            <Td>{bucket.label}</Td>
                            <MoneyTd amount={bucket.amount_minor} currency={data.currency} />
                          </tr>
                        ))}
                        <tr className="bg-surface-subtle font-semibold">
                          <Td>{t('total')}</Td>
                          <MoneyTd amount={data.total_minor} currency={data.currency} />
                        </tr>
                      </tbody>
                    </Table>
                  )}
                </>
              )
            })()
          : null}
        {query.data && report === 'aging-reconciliation'
          ? (() => {
              const data = query.data as AgingReconciliation
              const isReceivable = data.type === 'ar'
              return (
                <>
                  <CardHeader
                    title={locale === 'ar' ? 'مطابقة أعمار الديون مع دفتر الأستاذ' : 'Aging reconciliation to the ledger'}
                    subtitle={`${isReceivable ? (locale === 'ar' ? 'مدينون' : 'Receivables') : locale === 'ar' ? 'دائنون' : 'Payables'} · ${formatDate(data.as_of, locale)}`}
                    icon="listChecks"
                  />
                  <div className="grid gap-3 px-5 py-4 md:grid-cols-3">
                    <div className="rounded-12 border border-border-subtle p-4">
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'رصيد حساب المراقبة' : 'Control ledger balance'}</div>
                      <div className="mt-1 text-lg font-semibold">
                        <Money amountMinor={data.ledger.balance_minor} currency={data.currency} />
                      </div>
                      <div className="mt-1 text-small text-text-subtle">
                        {data.ledger.control_account.code} — {localizedName(data.ledger.control_account, locale)}
                      </div>
                    </div>
                    <div className="rounded-12 border border-border-subtle p-4">
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'أرصدة الفواتير المفتوحة' : 'Open invoice balances'}</div>
                      <div className="mt-1 text-lg font-semibold">
                        <Money amountMinor={data.operational.open_invoices_minor} currency={data.currency} />
                      </div>
                      <div className="mt-1 text-small text-text-subtle">
                        {data.operational.invoice_count} {locale === 'ar' ? 'فاتورة' : 'invoices'}
                      </div>
                    </div>
                    <div className={`rounded-12 border p-4 ${data.reconciled ? 'border-success-border' : 'border-danger-border'}`}>
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'الفرق' : 'Difference'}</div>
                      <div className={`mt-1 text-lg font-semibold ${data.reconciled ? 'text-success-text' : 'text-danger-text'}`}>
                        <Money amountMinor={data.difference_minor} currency={data.currency} />
                      </div>
                      <div className={`mt-1 flex items-center gap-1 text-small ${data.reconciled ? 'text-success-text' : 'text-danger-text'}`}>
                        <Icon name={data.reconciled ? 'checkCircle' : 'alertTriangle'} className="h-3.5 w-3.5" />
                        {data.reconciled ? (locale === 'ar' ? 'مطابق' : 'Reconciled') : locale === 'ar' ? 'يحتاج مراجعة' : 'Needs review'}
                      </div>
                    </div>
                  </div>

                  {data.differences.length > 0 ? (
                    <div className="border-t border-border-subtle px-5 py-4">
                      <div className="mb-2 text-sm font-semibold">{locale === 'ar' ? 'فروق موضحة' : 'Explained differences'}</div>
                      <ul className="space-y-2 text-sm">
                        {data.differences.map((difference) => (
                          <li key={difference.code} className="flex flex-wrap items-center justify-between gap-2">
                            <span>{locale === 'ar' ? difference.label_ar : difference.label_en}</span>
                            <Money amountMinor={difference.amount_minor} currency={data.currency} />
                          </li>
                        ))}
                      </ul>
                    </div>
                  ) : null}

                  {data.operational.buckets.length > 0 ? (
                    <Table>
                      <thead>
                        <tr>
                          <Th>{locale === 'ar' ? 'الفترة' : 'Bucket'}</Th>
                          <Th className="text-end">{t('amount')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.operational.buckets.map((bucket) => (
                          <tr key={bucket.key}>
                            <Td>{bucket.key}</Td>
                            <MoneyTd amount={bucket.amount_minor} currency={data.currency} />
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  ) : null}

                  <CardHeader title={locale === 'ar' ? 'المطابقة حسب الطرف' : 'Reconciliation by party'} icon="users" />
                  {data.parties.length === 0 ? (
                    emptyFor('users')
                  ) : (
                    <Table tall>
                      <thead>
                        <tr>
                          <Th>{t('party')}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'دفتر الأستاذ' : 'Ledger'}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'الفواتير المفتوحة' : 'Open invoices'}</Th>
                          <Th className="text-end">{locale === 'ar' ? 'الفرق' : 'Difference'}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.parties.map((party) => (
                          <tr key={`${party.party_type}-${party.party_id}`}>
                            <Td>{locale === 'ar' ? party.name_ar || party.name_en || party.party_id : party.name_en || party.name_ar || party.party_id}</Td>
                            <MoneyTd amount={party.ledger_minor} currency={data.currency} />
                            <MoneyTd amount={party.open_invoices_minor} currency={data.currency} />
                            <MoneyTd amount={party.difference_minor} currency={data.currency} />
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  )}

                  <div className="border-t border-border-subtle px-5 py-4 text-sm">
                    <div className="mb-2 font-semibold">{locale === 'ar' ? 'مطابقة السلف غير المطبقة' : 'Unapplied advances reconciliation'}</div>
                    <div className="flex flex-wrap gap-6">
                      <span>
                        {locale === 'ar' ? 'دفتر الأستاذ' : 'Ledger'}:{' '}
                        <Money amountMinor={data.advances.ledger_minor} currency={data.currency} />
                      </span>
                      <span>
                        {locale === 'ar' ? 'العمليات' : 'Operational'}:{' '}
                        <Money amountMinor={data.advances.operational_minor} currency={data.currency} />
                      </span>
                      <span className={data.advances.reconciled ? 'text-success-text' : 'text-danger-text'}>
                        {locale === 'ar' ? 'الفرق' : 'Difference'}:{' '}
                        <Money amountMinor={data.advances.difference_minor} currency={data.currency} />
                      </span>
                    </div>
                  </div>
                </>
              )
            })()
          : null}

        {isStatement && statementQuery.data
          ? (() => {
              const { data, meta } = statementQuery.data
              const partyName = localizedName(data.party, locale)
              const partyLabel = data.party_type === 'customer' ? t('customer') : data.party_type === 'supplier' ? t('supplier') : locale === 'ar' ? 'موظف' : 'Employee'
              const lineDebit = (line: LedgerLine) => (data.basis === 'original' ? line.debit_minor : line.base_debit_minor)
              const lineCredit = (line: LedgerLine) => (data.basis === 'original' ? line.credit_minor : line.base_credit_minor)
              return (
                <>
                  <CardHeader
                    title={`${locale === 'ar' ? 'كشف حساب' : 'Statement'} · ${partyLabel} ${partyName}`}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="users"
                  />
                  <div className="grid gap-3 px-5 py-4 md:grid-cols-4">
                    {[
                      { label: t('openingBalance'), amount: data.opening_minor },
                      { label: t('debit'), amount: data.total_debit_minor },
                      { label: t('credit'), amount: data.total_credit_minor },
                      { label: t('closingBalance'), amount: data.closing_minor },
                    ].map((item) => (
                      <div key={item.label} className="rounded-12 border border-border-subtle p-4">
                        <div className="text-small text-text-subtle">{item.label}</div>
                        <div className="mt-1 font-semibold">
                          <Money amountMinor={item.amount} currency={data.currency} />
                        </div>
                      </div>
                    ))}
                  </div>
                  <div className="grid gap-3 px-5 pb-4 md:grid-cols-3">
                    <div className="rounded-12 border border-border-subtle p-4">
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'رصيد الحسابات الفرعية' : 'Subaccounts balance'}</div>
                      <div className="mt-1">
                        <Money amountMinor={data.subaccount_closing_minor} currency={data.currency} />
                      </div>
                    </div>
                    <div className="rounded-12 border border-border-subtle p-4">
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'على حساب المراقبة مباشرة' : 'Tagged on the control account'}</div>
                      <div className="mt-1">
                        <Money amountMinor={data.tagged_control_closing_minor} currency={data.currency} />
                      </div>
                    </div>
                    <div className={`rounded-12 border p-4 ${data.reconciliation.reconciled ? 'border-success-border' : 'border-danger-border'}`}>
                      <div className="text-small text-text-subtle">{locale === 'ar' ? 'الفرق مع العمليات' : 'Operational difference'}</div>
                      <div className={`mt-1 font-semibold ${data.reconciliation.reconciled ? 'text-success-text' : 'text-danger-text'}`}>
                        <Money amountMinor={data.reconciliation.difference_minor} currency={data.currency} />
                      </div>
                      <div className={`flex items-center gap-1 text-small ${data.reconciliation.reconciled ? 'text-success-text' : 'text-danger-text'}`}>
                        <Icon name={data.reconciliation.reconciled ? 'checkCircle' : 'alertTriangle'} className="h-3.5 w-3.5" />
                        {data.reconciliation.reconciled ? (locale === 'ar' ? 'مطابق' : 'Reconciled') : locale === 'ar' ? 'يحتاج مراجعة' : 'Needs review'}
                      </div>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-6 border-t border-border-subtle px-5 py-3 text-sm">
                    <span>
                      {locale === 'ar' ? 'أرصدة الفواتير المفتوحة' : 'Open invoice balances'}:{' '}
                      <Money amountMinor={data.reconciliation.operational_open_invoices_minor} currency={data.currency} />
                    </span>
                    <span>
                      {locale === 'ar' ? 'دفعات غير مطبقة' : 'Unapplied credits'}:{' '}
                      <Money amountMinor={data.reconciliation.unapplied_credits_minor} currency={data.currency} />
                    </span>
                  </div>
                  {data.reconciliation.differences.length > 0 ? (
                    <ul className="space-y-2 border-t border-border-subtle px-5 py-4 text-sm">
                      {data.reconciliation.differences.map((difference) => (
                        <li key={difference.code} className="flex flex-wrap items-center justify-between gap-2">
                          <span>{locale === 'ar' ? difference.label_ar : difference.label_en}</span>
                          <Money amountMinor={difference.amount_minor} currency={data.currency} />
                        </li>
                      ))}
                    </ul>
                  ) : null}
                  {data.lines.length === 0 ? (
                    emptyFor('ledger')
                  ) : (
                    <Table tall>
                      <thead>
                        <tr>
                          <Th>{t('date')}</Th>
                          <Th>{t('entryNumber')}</Th>
                          <Th>{t('description')}</Th>
                          <Th>{locale === 'ar' ? 'المستند' : 'Source'}</Th>
                          <Th className="text-end">{t('debit')}</Th>
                          <Th className="text-end">{t('credit')}</Th>
                          <Th className="text-end">{t('balance')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.lines.map((line) => (
                          <tr key={`${line.entry_id}-${line.number}-${line.balance_minor}`}>
                            <Td className="tabular">{formatDate(line.entry_date, locale)}</Td>
                            <Td className="tabular">
                              {can('journal.view') ? (
                                <button
                                  type="button"
                                  className="rounded-8 text-text-default underline underline-offset-2 hover:bg-surface-hovered"
                                  onClick={() => setEntryId(line.entry_id)}
                                >
                                  {line.number}
                                </button>
                              ) : (
                                line.number
                              )}
                            </Td>
                            <Td>{line.description || line.memo || '—'}</Td>
                            <Td>
                              <SourceDocument line={line} />
                            </Td>
                            <MoneyTd amount={lineDebit(line)} currency={data.currency} />
                            <MoneyTd amount={lineCredit(line)} currency={data.currency} />
                            <MoneyTd amount={line.balance_minor} currency={data.currency} />
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  )}
                  <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setStatementPage} />
                </>
              )
            })()
          : null}
      </Card>

      <details className="group mt-4 rounded-16 border border-border-subtle bg-surface-default shadow-2">
        <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3 text-sm font-medium text-text-subtle outline-none hover:text-text-default focus-visible:ring-2 focus-visible:ring-border-focus [&::-webkit-details-marker]:hidden">
          <Icon name="chevronDown" className="h-4 w-4 transition group-open:rotate-180" />
          {locale === 'ar' ? 'خيارات التسليم والجدولة' : 'Delivery options and scheduling'}
        </summary>
        <ScheduledReports />
        {canExport ? (
          <BackgroundExports
            report={isLedger ? 'general-ledger' : report}
            params={Object.fromEntries(Object.entries(isLedger ? ledgerParams : params).filter(([, value]) => value !== undefined && value !== null && value !== '').map(([key, value]) => [key, String(value)]))}
            disabled={!canExport}
          />
        ) : null}
      </details>

      <Modal
        open={entryId !== null}
        title={t('entryDetails')}
        onClose={() => setEntryId(null)}
        footer={
          <Button variant="secondary" onClick={() => setEntryId(null)}>
            {t('closeLabel')}
          </Button>
        }
      >
        {entryQuery.isLoading ? <Loading /> : null}
        {entryQuery.isError ? <ErrorState message={errorMessage(entryQuery.error)} /> : null}
        {entryQuery.data ? (
          <div>
            <dl className="mb-4 grid grid-cols-2 gap-3 text-medium">
              <div>
                <dt className="text-small text-text-subtle">{t('entryNumber')}</dt>
                <dd className="tabular">{entryQuery.data.number}</dd>
              </div>
              <div>
                <dt className="text-small text-text-subtle">{t('date')}</dt>
                <dd className="tabular">{formatDate(entryQuery.data.entry_date, locale)}</dd>
              </div>
              <div>
                <dt className="text-small text-text-subtle">{t('sourceDocument')}</dt>
                <dd>{sourceLabel(entryQuery.data.source_type)}</dd>
              </div>
              <div>
                <dt className="text-small text-text-subtle">{t('description')}</dt>
                <dd>{entryQuery.data.memo || '—'}</dd>
              </div>
            </dl>
            <Table>
              <thead>
                <tr>
                  <Th>{t('account')}</Th>
                  <Th className="text-end">{t('debit')}</Th>
                  <Th className="text-end">{t('credit')}</Th>
                </tr>
              </thead>
              <tbody>
                {(entryQuery.data.lines ?? []).map((line) => (
                  <tr key={line.id}>
                    <Td>
                      <span className="tabular">{line.account?.code}</span>{' '}
                      {locale === 'ar' ? line.account?.name_ar : line.account?.name_en}
                    </Td>
                    <MoneyTd amount={line.debit_minor} currency={entryQuery.data.currency} />
                    <MoneyTd amount={line.credit_minor} currency={entryQuery.data.currency} />
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
