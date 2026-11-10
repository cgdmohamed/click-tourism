import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Chart as ChartJS, ArcElement, CategoryScale, Legend, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js'
import { Doughnut, Line } from 'react-chartjs-2'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { Badge, Card, CardHeader, EmptyState, ErrorState, Input, Loading, PageHeader, Select, Table, Td, Th } from '../components/ui'
import { Icon, type IconName } from '../components/icons'
import { api, errorMessage } from '../lib/api'
import { exponent, formatDate, formatMoney } from '../lib/format'
import type { AttentionItem, DashboardSummary } from '../lib/types'

ChartJS.register(ArcElement, CategoryScale, Legend, LineElement, LinearScale, PointElement, Tooltip)

type DashboardPeriod = 'today' | 'thisMonth' | 'lastMonth' | 'customRange'

/**
 * Dashboard-only metric card: keep the exact amount accessible on hover/focus
 * while compacting large amounts to avoid overflow at narrow breakpoints.
 * Backend still returns integer minor units; no financial precision is lost.
 */
function FinancialMetric({
  label,
  value,
  currency,
  locale,
  icon,
  hint,
  negative = false,
  href,
}: {
  label: string
  value: number | null | undefined
  currency?: string
  locale: 'ar' | 'en'
  icon: IconName
  hint?: string
  negative?: boolean
  href?: string
}) {
  const isMoney = !!currency
  const original = isMoney ? formatMoney(value, currency ?? 'EGP', locale) : value === null || value === undefined ? '—' : new Intl.NumberFormat(locale === 'ar' ? 'ar-EG' : 'en-US').format(value)
  const whole = value === null || value === undefined ? null : value / 10 ** (isMoney ? exponent(currency ?? 'EGP') : 0)
  const compact = whole === null ? '—' : new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', {
    notation: Math.abs(whole) >= 100000 ? 'compact' : 'standard',
    maximumFractionDigits: Math.abs(whole) >= 100000 ? 2 : isMoney ? 2 : 0,
    minimumFractionDigits: 0,
  }).format(whole)
  const body = (
    <div className="flex h-full min-w-0 flex-col gap-3 rounded-16 border border-border-subtle bg-surface-default p-4 shadow-2 sm:p-5">
      <div className="flex min-w-0 items-start justify-between gap-2">
        <span className="min-w-0 text-small font-medium leading-relaxed text-text-subtle">{label}</span>
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-12 bg-surface-selected text-brand-1-accent">
          <Icon name={icon} size={18} />
        </span>
      </div>
      <div className="mt-auto min-w-0" title={original} aria-label={original}>
        <div className={`flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1 ${negative ? 'text-danger-text' : 'text-text-default'}`}>
          <span dir="auto" className="min-w-0 max-w-full break-words text-[clamp(1.25rem,1.7vw,1.85rem)] font-bold leading-tight tabular-nums">
            {compact}
          </span>
          {isMoney && value !== null && value !== undefined && (
            <span className="shrink-0 text-small font-medium opacity-75">{currency}</span>
          )}
        </div>
        {hint && <p className="mt-2 text-small leading-relaxed text-text-subtle">{hint}</p>}
      </div>
    </div>
  )
  return href ? <Link to={href} className="block h-full min-w-0 rounded-16 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-1-accent">{body}</Link> : body
}

export default function Dashboard() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const [period, setPeriod] = useState<DashboardPeriod>('thisMonth')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const baseNote = locale === 'ar' ? 'بالعملة الأساسية للنظام' : 'In the installation base currency'

  const invalidRange = period === 'customRange' && (!from || !to || to < from)
  const summary = useQuery({
    queryKey: ['dashboard-finance', activeBranch?.id, period, from, to],
    queryFn: async () => {
      const { data } = await api.get<{ data: DashboardSummary }>('/dashboard/summary', {
        params: {
          period,
          ...(period === 'customRange' ? { from, to } : {}),
        },
      })
      return data.data
    },
    enabled: !!activeBranch && !invalidRange,
  })
  const data = summary.data
  const money = (amount: number | null | undefined, currency = data?.currency ?? 'EGP') =>
    formatMoney(amount, currency, locale)
  const major = (amount: number) => amount / 10 ** exponent(data?.currency ?? 'EGP')
  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' as const } },
    scales: { y: { beginAtZero: true } },
  }
  const colors = ['#4273c3', '#23a183', '#e2a549', '#d66a71', '#8c75be']
  const shortcuts: Array<{ label: string; href: string; permission: string }> = [
    { label: locale === 'ar' ? 'فواتير العملاء والموردين' : 'Customer & supplier invoices', href: '/invoices', permission: 'invoices.view' },
    { label: locale === 'ar' ? 'تسجيل تحصيل' : 'Record a receipt', href: '/payments', permission: 'payments.create' },
    { label: locale === 'ar' ? 'مدفوعات الموردين' : 'Supplier payments', href: '/supplier-settlements', permission: 'supplier_payments.view' },
    { label: locale === 'ar' ? 'القيود اليومية' : 'Journal entries', href: '/journal', permission: 'journal.view' },
    { label: locale === 'ar' ? 'تسجيل مصروف' : 'Expenses', href: '/expenses', permission: 'expenses.create' },
    { label: locale === 'ar' ? 'كشوف حساب العملاء' : 'Customer statements', href: '/customers', permission: 'customers.view' },
    { label: locale === 'ar' ? 'التسوية البنكية' : 'Bank reconciliation', href: '/reconciliation', permission: 'reconciliation.view' },
    { label: locale === 'ar' ? 'التقارير وميزان المراجعة' : 'Reports & trial balance', href: '/reports', permission: 'reports.view' },
  ]
  const actions = shortcuts.filter((action) => can(action.permission))
  const alerts = (data?.attention_items ?? []).filter((item) =>
    item.type === 'overdue_invoice' ? can('invoices.view') : can('payments.view'),
  )
  const alertsByType = alerts.reduce<Record<string, number>>((acc, item) => {
    acc[item.type] = (acc[item.type] ?? 0) + 1
    return acc
  }, {})
  const cashChartAccounts = (data?.cash_accounts ?? []).filter((account) =>
    account.balance_minor > 0 && account.currency === data?.currency,
  )

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('financialCommandCenter')}
        subtitle={activeBranch ? (locale === 'ar' ? activeBranch.name_ar : activeBranch.name_en) : undefined}
        icon="dashboard"
        actions={
          <button
            type="button"
            onClick={() => void summary.refetch()}
            disabled={summary.isFetching || invalidRange}
            className="rounded-12 border border-border-default bg-surface-default px-4 py-2 text-small text-text-default hover:bg-surface-hovered disabled:opacity-50"
          >
            {summary.isFetching ? t('loading') : t('refresh')}
          </button>
        }
      />

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-16 border border-border-subtle bg-surface-default px-4 py-3 shadow-1">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <label className="shrink-0 text-small font-medium text-text-default" htmlFor="dashboard-period">
            {locale === 'ar' ? 'الفترة' : 'Period'}
          </label>
          <Select id="dashboard-period" className="!w-auto min-w-36 max-w-full" value={period} onChange={(event) => setPeriod(event.target.value as DashboardPeriod)}>
            <option value="today">{t('today')}</option>
            <option value="thisMonth">{t('thisMonth')}</option>
            <option value="lastMonth">{t('lastMonth')}</option>
            <option value="customRange">{t('customRange')}</option>
          </Select>
          {period === 'customRange' && (
            <>
              <Input className="!w-auto min-w-0 max-w-40" aria-label={locale === 'ar' ? 'من تاريخ' : 'From date'} type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
              <Input className="!w-auto min-w-0 max-w-40" aria-label={locale === 'ar' ? 'إلى تاريخ' : 'To date'} type="date" value={to} onChange={(event) => setTo(event.target.value)} />
            </>
          )}
        </div>
        {data && <p className="text-small leading-relaxed text-text-subtle">{formatDate(data.period_from, locale)} – {formatDate(data.period_to, locale)} · {data.currency} · {baseNote}</p>}
        {invalidRange && <p className="w-full text-small text-warning-text">{locale === 'ar' ? 'اختر فترة صحيحة لعرض البيانات' : 'Choose a valid date range'}</p>}
      </div>

      {summary.isLoading ? <Loading /> : null}
      {summary.isError ? <ErrorState message={errorMessage(summary.error)} onRetry={() => void summary.refetch()} /> : null}
      {data && !invalidRange && (
        <>
          <section aria-label={t('quickActions')} className="rounded-16 border border-border-subtle bg-surface-default p-4 shadow-1">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="heading-small">{t('quickActions')}</h2>
              <span className="text-small text-text-subtle">{locale === 'ar' ? 'اختصارات العمليات اليومية' : 'Daily shortcuts'}</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {actions.map((action) => (
                <Link key={action.href} to={action.href} className="inline-flex min-h-10 max-w-full items-center gap-2 rounded-12 border border-border-default bg-surface-subtle px-3 py-2 text-small font-medium text-text-default hover:border-border-focus hover:bg-surface-hovered focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-1-accent">
                  <span className="min-w-0">{action.label}</span><span aria-hidden="true" className="shrink-0 text-brand-1-accent">↗</span>
                </Link>
              ))}
              {actions.length === 0 && <p className="text-small text-text-subtle">{t('noAvailableActions')}</p>}
            </div>
          </section>

          <section aria-label={t('financialKPIs')} className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 2xl:grid-cols-3">
            <FinancialMetric icon="wallet" label={t('cashBankBalances')} value={data.cash_balance_minor} currency={data.currency} locale={locale} href="/treasury" hint={data.cash_balance_mixed_currency ? (locale === 'ar' ? 'عملات مختلطة: راجع أرصدة الحسابات' : 'Mixed currencies: review account balances') : baseNote} />
            <FinancialMetric icon="users" label={t('outstandingReceivables')} value={data.ar_outstanding_minor} currency={data.currency} locale={locale} href="/invoices" hint={locale === 'ar' ? 'فواتير العملاء الصادرة وغير المسددة' : 'Open issued customer invoices'} />
            <FinancialMetric icon="truck" label={t('outstandingPayables')} value={data.ap_outstanding_minor} currency={data.currency} locale={locale} href="/invoices" hint={locale === 'ar' ? 'فواتير الموردين غير المسددة' : 'Open supplier invoices'} />
            <FinancialMetric icon="alertTriangle" label={t('overdueReceivables')} value={data.overdue_ar_minor} currency={data.currency} locale={locale} href="/invoices" negative={data.overdue_ar_minor > 0} />
            <FinancialMetric icon="alertTriangle" label={t('overduePayables')} value={data.overdue_ap_minor} currency={data.currency} locale={locale} href="/invoices" negative={data.overdue_ap_minor > 0} />
            <FinancialMetric icon="coins" label={t('customerAdvances')} value={data.customer_advances_minor} currency={data.currency} locale={locale} href="/payments" hint={locale === 'ar' ? 'تحصيلات لم تُخصص' : 'Unallocated receipts'} />
            <FinancialMetric icon="ledger" label={t('unreconciledBank')} value={data.unreconciled_bank_count} locale={locale} href="/reconciliation" negative={data.unreconciled_bank_count > 0} />
            <FinancialMetric icon="trendUp" label={t('netProfitLoss')} value={data.net_profit_minor} currency={data.currency} locale={locale} href="/reports" negative={data.net_profit_minor < 0} hint={locale === 'ar' ? 'نتيجة القيود المرحلة خلال الفترة' : 'Posted GL result for selected period'} />
          </section>

          <section aria-label={locale === 'ar' ? 'المخططات المالية' : 'Financial charts'} className="grid gap-4 xl:grid-cols-2">
            <Card>
              <CardHeader title={t('cashInflowOutflow')} subtitle={locale === 'ar' ? 'حركات التحصيلات ومدفوعات الموردين (آخر 6 أشهر)' : 'Receipts and supplier payments, last 6 months'} />
              <div className="h-64 px-4 pb-4">
                <Line data={{
                  labels: data.monthly_cash_flow.map((item) => item.month),
                  datasets: [
                    { label: t('inflow'), data: data.monthly_cash_flow.map((item) => major(item.inflow_minor)), borderColor: colors[1], tension: 0.25 },
                    { label: t('outflow'), data: data.monthly_cash_flow.map((item) => major(item.outflow_minor)), borderColor: colors[3], tension: 0.25 },
                  ],
                }} options={chartOptions} />
              </div>
            </Card>
            <Card>
              <CardHeader title={t('receivablesAging')} subtitle={locale === 'ar' ? 'أرصدة الفواتير حسب تاريخ الاستحقاق' : 'Outstanding invoices by due date'} />
              <div className="h-64 px-4 pb-4">
                {data.aging_buckets.every((item) => item.amount_minor === 0) ? <EmptyState /> : (
                  <Doughnut data={{
                    labels: data.aging_buckets.map((item) => locale === 'ar' ? item.label_ar : item.label_en),
                    datasets: [{ data: data.aging_buckets.map((item) => major(item.amount_minor)), backgroundColor: colors }],
                  }} options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }} />
                )}
              </div>
            </Card>
            <Card>
              <CardHeader title={t('revenueVsExpenses')} subtitle={locale === 'ar' ? 'من القيود المرحلة، وليس التدفق النقدي' : 'Posted GL entries, not cash movements'} />
              <div className="h-64 px-4 pb-4">
                <Line data={{
                  labels: data.monthly_profit_loss.map((item) => item.month),
                  datasets: [
                    { label: t('revenue'), data: data.monthly_profit_loss.map((item) => major(item.revenue_minor)), borderColor: colors[0], tension: 0.25 },
                    { label: t('expenses'), data: data.monthly_profit_loss.map((item) => major(item.expenses_minor)), borderColor: colors[3], tension: 0.25 },
                  ],
                }} options={chartOptions} />
              </div>
            </Card>
            <Card>
              <CardHeader title={t('cashBankDistribution')} subtitle={locale === 'ar' ? 'الحسابات الموجبة بالعملة الأساسية فقط' : 'Positive balances in base currency only'} />
              <div className="h-64 px-4 pb-4">
                {cashChartAccounts.length === 0 ? <EmptyState /> : (
                  <Doughnut data={{
                    labels: cashChartAccounts.map((item) => locale === 'ar' ? item.name_ar : item.name_en),
                    datasets: [{ data: cashChartAccounts.map((item) => major(item.balance_minor)), backgroundColor: colors }],
                  }} options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }} />
                )}
              </div>
              {data.cash_accounts.some((item) => item.balance_minor < 0 || item.currency !== data.currency) && (
                <div className="border-t border-border-subtle p-4 text-small text-text-subtle">
                  {locale === 'ar' ? 'أرصدة خارج الرسم (سالبة أو بعملة مختلفة):' : 'Balances excluded from chart (negative or another currency):'}
                  {data.cash_accounts.filter((item) => item.balance_minor < 0 || item.currency !== data.currency).map((item) => (
                    <p key={item.id}>{locale === 'ar' ? item.name_ar : item.name_en}: {money(item.balance_minor, item.currency)}</p>
                  ))}
                </div>
              )}
            </Card>
          </section>

          <Card>
            <CardHeader
              title={t('attentionCenter')}
              subtitle={locale === 'ar' ? 'المشكلات المفتوحة التي تحتاج إجراء، وليس مجرد إحصائيات' : 'Open items that need action'}
              actions={<Badge tone={alerts.length > 0 ? 'warning' : 'positive'}>{alerts.length}</Badge>}
            />
            {alerts.length === 0 ? (
              <div className="p-4"><EmptyState /></div>
            ) : (
              <div className="space-y-3 p-4">
                {Object.entries(alertsByType).map(([type, count]) => (
                  <p className="text-small text-text-subtle" key={type}>
                    {type === 'overdue_invoice'
                      ? (locale === 'ar' ? 'فواتير عملاء متأخرة' : 'Overdue customer invoices')
                      : (locale === 'ar' ? 'تحصيلات غير مخصصة' : 'Unallocated receipts')}: {count}
                  </p>
                ))}
              </div>
            )}
          </Card>
          <Card>
            <CardHeader title={t('workQueue')} subtitle={locale === 'ar' ? 'افتح المستند للتعامل مع المشكلة' : 'Open the document to resolve the issue'} />
            <div className="overflow-x-auto">
              {alerts.length === 0 ? <div className="p-4"><EmptyState /></div> : (
                <Table>
                  <thead><tr>
                    <Th>{t('task')}</Th>
                    <Th>{t('relatedDocument')}</Th>
                    <Th>{t('amount')}</Th>
                    <Th>{t('dueDate')}</Th>
                    <Th>{t('priority')}</Th>
                    <Th>{t('action')}</Th>
                  </tr></thead>
                  <tbody>
                    {alerts.map((item: AttentionItem) => (
                      <tr key={item.id}>
                        <Td>{item.title}</Td>
                        <Td>{item.party_name ?? '—'}</Td>
                        <Td>{money(item.amount_minor, item.currency ?? data.currency)}</Td>
                        <Td>{formatDate(item.due_date, locale)}</Td>
                        <Td><Badge tone={item.severity === 'high' ? 'negative' : 'warning'}>{item.severity === 'high' ? (locale === 'ar' ? 'عاجل' : 'High') : (locale === 'ar' ? 'متوسط' : 'Medium')}</Badge></Td>
                        <Td><Link className="text-small font-medium text-brand-1-accent hover:underline" to={item.action_url}>{locale === 'ar' ? 'فتح' : 'Open'}</Link></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
