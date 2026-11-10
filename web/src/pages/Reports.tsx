import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import type { Account, JournalEntry } from '../lib/types'
import { Button, Card, CardHeader, EmptyState, ErrorState, Field, InlineNotice, Input, Loading, Modal, Money, PageHeader, Pagination, SearchableSelect, Select, Spinner, Table, Td, Th, cx } from '../components/ui'
import { Icon } from '../components/icons'
import type { IconName } from '../components/icons'

type CurrencyRow = { code: string; is_active: boolean }
type PartyRow = { id: string; name_ar: string; name_en: string }
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
  total_revenue_minor: number
  total_expense_minor: number
  net_profit_minor: number
  comparison: IncomeStatement | null
  variance: {
    total_revenue_minor: number
    total_expense_minor: number
    net_profit_minor: number
  } | null
}

type BalanceSheet = {
  currency: string
  as_of: string
  assets: StatementLine[]
  liabilities: StatementLine[]
  equity: StatementLine[]
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

export default function Reports() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const [report, setReport] = useState<ReportKey>('trial-balance')
  const [currency, setCurrency] = useState('')
  const [partyType, setPartyType] = useState<'' | PartyType>('')
  const [partyId, setPartyId] = useState('')
  const [entryId, setEntryId] = useState<string | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)
  const [from, setFrom] = useState(firstOfYear())
  const [to, setTo] = useState(todayISO())
  const [agingType, setAgingType] = useState<'ar' | 'ap'>('ar')
  const [ledgerAccount, setLedgerAccount] = useState('')
  const [ledgerPage, setLedgerPage] = useState(1)
  const [maxLevel, setMaxLevel] = useState('')
  const [costCenterId, setCostCenterId] = useState('')
  const [adjustments, setAdjustments] = useState<'include' | 'exclude' | 'only'>('include')
  const [compare, setCompare] = useState(false)
  const [compareFrom, setCompareFrom] = useState(firstOfYear())
  const [compareTo, setCompareTo] = useState(todayISO())
  const [compareAsOf, setCompareAsOf] = useState(todayISO())
  const [statementPage, setStatementPage] = useState(1)

  const params = useMemo(() => {
    if (report === 'trial-balance') {
      return {
        from,
        to,
        ...(currency ? { currency } : {}),
        ...(maxLevel ? { max_level: Number(maxLevel) } : {}),
        ...(costCenterId ? { cost_center_id: costCenterId } : {}),
        ...(adjustments !== 'include' ? { adjustments } : {}),
      }
    }
    if (report === 'income-statement') {
      return { from, to, ...(compare ? { compare_from: compareFrom, compare_to: compareTo } : {}) }
    }
    if (report === 'balance-sheet') {
      return { as_of: to, ...(compare ? { compare_as_of: compareAsOf } : {}) }
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
  }, [report, from, to, agingType, currency, maxLevel, costCenterId, adjustments, compare, compareFrom, compareTo, compareAsOf, partyType, partyId])

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

  const isLedger = report === 'general-ledger'
  const isStatement = report === 'party-statement'

  const query = useQuery({
    queryKey: ['report', report, params, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: unknown }>(`/reports/${report}`, { params })
      return data.data
    },
    enabled: !isLedger && !isStatement,
  })

  const accountsQuery = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
    enabled: isLedger,
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

  const partiesQuery = useQuery({
    queryKey: ['report-parties', partyType],
    queryFn: async () => {
      const endpoint = partyType === 'customer' ? '/customers' : partyType === 'supplier' ? '/suppliers' : '/employees'
      const { data } = await api.get<{ data: PartyRow[] }>(endpoint, { params: { per_page: 100 } })
      return data.data
    },
    enabled: partyType !== '' && (isLedger || isStatement),
  })

  const entryQuery = useQuery({
    queryKey: ['journal-entry', entryId],
    queryFn: async () => {
      const { data } = await api.get<{ data: JournalEntry & { source_type?: string | null } }>(`/journal-entries/${entryId}`)
      return data.data
    },
    enabled: entryId !== null,
  })

  const canExport =
    can('reports.export') &&
    (isLedger ? ledgerAccount !== '' : isStatement ? partyId !== '' : true)

  const download = async (format: 'csv' | 'xlsx' | 'pdf') => {
    setExporting(true)
    setExportError(null)
    try {
      const url = isLedger ? '/reports/general-ledger/export' : `/reports/${report}/export`
      const reportParams = isLedger ? ledgerParams : params
      const response = await api.get<Blob>(url, {
        params: { ...reportParams, format, language: locale },
        responseType: 'blob',
      })
      const disposition = String(response.headers['content-disposition'] ?? '')
      const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `${report}.${format}`
      const href = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = href
      link.download = filename
      link.click()
      URL.revokeObjectURL(href)
    } catch {
      setExportError(t('exportFailed'))
    } finally {
      setExporting(false)
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
  ]

  const label = (line: StatementLine) => (locale === 'ar' ? line.name_ar : line.name_en)

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
                <Td className="text-end">
                  <Money amountMinor={now} currency={currency} />
                </Td>
                {comparing ? (
                  <Td className="text-end">
                    <Money amountMinor={before} currency={currency} />
                  </Td>
                ) : null}
                {comparing ? (
                  <Td className="text-end">
                    <Money amountMinor={now - before} currency={currency} />
                  </Td>
                ) : null}
              </tr>
            )
          })}
        </tbody>
      </Table>
    )
  }

  return (
    <div>
      <PageHeader
        title={t('reports')}
        subtitle={locale === 'ar' ? 'تقارير مالية مبنية على القيود المُرحّلة فقط' : 'Financial reports based on posted entries only'}
        icon="chart"
      />

      <div className="mb-4 flex flex-wrap gap-2">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setReport(tab.key)}
            className={cx(
              'inline-flex items-center gap-2 rounded-12 border px-3 py-2 text-sm font-medium transition',
              report === tab.key
                ? 'border-border-selected bg-surface-selected text-text-default'
                : 'border-border-subtle bg-surface-default text-text-subtle hover:text-text-default',
            )}
          >
            <Icon name={tab.icon} className={cx('h-4 w-4', report === tab.key && 'text-brand-1-accent')} />
            {locale === 'ar' ? tab.ar : tab.en}
          </button>
        ))}
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
          {isLedger ? (
            <Field label={t('account')}>
              <SearchableSelect
                value={ledgerAccount}
                onChange={(value) => {
                  setLedgerAccount(value)
                  setLedgerPage(1)
                }}
                placeholder={t('selectAccount')}
                options={(accountsQuery.data ?? []).map((account) => ({
                  value: account.id,
                  label: `${account.code} — ${locale === 'ar' ? account.name_ar : account.name_en}`,
                }))}
              />
            </Field>
          ) : null}
          {isStatement ? (
            <>
              <Field label={t('party')}>
                <Select
                  value={partyType}
                  onChange={(event) => {
                    setPartyType(event.target.value as '' | PartyType)
                    setPartyId('')
                    setStatementPage(1)
                  }}
                >
                  <option value="">{locale === 'ar' ? 'اختر النوع' : 'Select type'}</option>
                  <option value="customer">{t('customer')}</option>
                  <option value="supplier">{t('supplier')}</option>
                  <option value="employee">{locale === 'ar' ? 'موظف' : 'Employee'}</option>
                </Select>
              </Field>
              {partyType ? (
                <Field label={partyType === 'customer' ? t('customer') : partyType === 'supplier' ? t('supplier') : locale === 'ar' ? 'موظف' : 'Employee'}>
                  <SearchableSelect
                    value={partyId}
                    onChange={(value) => {
                      setPartyId(value)
                      setStatementPage(1)
                    }}
                    options={(partiesQuery.data ?? []).map((party) => ({
                      value: party.id,
                      label: locale === 'ar' ? party.name_ar : party.name_en,
                    }))}
                  />
                </Field>
              ) : null}
            </>
          ) : null}
          {report !== 'balance-sheet' && report !== 'aging' && report !== 'aging-reconciliation' ? (
            <Field label={t('from')}>
              <Input
                type="date"
                value={from}
                onChange={(event) => {
                  setFrom(event.target.value)
                  setLedgerPage(1)
                  setStatementPage(1)
                }}
                dir="ltr"
              />
            </Field>
          ) : null}
          <Field label={report === 'trial-balance' || report === 'income-statement' || isLedger || isStatement ? t('to') : t('asOf')}>
            <Input
              type="date"
              value={to}
              onChange={(event) => {
                setTo(event.target.value)
                setLedgerPage(1)
                setStatementPage(1)
              }}
              dir="ltr"
            />
          </Field>
          {report === 'aging' || report === 'aging-reconciliation' ? (
            <Field label={t('type')}>
              <Select value={agingType} onChange={(event) => setAgingType(event.target.value as 'ar' | 'ap')}>
                <option value="ar">{locale === 'ar' ? 'مدينون (عملاء)' : 'Receivables (AR)'}</option>
                <option value="ap">{locale === 'ar' ? 'دائنون (موردون)' : 'Payables (AP)'}</option>
              </Select>
            </Field>
          ) : null}
          {report === 'trial-balance' || isLedger || isStatement ? (
            <Field label={t('currency')}>
              <Select
                value={currency}
                onChange={(event) => {
                  setCurrency(event.target.value)
                  setLedgerPage(1)
                  setStatementPage(1)
                }}
              >
                <option value="">{t('baseCurrencyBasis')}</option>
                {(currenciesQuery.data ?? [])
                  .filter((row) => row.is_active)
                  .map((row) => (
                    <option key={row.code} value={row.code}>
                      {row.code}
                    </option>
                  ))}
              </Select>
            </Field>
          ) : null}
          {report === 'trial-balance' ? (
            <>
              <Field label={locale === 'ar' ? 'أقصى مستوى' : 'Max level'}>
                <Select value={maxLevel} onChange={(event) => setMaxLevel(event.target.value)}>
                  <option value="">{locale === 'ar' ? 'كل المستويات' : 'All levels'}</option>
                  {[1, 2, 3, 4, 5].map((level) => (
                    <option key={level} value={level}>
                      {level}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={locale === 'ar' ? 'مركز التكلفة' : 'Cost center'}>
                <Select value={costCenterId} onChange={(event) => setCostCenterId(event.target.value)}>
                  <option value="">{locale === 'ar' ? 'كل المراكز' : 'All centers'}</option>
                  {(costCentersQuery.data ?? []).map((center) => (
                    <option key={center.id} value={center.id}>
                      {center.code} — {locale === 'ar' ? center.name_ar : center.name_en}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={locale === 'ar' ? 'التسويات' : 'Adjustments'}>
                <Select value={adjustments} onChange={(event) => setAdjustments(event.target.value as 'include' | 'exclude' | 'only')}>
                  <option value="include">{locale === 'ar' ? 'شاملة' : 'Include'}</option>
                  <option value="exclude">{locale === 'ar' ? 'بدون تسويات' : 'Exclude'}</option>
                  <option value="only">{locale === 'ar' ? 'التسويات فقط' : 'Only adjustments'}</option>
                </Select>
              </Field>
            </>
          ) : null}
          {isLedger ? (
            <>
              <Field label={t('party')}>
                <Select
                  value={partyType}
                  onChange={(event) => {
                    setPartyType(event.target.value as '' | PartyType)
                    setPartyId('')
                    setLedgerPage(1)
                  }}
                >
                  <option value="">{t('allParties')}</option>
                  <option value="customer">{t('customer')}</option>
                  <option value="supplier">{t('supplier')}</option>
                  <option value="employee">{locale === 'ar' ? 'موظف' : 'Employee'}</option>
                </Select>
              </Field>
              {partyType ? (
                <Field label={partyType === 'customer' ? t('customer') : partyType === 'supplier' ? t('supplier') : locale === 'ar' ? 'موظف' : 'Employee'}>
                  <SearchableSelect
                    value={partyId}
                    onChange={(value) => {
                      setPartyId(value)
                      setLedgerPage(1)
                    }}
                    clearLabel={t('allParties')}
                    options={(partiesQuery.data ?? []).map((party) => ({
                      value: party.id,
                      label: locale === 'ar' ? party.name_ar : party.name_en,
                    }))}
                  />
                </Field>
              ) : null}
            </>
          ) : null}
          {report === 'income-statement' || report === 'balance-sheet' ? (
            <>
              <label className="flex items-center gap-2 pb-2 text-sm text-text-subtle">
                <input type="checkbox" checked={compare} onChange={(event) => setCompare(event.target.checked)} />
                {locale === 'ar' ? 'مقارنة بفترة سابقة' : 'Compare with prior period'}
              </label>
              {compare && report === 'income-statement' ? (
                <>
                  <Field label={`${t('from')} (${locale === 'ar' ? 'مقارنة' : 'comparison'})`}>
                    <Input type="date" value={compareFrom} onChange={(event) => setCompareFrom(event.target.value)} dir="ltr" />
                  </Field>
                  <Field label={`${t('to')} (${locale === 'ar' ? 'مقارنة' : 'comparison'})`}>
                    <Input type="date" value={compareTo} onChange={(event) => setCompareTo(event.target.value)} dir="ltr" />
                  </Field>
                </>
              ) : null}
              {compare && report === 'balance-sheet' ? (
                <Field label={`${t('asOf')} (${locale === 'ar' ? 'مقارنة' : 'comparison'})`}>
                  <Input type="date" value={compareAsOf} onChange={(event) => setCompareAsOf(event.target.value)} dir="ltr" />
                </Field>
              ) : null}
            </>
          ) : null}
          <Button
            variant="secondary"
            icon="play"
            onClick={() => void active.refetch()}
            disabled={active.isFetching || (isLedger && ledgerAccount === '') || (isStatement && partyId === '')}
          >
            {active.isFetching ? <Spinner className="h-4 w-4" /> : t('run')}
          </Button>
          <Button
            variant="secondary"
            icon="printer"
            onClick={() => window.print()}
            disabled={(isLedger && ledgerAccount === '') || (isStatement && partyId === '')}
          >
            {t('print')}
          </Button>
          {canExport ? (
            <Button variant="secondary" icon="download" onClick={() => void download('csv')} disabled={exporting}>
              {exporting ? <Spinner className="h-4 w-4" /> : t('exportCsv')}
            </Button>
          ) : null}
          {canExport ? (
            <Button variant="secondary" icon="download" onClick={() => void download('xlsx')} disabled={exporting}>
              {t('exportXlsx')}
            </Button>
          ) : null}
          {canExport ? (
            <Button variant="secondary" icon="download" onClick={() => void download('pdf')} disabled={exporting}>
              {t('exportPdf')}
            </Button>
          ) : null}
        </div>

        {exportError ? <p className="px-5 py-2 text-small text-danger-text">{exportError}</p> : null}
        {currency && (report === 'trial-balance' || isLedger || isStatement) ? (
          <p className="px-5 py-2 text-small text-text-subtle">{t('originalAmountsNote')}: {currency}</p>
        ) : null}
        {report === 'trial-balance' && costCenterId ? (
          <p className="px-5 py-2 text-small text-text-subtle">
            {locale === 'ar'
              ? 'مركز التكلفة يعرض الجزء المخصص تحليليًا من كل قيد فقط.'
              : 'Cost-center view reports only the analytical portion allocated to each line.'}
          </p>
        ) : null}

        {active.isLoading && active.fetchStatus !== 'idle' ? <Loading /> : null}
        {active.isError ? <ErrorState message={errorMessage(active.error)} onRetry={() => void active.refetch()} /> : null}

        {isLedger && ledgerAccount === '' ? <EmptyState icon="ledger" title={t('selectAccountForLedger')} /> : null}
        {isStatement && partyId === '' ? (
          <EmptyState icon="users" title={locale === 'ar' ? 'اختر نوع الطرف وحسابه' : 'Select a party type and party'} />
        ) : null}

        {isLedger && ledgerQuery.data
          ? (() => {
              const { data, meta } = ledgerQuery.data
              const accountName = locale === 'ar' ? data.account.name_ar : data.account.name_en
              return (
                <>
                  <CardHeader
                    title={`${t('generalLedger')} · ${data.account.code} — ${accountName}`}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="ledger"
                  />
                  <Table>
                    <thead>
                      <tr>
                        <Th>{t('date')}</Th>
                        <Th>{t('entryNumber')}</Th>
                        <Th>{t('description')}</Th>
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
                          <Td className="text-end">
                            <Money amountMinor={data.opening_minor} currency={data.currency} />
                          </Td>
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
                          <Td className="text-end">
                            <Money
                              amountMinor={data.basis === 'original' ? line.debit_minor : line.base_debit_minor}
                              currency={data.currency}
                            />
                          </Td>
                          <Td className="text-end">
                            <Money
                              amountMinor={data.basis === 'original' ? line.credit_minor : line.base_credit_minor}
                              currency={data.currency}
                            />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={line.balance_minor} currency={data.currency} />
                          </Td>
                        </tr>
                      ))}
                      {meta.current_page === meta.last_page ? (
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td />
                          <Td>{t('closingBalance')}</Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_debit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_credit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={data.closing_minor} currency={data.currency} />
                          </Td>
                        </tr>
                      ) : null}
                    </tbody>
                  </Table>
                  <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setLedgerPage} />
                </>
              )
            })()
          : null}

        {query.data && report === 'trial-balance'
          ? (() => {
              const data = query.data as TrialBalance
              const openingNet = data.total_opening_debit_minor - data.total_opening_credit_minor
              const closingNet = data.total_closing_debit_minor - data.total_closing_credit_minor
              return (
                <>
                  <CardHeader
                    title={t('trialBalance')}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
                    icon="scale"
                  />
                  {data.rows.length === 0 ? (
                    <EmptyState icon="scale" />
                  ) : (
                    <Table>
                      <thead>
                        <tr>
                          <Th>{t('code')}</Th>
                          <Th>{t('name')}</Th>
                          <Th className="text-end">{t('openingBalance')}</Th>
                          <Th className="text-end">{t('debit')}</Th>
                          <Th className="text-end">{t('credit')}</Th>
                          <Th className="text-end">{t('closingBalance')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.rows.map((row) => (
                          <tr key={row.account_id}>
                            <Td className="tabular">
                              {row.code}
                              {row.level > 1 ? <span className="ms-2 text-small text-text-subtle">L{row.level}</span> : null}
                            </Td>
                            <Td>{locale === 'ar' ? row.name_ar : row.name_en}</Td>
                            <Td className="text-end">
                              <Money amountMinor={row.opening_balance_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={row.debit_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={row.credit_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={row.closing_balance_minor} currency={data.currency} />
                            </Td>
                          </tr>
                        ))}
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td>{t('total')}</Td>
                          <Td className="text-end">
                            <Money amountMinor={openingNet} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_debit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_credit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={closingNet} currency={data.currency} />
                          </Td>
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
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'المصروفات' : 'Expenses'}</div>
                  {statementRows(data.expenses, data.currency, data.comparison?.expenses)}
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
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الأصول' : 'Assets'}</div>
                  {statementRows(data.assets, data.currency, data.comparison?.assets)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الالتزامات' : 'Liabilities'}</div>
                  {statementRows(data.liabilities, data.currency, data.comparison?.liabilities)}
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
                    <EmptyState icon="clock" />
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
                            <Td className="text-end">
                              <Money amountMinor={bucket.amount_minor} currency={data.currency} />
                            </Td>
                          </tr>
                        ))}
                        <tr className="bg-surface-subtle font-semibold">
                          <Td>{t('total')}</Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_minor} currency={data.currency} />
                          </Td>
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
                        {data.ledger.control_account.code} — {locale === 'ar' ? data.ledger.control_account.name_ar : data.ledger.control_account.name_en}
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
                            <Td className="text-end">
                              <Money amountMinor={bucket.amount_minor} currency={data.currency} />
                            </Td>
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  ) : null}

                  <CardHeader title={locale === 'ar' ? 'المطابقة حسب الطرف' : 'Reconciliation by party'} icon="users" />
                  {data.parties.length === 0 ? (
                    <EmptyState icon="users" />
                  ) : (
                    <Table>
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
                            <Td className="text-end">
                              <Money amountMinor={party.ledger_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={party.open_invoices_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={party.difference_minor} currency={data.currency} />
                            </Td>
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
              const partyName = locale === 'ar' ? data.party.name_ar : data.party.name_en
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
                    <EmptyState icon="ledger" />
                  ) : (
                    <Table>
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
                              <span>{sourceLabel(line.source_type)}</span>
                              {line.source_reference ? <span className="ms-2 tabular text-text-subtle">{line.source_reference}</span> : null}
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={lineDebit(line)} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={lineCredit(line)} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={line.balance_minor} currency={data.currency} />
                            </Td>
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
                    <Td className="text-end">
                      <Money amountMinor={line.debit_minor} currency={entryQuery.data.currency} />
                    </Td>
                    <Td className="text-end">
                      <Money amountMinor={line.credit_minor} currency={entryQuery.data.currency} />
                    </Td>
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
