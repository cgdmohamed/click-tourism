import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, formatMoney } from '../lib/format'
import type { DashboardSummary, AttentionItem } from '../lib/types'
import { Line, Doughnut } from 'react-chartjs-2'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  ArcElement,
} from 'chart.js'

ChartJS.register(
  Title,
  Tooltip,
  Legend,
  BarElement,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  ArcElement,
)

import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Input,
  Loading,
  Money,
  PageHeader,
  Select,
  StatCard,
  Table,
  Td,
  Th,
} from '../components/ui'

const TODAY_KEY = 'today'

export default function Dashboard() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const [searchParams] = useSearchParams()

  const [period, setPeriod] = useState(searchParams.get('period') || 'thisMonth')
  const [customFrom, setCustomFrom] = useState(searchParams.get('customFrom') || '')
  const [customTo, setCustomTo] = useState(searchParams.get('customTo') || '')
  const [currency, setCurrency] = useState(searchParams.get('currency') || '')

  const query = useQuery<DashboardSummary>({
    queryKey: ['dashboard', activeBranch?.id, period, customFrom, customTo, currency],
    queryFn: async () => {
      const { data } = await api.get<{ data: DashboardSummary }>('/dashboard/summary', {
        params: {
          period,
          from: customFrom,
          to: customTo,
          currency: currency || undefined,
        },
      })
      return data.data
    },
    enabled: !!activeBranch,
  })

  const data = query.data

  const formatMoneyValue = (minor: number | null | undefined, currency: string) => {
    if (minor === null || minor === undefined) return '—'
    return formatMoney(minor, currency, locale)
  }

  const monthlyCashFlowData = useMemo(() => {
    if (!data?.monthly_cash_flow) return null
    return {
      labels: data.monthly_cash_flow.map((m) => m.month),
      datasets: [
        {
          label: t('inflow'),
          data: data.monthly_cash_flow.map((m) => m.inflow_minor),
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          fill: true,
          tension: 0.4,
        },
        {
          label: t('outflow'),
          data: data.monthly_cash_flow.map((m) => m.outflow_minor),
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239, 68, 68, 0.1)',
          fill: true,
          tension: 0.4,
        },
      ],
    }
  }, [data, locale])

  const agingData = useMemo(() => {
    if (!data?.aging_buckets) return null
    return {
      labels: data.aging_buckets.map((b) => (locale === 'ar' ? b.label_ar : b.label_en)),
      datasets: [
        {
          label: t('outstanding'),
          data: data.aging_buckets.map((b) => b.amount_minor),
          backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
        },
      ],
    }
  }, [data, locale])

  const revenueExpenseData = useMemo(() => {
    if (!data?.monthly_cash_flow) return null
    return {
      labels: data.monthly_cash_flow.map((m) => m.month),
      datasets: [
        {
          label: t('revenue'),
          data: data.monthly_cash_flow.map((m) => m.inflow_minor),
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          fill: true,
          tension: 0.4,
        },
        {
          label: t('expenses'),
          data: data.monthly_cash_flow.map((m) => m.outflow_minor),
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239, 68, 68, 0.1)',
          fill: true,
          tension: 0.4,
        },
      ],
    }
  }, [data, locale])

  const cashDistributionData = useMemo(() => {
    if (!data?.cash_accounts) return null
    return {
      labels: data.cash_accounts.map((a) => (locale === 'ar' ? a.name_ar : a.name_en)),
      datasets: [
        {
          data: data.cash_accounts.map((a) => Math.abs(a.balance_minor)),
          backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'],
        },
      ],
    }
  }, [data, locale])

  // Permission checks
  const canCreateInvoice = can('invoices.create')
  const canCreatePayment = can('payments.create')
  const canCreateExpense = can('expenses.create')
  const canCreateJournal = can('journal.create')
  const canViewPartyStatement = can('reports.view')
  const canViewBankReconciliation = can('reconciliation.view')
  const canViewTrialBalance = can('reports.view')

  const quickActions = [
    {
      label: t('newCustomerInvoice'),
      labelEn: 'New Customer Invoice',
      icon: 'fileText',
      href: '/invoices',
      can: canCreateInvoice,
    },
    {
      label: t('newSupplierInvoice'),
      labelEn: 'New Supplier Invoice',
      icon: 'fileText',
      href: '/invoices',
      can: canCreateInvoice,
    },
    {
      label: t('newPayment'),
      labelEn: 'New Receipt',
      icon: 'creditCard',
      href: '/payments',
      can: canCreatePayment,
    },
    {
      label: t('newSupplierPayment'),
      labelEn: 'New Supplier Payment',
      icon: 'creditCard',
      href: '/supplier-settlements',
      can: canCreatePayment,
    },
    {
      label: t('newJournal'),
      labelEn: 'New Journal Entry',
      icon: 'ledger',
      href: '/journal',
      can: canCreateJournal,
    },
    {
      label: t('newExpense'),
      labelEn: 'New Expense',
      icon: 'receipt',
      href: '/expenses',
      can: canCreateExpense,
    },
    {
      label: t('customerProfile'),
      labelEn: 'Customer Statement',
      icon: 'users',
      href: '/customers',
      can: canViewPartyStatement,
    },
    {
      label: t('supplierProfile'),
      labelEn: 'Supplier Statement',
      icon: 'truck',
      href: '/suppliers',
      can: canViewPartyStatement,
    },
    {
      label: t('reconciliation'),
      labelEn: 'Bank Reconciliation',
      icon: 'listChecks',
      href: '/reconciliation',
      can: canViewBankReconciliation,
    },
    {
      label: t('trialBalance'),
      labelEn: 'Trial Balance',
      icon: 'scale',
      href: '/reports',
      can: canViewTrialBalance,
    },
  ]

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!data) return <EmptyState />

  return (
    <div>
      <PageHeader
        title={t('financialCommandCenter')}
        subtitle={activeBranch ? (locale === 'ar' ? activeBranch.name_ar : activeBranch.name_en) : undefined}
        icon="dashboard"
        actions={
          <>
            <Select value={activeBranch?.id || ''} disabled>
              <option value="">{t('allBranches')}</option>
              {activeBranch && (
                <option value={activeBranch.id}>
                  {locale === 'ar' ? activeBranch.name_ar : activeBranch.name_en}
                </option>
              )}
            </Select>
            <Button variant="ghost" onClick={() => setPeriod(TODAY_KEY)}>
              {t(TODAY_KEY)}
            </Button>
            <Button variant="ghost" onClick={() => setPeriod('thisMonth')}>
              {t('thisMonth')}
            </Button>
            <Button variant="ghost" onClick={() => setPeriod('lastMonth')}>
              {t('lastMonth')}
            </Button>
            <Button variant="ghost" onClick={() => setPeriod('customRange')}>
              {t('customRange')}
            </Button>
            {period === 'customRange' && (
              <>
                <Input
                  type="date"
                  value={customFrom}
                  onChange={(e) => setCustomFrom(e.target.value)}
                  dir="ltr"
                />
                <Input
                  type="date"
                  value={customTo}
                  onChange={(e) => setCustomTo(e.target.value)}
                  dir="ltr"
                />
              </>
            )}
            <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
              <option value="">{t('currency')}</option>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </>
        }
      />

      <div className="space-y-6">
        {/* Quick Actions */}
        <Card>
          <CardHeader title={t('quickActions')} icon="play" />
          <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {quickActions
              .filter((a) => a.can)
              .map((action) => (
                <Button key={action.label} variant="secondary" icon={action.icon as any}>
                  <Link to={action.href}>{action.label}</Link>
                </Button>
              ))}
            {quickActions.filter((a) => a.can).length === 0 && (
              <div className="px-4 py-4 text-center text-text-subtle">
                {t('noAvailableActions')}
              </div>
            )}
          </div>
        </Card>

        {/* KPI Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
          <StatCard
            label={t('cashBankBalances')}
            value={formatMoneyValue(data.cash_balance_minor, data.currency)}
            tone="positive"
            icon="wallet"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('outstandingReceivables')}
            value={formatMoneyValue(data.ar_outstanding_minor, data.currency)}
            tone="positive"
            icon="users"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('outstandingPayables')}
            value={formatMoneyValue(data.ap_outstanding_minor, data.currency)}
            tone="negative"
            icon="truck"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('overdueReceivables')}
            value={formatMoneyValue(data.overdue_ar_minor, data.currency)}
            tone={data.overdue_ar_minor > 0 ? 'negative' : 'positive'}
            icon="alertTriangle"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('overduePayables')}
            value={formatMoneyValue(data.overdue_ap_minor, data.currency)}
            tone={data.overdue_ap_minor > 0 ? 'negative' : 'positive'}
            icon="alertTriangle"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('customerAdvances')}
            value={formatMoneyValue(data.customer_advances_minor, data.currency)}
            tone="positive"
            icon="coins"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('unreconciledBank')}
            value={data.unreconciled_bank_count}
            tone={data.unreconciled_bank_count > 0 ? 'negative' : 'positive'}
            icon="ledger"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
          <StatCard
            label={t('netProfitLoss')}
            value={formatMoneyValue(data.net_profit_minor, data.currency)}
            tone={data.net_profit_minor >= 0 ? 'positive' : 'negative'}
            icon="trendUp"
            hint={data.as_of ? t('asOf') + ': ' + formatDate(data.as_of, locale) : undefined}
          />
        </div>

        {/* Charts - Row 1 */}
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader title={t('cashInflowOutflow')} icon="wallet" />
            <div className="h-64">
              <Line
                data={monthlyCashFlowData || { labels: [], datasets: [] }}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false },
                  },
                  scales: {
                    x: { type: 'category', title: { display: true, text: t('month') } },
                    y: { type: 'linear', title: { display: true, text: data.currency }, beginAtZero: true },
                  },
                }}
                height={300}
                width={600}
              />
            </div>
          </Card>
          <Card>
            <CardHeader title={t('receivablesAging')} icon="clock" />
            <div className="h-64">
              <Doughnut
                data={agingData || { labels: [], datasets: [] }}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { position: 'bottom' } },
                }}
                height={300}
                width={600}
              />
            </div>
          </Card>
        </div>

        {/* Charts - Row 2 */}
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader title={t('revenueVsExpenses')} icon="trendUp" />
            <div className="h-64">
              <Line
                data={revenueExpenseData || { labels: [], datasets: [] }}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false },
                  },
                  scales: {
                    x: { type: 'category', title: { display: true, text: t('month') } },
                    y: { type: 'linear', title: { display: true, text: data.currency }, beginAtZero: true },
                  },
                }}
                height={300}
                width={600}
              />
            </div>
          </Card>
          <Card>
            <CardHeader title={t('cashBankDistribution')} icon="wallet" />
            <div className="h-64">
              <Doughnut
                data={cashDistributionData || { labels: [], datasets: [] }}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { position: 'bottom' } },
                }}
                height={300}
                width={600}
              />
            </div>
          </Card>
        </div>

        {/* Attention Center */}
        <Card>
          <CardHeader title={t('attentionCenter')} icon="alertTriangle" />
          <div className="overflow-x-auto">
            <Table>
              <thead>
                <tr>
                  <Th>{t('task')}</Th>
                  <Th>{t('relatedDocument')}</Th>
                  <Th>{t('amount')}</Th>
                  <Th>{t('dueDate')}</Th>
                  <Th>{t('priority')}</Th>
                  <Th className="text-end">{t('action')}</Th>
                </tr>
              </thead>
              <tbody>
                {data.attention_items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-4 text-center text-text-subtle">
                      {t('noActivity')}
                    </td>
                  </tr>
                ) : (
                  data.attention_items.map((item: AttentionItem) => (
                    <tr key={`${item.type}-${item.party_name}-${item.amount_minor}`}>
                      <Td className="font-medium">{item.title}</Td>
                      <Td>{item.party_name || '—'}</Td>
                      <Td className="text-end">
                        {item.amount_minor !== null && item.currency ? (
                          <Money amountMinor={item.amount_minor} currency={item.currency} />
                        ) : (
                          '—'
                        )}
                      </Td>
                      <Td className="text-end">
                        {item.due_date ? formatDate(item.due_date, locale) : '—'}
                      </Td>
                      <Td>
                        <Badge
                          tone={
                            item.severity === 'high'
                              ? 'negative'
                              : item.severity === 'medium'
                              ? 'warning'
                              : 'positive'
                          }
                        >
                          {item.severity}
                        </Badge>
                      </Td>
                      <Td className="text-end">
                        <Button variant="ghost" icon="play">
                          <Link to={item.action_url}>
                            {item.action_label || t('viewProfile')}
                          </Link>
                        </Button>
                      </Td>
                    </tr>
                  ))
                )}
              </tbody>
            </Table>
          </div>
        </Card>

        {/* Work Queue */}
        <Card>
          <CardHeader title={t('workQueue')} icon="target" />
          <div className="overflow-x-auto">
            <Table>
              <thead>
                <tr>
                  <Th>{t('document')}</Th>
                  <Th>{t('relatedDocument')}</Th>
                  <Th>{t('amount')}</Th>
                  <Th>{t('dueDate')}</Th>
                  <Th>{t('priority')}</Th>
                  <Th className="text-end">{t('action')}</Th>
                </tr>
              </thead>
              <tbody>
                {data.attention_items.slice(0, 5).map((item) => (
                  <tr key={`${item.type}-${item.party_name}-${item.amount_minor}-queue`}>
                    <Td className="font-medium">{item.title}</Td>
                    <Td>{item.party_name || '—'}</Td>
                    <Td className="text-end">
                      {item.amount_minor !== null && item.currency ? (
                        <Money amountMinor={item.amount_minor} currency={item.currency} />
                      ) : (
                        '—'
                      )}
                    </Td>
                    <Td className="text-end">
                      {item.due_date ? formatDate(item.due_date, locale) : '—'}
                    </Td>
                    <Td>
                      <Badge
                        tone={
                          item.severity === 'high'
                            ? 'negative'
                            : item.severity === 'medium'
                            ? 'warning'
                            : 'positive'
                        }
                      >
                        {item.severity}
                      </Badge>
                    </Td>
                    <Td className="text-end">
                      <Button variant="ghost" icon="play">
                        <Link to={item.action_url}>
                          {item.action_label || t('viewProfile')}
                        </Link>
                      </Button>
                    </Td>
                  </tr>
                ))}
                {data.attention_items.slice(0, 5).length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-6 py-4 text-center text-text-subtle">
                      {t('noActivity')}
                    </td>
                  </tr>
                )}
              </tbody>
            </Table>
          </div>
        </Card>
      </div>
    </div>
  )
}
