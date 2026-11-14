import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Chart as ChartJS, ArcElement, CategoryScale, Legend, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js'
import { Doughnut, Line } from 'react-chartjs-2'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { Badge, Card, CardHeader, EmptyState, ErrorState, Input, Loading, Money, PageHeader, Select, Table, Td, Th } from '../components/ui'
import { SetupChecklist } from '../components/SetupChecklist'
import { AssistantToggleCard } from '../components/AssistantToggleCard'
import { Icon, type IconName } from '../components/icons'
import { api, errorMessage } from '../lib/api'
import { exponent, formatDate, formatMoney } from '../lib/format'
import type { AttentionItem, DashboardSummary, PipelineReport } from '../lib/types'
import { localizedName } from '../lib/names'

ChartJS.register(ArcElement, CategoryScale, Legend, LineElement, LinearScale, PointElement, Tooltip)

type DashboardPeriod = 'today' | 'thisMonth' | 'lastMonth' | 'customRange'

type MetricSize = 'primary' | 'secondary'

/**
 * Dashboard KPI card. Amounts go through the shared `Money` rule (one nowrap,
 * bidi-isolated run whose font steps down to fit the card, full value in the
 * tooltip); counts use the same card with a plain number. Integer minor units
 * come from the backend untouched, so no precision is lost.
 */
function FinancialMetric({
  label,
  value,
  currency,
  locale,
  icon,
  hint,
  warn = false,
  href,
  size = 'secondary',
  className,
}: {
  label: string
  value: number | null | undefined
  currency?: string
  locale: 'ar' | 'en'
  icon: IconName
  hint?: string
  /** Highlights a non-zero value that needs attention (overdue, unmatched). */
  warn?: boolean
  href?: string
  size?: MetricSize
  className?: string
}) {
  const isZero = value === 0
  const tone = warn && !isZero && value !== null && value !== undefined ? (currency ? 'text-danger-text' : 'text-warning-text') : isZero ? 'text-text-subtle' : 'text-text-default'
  const primary = size === 'primary'
  const count = value === null || value === undefined ? '—' : new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US').format(value)
  const body = (
    <div className={`flex h-full min-w-0 flex-col gap-1.5 rounded-16 border border-border-subtle bg-surface-default shadow-1 ${primary ? 'px-5 py-4' : 'px-4 py-3'}`}>
      <div className="flex min-w-0 items-center gap-2">
        <span className={`flex shrink-0 items-center justify-center rounded-12 bg-surface-selected text-brand-1-accent ${primary ? 'h-8 w-8' : 'h-7 w-7'}`}>
          <Icon name={icon} size={primary ? 18 : 16} />
        </span>
        <span className="min-w-0 text-small font-medium leading-snug text-text-subtle">{label}</span>
      </div>
      <div className={`money-fit leading-tight ${primary ? 'text-[1.875rem]' : 'text-[1.375rem]'} ${isZero ? 'font-medium' : 'font-bold'} ${tone}`}>
        {currency ? (
          <Money amountMinor={value} currency={currency} className={value !== null && value !== undefined && value < 0 ? undefined : tone} />
        ) : (
          <span className="tabular" dir="ltr" title={count}>{count}</span>
        )}
      </div>
      <p className="min-h-[1.25rem] text-small leading-snug text-text-subtle">{hint ?? '\u00a0'}</p>
    </div>
  )
  return href ? <Link to={href} className={`block h-full min-w-0 rounded-16 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-1-accent ${className ?? ''}`}>{body}</Link> : <div className={className}>{body}</div>
}

/** Placeholder with the same grid and card shapes as the loaded KPIs. */
function DashboardSkeleton({ label }: { label: string }) {
  const card = (key: string, className: string, tall: boolean) => (
    <div key={key} className={`flex flex-col gap-3 rounded-16 border border-border-subtle bg-surface-default shadow-1 ${tall ? 'px-5 py-4' : 'px-4 py-3'} ${className}`}>
      <div className="h-4 w-1/2 animate-pulse rounded-12 bg-surface-hovered" />
      <div className={`animate-pulse rounded-12 bg-surface-hovered ${tall ? 'h-9 w-4/5' : 'h-7 w-3/5'}`} />
      <div className="h-3 w-2/3 animate-pulse rounded-12 bg-surface-hovered" />
    </div>
  )
  return (
    <div role="status" aria-live="polite" aria-busy="true" className="space-y-3">
      <span className="sr-only">{label}</span>
      <div className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((i) => card(`p${i}`, i === 2 ? 'min-[560px]:col-span-2 xl:col-span-1' : '', true))}
      </div>
      <div className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 xl:grid-cols-6">
        {[0, 1, 2, 3, 4].map((i) => card(`s${i}`, i < 3 ? 'xl:col-span-2' : i === 3 ? 'xl:col-span-3' : 'min-[560px]:col-span-2 xl:col-span-3', false))}
      </div>
    </div>
  )
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
  const pipeline = useQuery({
    queryKey: ['dashboard-pipeline', activeBranch?.id, period, from, to],
    queryFn: async () => {
      const { data: response } = await api.get<{ data: PipelineReport }>('/reports/pipeline', {
        params: {
          period,
          ...(period === 'customRange' ? { from, to } : {}),
        },
      })
      return response.data
    },
    enabled: !!activeBranch && !invalidRange && can('leads.view'),
  })
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
    { label: locale === 'ar' ? 'تسجيل تحصيل' : 'Record a receipt', href: '/payments?new=payment', permission: 'payments.create' },
    { label: locale === 'ar' ? 'مدفوعات الموردين' : 'Supplier payments', href: '/supplier-settlements', permission: 'supplier_payments.view' },
    { label: locale === 'ar' ? 'القيود اليومية' : 'Journal entries', href: '/journal', permission: 'journal.view' },
    { label: locale === 'ar' ? 'تسجيل مصروف' : 'Expenses', href: '/expenses?new=expense', permission: 'expenses.create' },
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
        subtitle={activeBranch ? (localizedName(activeBranch, locale)) : undefined}
        icon="dashboard"
        actions={
          <>
            <label className="sr-only" htmlFor="dashboard-period">
              {locale === 'ar' ? 'الفترة' : 'Period'}
            </label>
            <Select id="dashboard-period" className="!w-auto min-w-32 max-w-full" value={period} onChange={(event) => setPeriod(event.target.value as DashboardPeriod)}>
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
            <button
              type="button"
              onClick={() => void summary.refetch()}
              disabled={summary.isFetching || invalidRange}
              className="rounded-12 border border-border-default bg-surface-default px-4 py-2 text-small text-text-default hover:bg-surface-hovered disabled:opacity-50"
            >
              {summary.isFetching ? t('loading') : t('refresh')}
            </button>
          </>
        }
      />
      {data && !invalidRange && <p className="-mt-3 text-small leading-relaxed text-text-subtle">{formatDate(data.period_from, locale)} – {formatDate(data.period_to, locale)} · {data.currency} · {baseNote}</p>}
      {invalidRange && <p className="-mt-3 text-small text-warning-text">{locale === 'ar' ? 'اختر فترة صحيحة لعرض البيانات' : 'Choose a valid date range'}</p>}

      {summary.isLoading && !invalidRange ? <DashboardSkeleton label={t('loading')} /> : null}
      {summary.isError ? <Card><ErrorState message={errorMessage(summary.error)} onRetry={() => void summary.refetch()} /></Card> : null}
      {data && !invalidRange && (
        <>
          <section aria-label={t('financialKPIs')} className="space-y-3">
            <div className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 xl:grid-cols-3">
              <FinancialMetric size="primary" icon="wallet" label={t('cashBankBalances')} value={data.cash_balance_minor} currency={data.currency} locale={locale} href="/treasury" hint={data.cash_balance_mixed_currency ? (locale === 'ar' ? 'عملات مختلطة: راجع أرصدة الحسابات' : 'Mixed currencies: review account balances') : baseNote} />
              <FinancialMetric size="primary" icon="users" label={t('outstandingReceivables')} value={data.ar_outstanding_minor} currency={data.currency} locale={locale} href="/invoices" hint={locale === 'ar' ? 'فواتير العملاء الصادرة وغير المسددة' : 'Open issued customer invoices'} />
              <FinancialMetric size="primary" className="min-[560px]:col-span-2 xl:col-span-1" icon="truck" label={t('outstandingPayables')} value={data.ap_outstanding_minor} currency={data.currency} locale={locale} href="/invoices" hint={locale === 'ar' ? 'فواتير الموردين غير المسددة' : 'Open supplier invoices'} />
            </div>
            <div className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2 xl:grid-cols-6">
              <FinancialMetric className="xl:col-span-2" icon="alertTriangle" label={t('overdueReceivables')} value={data.overdue_ar_minor} currency={data.currency} locale={locale} href="/invoices" warn={data.overdue_ar_minor > 0} hint={locale === 'ar' ? 'فواتير عملاء تجاوزت تاريخ الاستحقاق' : 'Customer invoices past due'} />
              <FinancialMetric className="xl:col-span-2" icon="alertTriangle" label={t('overduePayables')} value={data.overdue_ap_minor} currency={data.currency} locale={locale} href="/invoices" warn={data.overdue_ap_minor > 0} hint={locale === 'ar' ? 'فواتير موردين تجاوزت تاريخ الاستحقاق' : 'Supplier invoices past due'} />
              <FinancialMetric className="xl:col-span-2" icon="coins" label={t('customerAdvances')} value={data.customer_advances_minor} currency={data.currency} locale={locale} href="/payments" hint={locale === 'ar' ? 'تحصيلات لم تُخصص' : 'Unallocated receipts'} />
              <FinancialMetric className="xl:col-span-3" icon="trendUp" label={t('netProfitLoss')} value={data.net_profit_minor} currency={data.currency} locale={locale} href="/reports" hint={locale === 'ar' ? 'نتيجة القيود المرحلة خلال الفترة' : 'Posted GL result for selected period'} />
              <FinancialMetric className="min-[560px]:col-span-2 xl:col-span-3" icon="ledger" label={t('unreconciledBank')} value={data.unreconciled_bank_count} locale={locale} href="/reconciliation" warn={data.unreconciled_bank_count > 0} hint={locale === 'ar' ? 'حركات بنكية لم تُطابق بعد' : 'Bank lines not matched yet'} />
            </div>
          </section>

          <section aria-label={t('quickActions')} className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="heading-small">{t('quickActions')}</h2>
              <span className="text-small text-text-subtle">{locale === 'ar' ? 'اختصارات العمليات اليومية' : 'Daily shortcuts'}</span>
            </div>
            <div className="relative">
              <div className="flex gap-2 overflow-x-auto pb-2 pe-8 [scrollbar-width:thin]" tabIndex={0} role="group" aria-label={t('quickActions')}>
                {actions.map((action) => (
                  <Link key={action.href} to={action.href} className="inline-flex min-h-10 shrink-0 items-center gap-2 whitespace-nowrap rounded-12 border border-border-default bg-surface-default px-3 py-2 text-small font-medium text-text-default hover:border-border-focus hover:bg-surface-hovered focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-1-accent">
                    <span>{action.label}</span><span aria-hidden="true" className="shrink-0 text-brand-1-accent">↗</span>
                  </Link>
                ))}
                {actions.length === 0 && <p className="text-small text-text-subtle">{t('noAvailableActions')}</p>}
              </div>
              <div aria-hidden="true" className="pointer-events-none absolute inset-y-0 end-0 w-10 bg-gradient-to-l from-surface-canvas to-transparent rtl:bg-gradient-to-r sm:hidden" />
            </div>
          </section>

          <SetupChecklist />
          <AssistantToggleCard mode="banner" />


          {can('leads.view') && (
            <Card>
              <CardHeader
                title={locale === 'ar' ? 'مسار العملاء المحتملين' : 'Sales pipeline'}
                subtitle={locale === 'ar' ? 'العملاء المسجلون خلال الفترة، بمرحلتهم الحالية' : 'Leads captured in the period, at their current stage'}
                actions={<Link to="/leads" className="text-small font-medium text-brand-1-accent hover:underline">{locale === 'ar' ? 'فتح العملاء المحتملين' : 'Open leads'} ↗</Link>}
              />
              {pipeline.isLoading ? <Loading /> : null}
              {pipeline.isError ? <ErrorState message={errorMessage(pipeline.error)} onRetry={() => void pipeline.refetch()} /> : null}
              {pipeline.data && !pipeline.isLoading && (
                pipeline.data.totals.captured === 0 ? <div className="p-4"><EmptyState /></div> : (
                  <>
                    <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5">
                      {[
                        { label: locale === 'ar' ? 'المسجلون' : 'Captured', value: pipeline.data.totals.captured },
                        { label: locale === 'ar' ? 'مفتوح' : 'Open', value: pipeline.data.totals.open },
                        { label: locale === 'ar' ? 'فاز' : 'Won', value: pipeline.data.totals.won },
                        { label: locale === 'ar' ? 'خسر' : 'Lost', value: pipeline.data.totals.lost },
                        { label: locale === 'ar' ? 'نسبة الفوز' : 'Win rate', value: `${pipeline.data.totals.conversion_rate}%` },
                      ].map((metric) => (
                        <div key={metric.label} className="rounded-12 border border-border-subtle bg-surface-subtle p-3">
                          <div className="text-small text-text-subtle">{metric.label}</div>
                          <div className="mt-1 text-xl font-bold tabular-nums">{metric.value}</div>
                        </div>
                      ))}
                    </div>
                    <div className="grid gap-5 border-t border-border-subtle p-4 xl:grid-cols-3">
                      <div>
                        <h3 className="mb-3 text-small font-semibold text-text-default">{locale === 'ar' ? 'حسب المرحلة' : 'By stage'}</h3>
                        <div className="space-y-3">
                          {pipeline.data.by_stage.filter((stage) => stage.count > 0).map((stage) => (
                            <div key={stage.stage}>
                              <div className="flex items-center justify-between gap-2 text-small">
                                <span className="min-w-0 truncate">{locale === 'ar' ? stage.name_ar : stage.name_en}</span>
                                <span className="shrink-0 tabular-nums text-text-subtle">{stage.count} · {stage.share}%</span>
                              </div>
                              <div className="mt-1 h-2 rounded-full bg-surface-subtle" role="presentation">
                                <div className="h-2 rounded-full bg-brand-1-accent" style={{ width: `${Math.max(stage.share, 2)}%` }} />
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                      <div>
                        <h3 className="mb-3 text-small font-semibold text-text-default">{locale === 'ar' ? 'حسب المصدر' : 'By source'}</h3>
                        {pipeline.data.by_source.map((source) => (
                          <div key={source.source ?? 'unspecified'} className="flex items-center justify-between gap-2 border-b border-border-subtle py-2 text-small last:border-0">
                            <span className="min-w-0 truncate">{locale === 'ar' ? source.name_ar : source.name_en}</span>
                            <span className="shrink-0 tabular-nums text-text-subtle">{source.count} · {source.conversion_rate}%</span>
                          </div>
                        ))}
                      </div>
                      <div>
                        <h3 className="mb-3 text-small font-semibold text-text-default">{locale === 'ar' ? 'حسب المسؤول' : 'By owner'}</h3>
                        {pipeline.data.by_owner.map((owner) => (
                          <div key={owner.owner ?? 'unassigned'} className="flex items-center justify-between gap-2 border-b border-border-subtle py-2 text-small last:border-0">
                            <span className="min-w-0 truncate">{owner.name ?? (locale === 'ar' ? 'غير مُسند' : 'Unassigned')}</span>
                            <span className="shrink-0 tabular-nums text-text-subtle">{owner.count} · {owner.conversion_rate}%</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </>
                )
              )}
            </Card>
          )}

          <section aria-label={locale === 'ar' ? 'المخططات المالية' : 'Financial charts'} className="grid gap-4 xl:grid-cols-2">
            <Card>
              <CardHeader title={t('cashInflowOutflow')} subtitle={locale === 'ar' ? 'حركات التحصيلات ومدفوعات الموردين (آخر 6 أشهر)' : 'Receipts and supplier payments, last 6 months'} />
              <div className="h-64 px-4 pb-4">
                <Line role="img" aria-label={t('cashInflowOutflow')} data={{
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
                  <Doughnut role="img" aria-label={t('receivablesAging')} data={{
                    labels: data.aging_buckets.map((item) => locale === 'ar' ? item.label_ar : item.label_en),
                    datasets: [{ data: data.aging_buckets.map((item) => major(item.amount_minor)), backgroundColor: colors }],
                  }} options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }} />
                )}
              </div>
            </Card>
            <Card>
              <CardHeader title={t('revenueVsExpenses')} subtitle={locale === 'ar' ? 'من القيود المرحلة، وليس التدفق النقدي' : 'Posted GL entries, not cash movements'} />
              <div className="h-64 px-4 pb-4">
                <Line role="img" aria-label={t('revenueVsExpenses')} data={{
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
                  <Doughnut role="img" aria-label={t('cashBankDistribution')} data={{
                    labels: cashChartAccounts.map((item) => localizedName(item, locale)),
                    datasets: [{ data: cashChartAccounts.map((item) => major(item.balance_minor)), backgroundColor: colors }],
                  }} options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }} />
                )}
              </div>
              {data.cash_accounts.some((item) => item.balance_minor < 0 || item.currency !== data.currency) && (
                <div className="border-t border-border-subtle p-4 text-small text-text-subtle">
                  {locale === 'ar' ? 'أرصدة خارج الرسم (سالبة أو بعملة مختلفة):' : 'Balances excluded from chart (negative or another currency):'}
                  {data.cash_accounts.filter((item) => item.balance_minor < 0 || item.currency !== data.currency).map((item) => (
                    <p key={item.id}>{localizedName(item, locale)}: {money(item.balance_minor, item.currency)}</p>
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

      <AssistantToggleCard mode="row" />
    </div>
  )
}
