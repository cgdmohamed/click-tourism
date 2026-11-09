import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import type { Account, JournalEntry } from '../lib/types'
import { Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Modal, Money, PageHeader, Pagination, SearchableSelect, Select, Spinner, Table, Td, Th, cx } from '../components/ui'

type CurrencyRow = { code: string; is_active: boolean }
type PartyRow = { id: string; name_ar: string; name_en: string }

type ReportKey = 'trial-balance' | 'general-ledger' | 'income-statement' | 'balance-sheet' | 'aging'

type LedgerLine = {
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
  debit_minor: number
  credit_minor: number
  balance_minor: number
}

type TrialBalance = {
  currency: string
  from: string
  to: string
  rows: TrialBalanceRow[]
  total_debit_minor: number
  total_credit_minor: number
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
}

type BalanceSheet = {
  currency: string
  as_of: string
  assets: StatementLine[]
  liabilities: StatementLine[]
  equity: StatementLine[]
  total_assets_minor: number
  total_liabilities_minor: number
  total_equity_minor: number
}

type Aging = {
  currency: string
  as_of: string
  type: 'ar' | 'ap'
  buckets: Array<{ label: string; amount_minor: number }>
  total_minor: number
}

const firstOfYear = () => `${new Date().getFullYear()}-01-01`

export default function Reports() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const [report, setReport] = useState<ReportKey>('trial-balance')
  const [currency, setCurrency] = useState('')
  const [partyType, setPartyType] = useState<'' | 'customer' | 'supplier'>('')
  const [partyId, setPartyId] = useState('')
  const [entryId, setEntryId] = useState<string | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)
  const [from, setFrom] = useState(firstOfYear())
  const [to, setTo] = useState(todayISO())
  const [agingType, setAgingType] = useState<'ar' | 'ap'>('ar')
  const [ledgerAccount, setLedgerAccount] = useState('')
  const [ledgerPage, setLedgerPage] = useState(1)

  const params = useMemo(() => {
    if (report === 'trial-balance') return { from, to, ...(currency ? { currency } : {}) }
    if (report === 'income-statement') return { from, to }
    if (report === 'balance-sheet') return { as_of: to }
    return { type: agingType, as_of: to }
  }, [report, from, to, agingType, currency])

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

  const query = useQuery({
    queryKey: ['report', report, params, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: unknown }>(`/reports/${report}`, { params })
      return data.data
    },
    enabled: !isLedger,
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

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: report === 'trial-balance' || isLedger,
  })

  const partiesQuery = useQuery({
    queryKey: ['report-parties', partyType],
    queryFn: async () => {
      const { data } = await api.get<{ data: PartyRow[] }>(partyType === 'customer' ? '/customers' : '/suppliers', {
        params: { per_page: 100 },
      })
      return data.data
    },
    enabled: isLedger && partyType !== '',
  })

  const entryQuery = useQuery({
    queryKey: ['journal-entry', entryId],
    queryFn: async () => {
      const { data } = await api.get<{ data: JournalEntry & { source_type?: string | null } }>(`/journal-entries/${entryId}`)
      return data.data
    },
    enabled: entryId !== null,
  })

  const canExport = can('reports.export') && (isLedger ? ledgerAccount !== '' : report === 'trial-balance')

  const downloadCsv = async () => {
    setExporting(true)
    setExportError(null)
    try {
      const url = isLedger ? '/reports/general-ledger/export' : '/reports/trial-balance/export'
      const response = await api.get<Blob>(url, { params: isLedger ? ledgerParams : params, responseType: 'blob' })
      const disposition = String(response.headers['content-disposition'] ?? '')
      const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `${report}.csv`
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

  const active = isLedger ? ledgerQuery : query

  const tabs: Array<{ key: ReportKey; ar: string; en: string }> = [
    { key: 'trial-balance', ar: 'ميزان المراجعة', en: 'Trial balance' },
    { key: 'general-ledger', ar: 'دفتر الأستاذ', en: 'General ledger' },
    { key: 'income-statement', ar: 'قائمة الدخل', en: 'Income statement' },
    { key: 'balance-sheet', ar: 'المركز المالي', en: 'Balance sheet' },
    { key: 'aging', ar: 'أعمار الديون', en: 'Aging' },
  ]

  const label = (line: StatementLine) => (locale === 'ar' ? line.name_ar : line.name_en)

  const statementRows = (lines: StatementLine[], currency: string) =>
    lines.length === 0 ? (
      <EmptyState />
    ) : (
      <Table>
        <thead>
          <tr>
            <Th>{t('code')}</Th>
            <Th>{t('name')}</Th>
            <Th className="text-end">{t('amount')}</Th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line) => (
            <tr key={line.code}>
              <Td className="tabular">{line.code}</Td>
              <Td>{label(line)}</Td>
              <Td className="text-end">
                <Money amountMinor={line.amount_minor} currency={currency} />
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
    )

  return (
    <div>
      <PageHeader
        title={t('reports')}
        subtitle={locale === 'ar' ? 'تقارير مالية مبنية على القيود المُرحّلة فقط' : 'Financial reports based on posted entries only'}
      />

      <div className="mb-4 flex flex-wrap gap-2">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setReport(tab.key)}
            className={cx(
              'rounded-12 border px-3 py-2 text-sm font-medium transition',
              report === tab.key
                ? 'border-border-selected bg-surface-selected text-text-default'
                : 'border-border-subtle bg-surface-default text-text-subtle hover:text-text-default',
            )}
          >
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
          {report !== 'balance-sheet' && report !== 'aging' ? (
            <Field label={t('from')}>
              <Input
                type="date"
                value={from}
                onChange={(event) => {
                  setFrom(event.target.value)
                  setLedgerPage(1)
                }}
                dir="ltr"
              />
            </Field>
          ) : null}
          <Field label={report === 'trial-balance' || report === 'income-statement' || isLedger ? t('to') : t('asOf')}>
            <Input
              type="date"
              value={to}
              onChange={(event) => {
                setTo(event.target.value)
                setLedgerPage(1)
              }}
              dir="ltr"
            />
          </Field>
          {report === 'aging' ? (
            <Field label={t('type')}>
              <Select value={agingType} onChange={(event) => setAgingType(event.target.value as 'ar' | 'ap')}>
                <option value="ar">{locale === 'ar' ? 'مدينون (عملاء)' : 'Receivables (AR)'}</option>
                <option value="ap">{locale === 'ar' ? 'دائنون (موردون)' : 'Payables (AP)'}</option>
              </Select>
            </Field>
          ) : null}
          {report === 'trial-balance' || isLedger ? (
            <Field label={t('currency')}>
              <Select
                value={currency}
                onChange={(event) => {
                  setCurrency(event.target.value)
                  setLedgerPage(1)
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
          {isLedger ? (
            <>
              <Field label={t('party')}>
                <Select
                  value={partyType}
                  onChange={(event) => {
                    setPartyType(event.target.value as '' | 'customer' | 'supplier')
                    setPartyId('')
                    setLedgerPage(1)
                  }}
                >
                  <option value="">{t('allParties')}</option>
                  <option value="customer">{t('customer')}</option>
                  <option value="supplier">{t('supplier')}</option>
                </Select>
              </Field>
              {partyType ? (
                <Field label={partyType === 'customer' ? t('customer') : t('supplier')}>
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
          <Button
            variant="secondary"
            onClick={() => void active.refetch()}
            disabled={active.isFetching || (isLedger && ledgerAccount === '')}
          >
            {active.isFetching ? <Spinner className="h-4 w-4" /> : t('run')}
          </Button>
          {canExport ? (
            <Button variant="secondary" onClick={() => void downloadCsv()} disabled={exporting}>
              {exporting ? <Spinner className="h-4 w-4" /> : t('exportCsv')}
            </Button>
          ) : null}
        </div>

        {exportError ? <p className="px-5 py-2 text-small text-danger-text">{exportError}</p> : null}
        {currency && (report === 'trial-balance' || isLedger) ? (
          <p className="px-5 py-2 text-small text-text-subtle">{t('originalAmountsNote')}: {currency}</p>
        ) : null}

        {active.isLoading && active.fetchStatus !== 'idle' ? <Loading /> : null}
        {active.isError ? <ErrorState message={errorMessage(active.error)} onRetry={() => void active.refetch()} /> : null}

        {isLedger && ledgerAccount === '' ? <EmptyState title={t('selectAccountForLedger')} /> : null}

        {isLedger && ledgerQuery.data
          ? (() => {
              const { data, meta } = ledgerQuery.data
              const accountName = locale === 'ar' ? data.account.name_ar : data.account.name_en
              return (
                <>
                  <CardHeader
                    title={`${t('generalLedger')} · ${data.account.code} — ${accountName}`}
                    subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`}
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
              return (
                <>
                  <CardHeader title={t('trialBalance')} subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`} />
                  {data.rows.length === 0 ? (
                    <EmptyState />
                  ) : (
                    <Table>
                      <thead>
                        <tr>
                          <Th>{t('code')}</Th>
                          <Th>{t('name')}</Th>
                          <Th className="text-end">{t('debit')}</Th>
                          <Th className="text-end">{t('credit')}</Th>
                          <Th className="text-end">{t('balance')}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.rows.map((row) => (
                          <tr key={row.account_id}>
                            <Td className="tabular">{row.code}</Td>
                            <Td>{locale === 'ar' ? row.name_ar : row.name_en}</Td>
                            <Td className="text-end">
                              <Money amountMinor={row.debit_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={row.credit_minor} currency={data.currency} />
                            </Td>
                            <Td className="text-end">
                              <Money amountMinor={row.balance_minor} currency={data.currency} />
                            </Td>
                          </tr>
                        ))}
                        <tr className="bg-surface-subtle font-semibold">
                          <Td />
                          <Td>{t('total')}</Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_debit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={data.total_credit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            {data.total_debit_minor === data.total_credit_minor ? '✓' : '⚠'}
                          </Td>
                        </tr>
                      </tbody>
                    </Table>
                  )}
                </>
              )
            })()
          : null}

        {query.data && report === 'income-statement'
          ? (() => {
              const data = query.data as IncomeStatement
              return (
                <>
                  <CardHeader title={t('incomeStatement')} subtitle={`${formatDate(data.from, locale)} → ${formatDate(data.to, locale)}`} />
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الإيرادات' : 'Revenue'}</div>
                  {statementRows(data.revenue, data.currency)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'المصروفات' : 'Expenses'}</div>
                  {statementRows(data.expenses, data.currency)}
                  <div className="flex flex-wrap justify-end gap-6 border-t border-border-subtle px-5 py-4 text-sm">
                    <span>
                      {locale === 'ar' ? 'إجمالي الإيرادات' : 'Total revenue'}:{' '}
                      <Money amountMinor={data.total_revenue_minor} currency={data.currency} />
                    </span>
                    <span>
                      {locale === 'ar' ? 'إجمالي المصروفات' : 'Total expenses'}:{' '}
                      <Money amountMinor={data.total_expense_minor} currency={data.currency} />
                    </span>
                    <span className={data.net_profit_minor >= 0 ? 'font-semibold text-success-text' : 'font-semibold text-danger-text'}>
                      {locale === 'ar' ? 'صافي الربح' : 'Net profit'}:{' '}
                      <Money amountMinor={data.net_profit_minor} currency={data.currency} />
                    </span>
                  </div>
                </>
              )
            })()
          : null}

        {query.data && report === 'balance-sheet'
          ? (() => {
              const data = query.data as BalanceSheet
              return (
                <>
                  <CardHeader title={t('balanceSheet')} subtitle={`${t('asOf')} ${formatDate(data.as_of, locale)}`} />
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الأصول' : 'Assets'}</div>
                  {statementRows(data.assets, data.currency)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'الالتزامات' : 'Liabilities'}</div>
                  {statementRows(data.liabilities, data.currency)}
                  <div className="px-5 py-3 text-sm font-semibold">{locale === 'ar' ? 'حقوق الملكية' : 'Equity'}</div>
                  {statementRows(data.equity, data.currency)}
                  <div className="flex flex-wrap justify-end gap-6 border-t border-border-subtle px-5 py-4 text-sm">
                    <span>
                      {locale === 'ar' ? 'إجمالي الأصول' : 'Total assets'}:{' '}
                      <Money amountMinor={data.total_assets_minor} currency={data.currency} />
                    </span>
                    <span>
                      {locale === 'ar' ? 'الالتزامات + الحقوق' : 'Liabilities + equity'}:{' '}
                      <Money
                        amountMinor={data.total_liabilities_minor + data.total_equity_minor}
                        currency={data.currency}
                      />
                    </span>
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
                  />
                  {data.buckets.length === 0 ? (
                    <EmptyState />
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
