import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import type { Account } from '../lib/types'
import { Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Money, PageHeader, Pagination, SearchableSelect, Select, Spinner, Table, Td, Th, cx } from '../components/ui'

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
  const { activeBranch } = useAuth()
  const [report, setReport] = useState<ReportKey>('trial-balance')
  const [from, setFrom] = useState(firstOfYear())
  const [to, setTo] = useState(todayISO())
  const [agingType, setAgingType] = useState<'ar' | 'ap'>('ar')
  const [ledgerAccount, setLedgerAccount] = useState('')
  const [ledgerPage, setLedgerPage] = useState(1)

  const params = useMemo(() => {
    if (report === 'trial-balance') return { from, to }
    if (report === 'income-statement') return { from, to }
    if (report === 'balance-sheet') return { as_of: to }
    return { type: agingType, as_of: to }
  }, [report, from, to, agingType])

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
    queryKey: ['report', 'general-ledger', ledgerAccount, from, to, ledgerPage, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<LedgerResponse>('/reports/general-ledger', {
        params: { account_id: ledgerAccount, from, to, page: ledgerPage, per_page: 50 },
      })
      return data
    },
    enabled: isLedger && ledgerAccount !== '',
  })

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
          <Button
            variant="secondary"
            onClick={() => void active.refetch()}
            disabled={active.isFetching || (isLedger && ledgerAccount === '')}
          >
            {active.isFetching ? <Spinner className="h-4 w-4" /> : t('run')}
          </Button>
        </div>

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
                          <Td className="tabular">{line.number}</Td>
                          <Td>{line.description || line.memo || '—'}</Td>
                          <Td className="text-end">
                            <Money amountMinor={line.base_debit_minor} currency={data.currency} />
                          </Td>
                          <Td className="text-end">
                            <Money amountMinor={line.base_credit_minor} currency={data.currency} />
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
    </div>
  )
}
