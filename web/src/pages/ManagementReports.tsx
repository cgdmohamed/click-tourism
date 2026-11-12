import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { formatDate } from '../lib/format'
import type { ManagementReportKey } from '../lib/managementReports'
import { CardHeader, EmptyState, InlineNotice, Money, Table, Td, Th } from '../components/ui'
import { localizedName } from '../lib/names'

type Names = { name_ar: string; name_en: string }
type Reconciliation = { reconciled: boolean }

type Profitability = {
  currency: string
  from: string
  to: string
  by_booking: Array<{
    booking: ({ id: string; number: string; status: string; customer_name_ar: string; customer_name_en: string }) | null
    revenue_minor: number
    direct_cost_minor: number
    margin_minor: number
  }>
  by_service_type: Array<{ service_type: string | null; revenue_minor: number; direct_cost_minor: number; margin_minor: number }>
  total_revenue_minor: number
  total_direct_cost_minor: number
  total_margin_minor: number
  reconciliation: Reconciliation
}

type Sales = {
  currency: string
  from: string
  to: string
  by_branch: Array<{ branch: Names & { id: string }; revenue_minor: number }>
  by_user: Array<{ user: { id: string; name: string } | null; bookings_count: number; revenue_minor: number }>
  total_revenue_minor: number
  reconciliation: Reconciliation
}

type Commissions = {
  currency: string
  from: string
  to: string
  rows: Array<{
    beneficiary_type: string
    beneficiary: (Names & { id: string }) | null
    pending_minor: number
    expense_minor: number
    paid_minor: number
  }>
  total_pending_minor: number
  total_expense_minor: number
  total_paid_minor: number
  reconciliation: Reconciliation
}

type Tax = {
  currency: string
  from: string
  to: string
  enabled: boolean
  rate: string
  inclusive: boolean
  rows: Array<{
    side: 'output' | 'input'
    account: { code: string; name_ar: string; name_en: string }
    amount_minor: number
  }>
  total_output_minor: number
  total_input_minor: number
  total_net_payable_minor: number
}

export default function ManagementReportView({ report, data }: { report: ManagementReportKey; data: unknown }) {
  const { locale } = useI18n()
  const { can } = useAuth()
  const ar = locale === 'ar'
  const text = (en: string, arabic: string) => (ar ? arabic : en)
  const name = (n: Names | null | undefined) => (n ? (localizedName(n, ar ? 'ar' : 'en')) : text('Not linked to a booking', 'غير مرتبط بحجز'))
  const header = (title: string, d: { from: string; to: string }) => (
    <CardHeader title={title} subtitle={`${formatDate(d.from, locale)} → ${formatDate(d.to, locale)}`} icon="chart" />
  )
  const money = (value: number, currency: string) => <Money amountMinor={value} currency={currency} />
  const notReconciled = (r: Reconciliation) =>
    r.reconciled ? null : (
      <InlineNotice tone="warning">{text('Totals do not match the income statement for this period.', 'الإجماليات لا تطابق قائمة الدخل لهذه الفترة.')}</InlineNotice>
    )

  if (report === 'profitability') {
    const d = data as Profitability
    return (
      <>
        {header(text('Profitability', 'الربحية'), d)}
        {notReconciled(d.reconciliation)}
        <h3 className="px-5 pt-4 text-sm font-semibold">{text('By booking', 'حسب الحجز')}</h3>
        {d.by_booking.length === 0 ? (
          <EmptyState icon="chart" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>{text('Booking', 'الحجز')}</Th>
                <Th>{text('Customer', 'العميل')}</Th>
                <Th className="text-end">{text('Revenue', 'الإيراد')}</Th>
                <Th className="text-end">{text('Direct cost', 'التكلفة المباشرة')}</Th>
                <Th className="text-end">{text('Margin', 'الهامش')}</Th>
              </tr>
            </thead>
            <tbody>
              {d.by_booking.map((row) => (
                <tr key={row.booking?.id ?? 'unlinked'}>
                  <Td className="tabular">
                    {row.booking ? (
                      can('bookings.view') ? (
                        <Link to={`/bookings?booking_id=${row.booking.id}`} className="text-brand-1-accent underline">
                          {row.booking.number}
                        </Link>
                      ) : (
                        row.booking.number
                      )
                    ) : (
                      text('Not linked to a booking', 'غير مرتبط بحجز')
                    )}
                  </Td>
                  <Td>{row.booking ? (ar ? row.booking.customer_name_ar : row.booking.customer_name_en) : '—'}</Td>
                  <Td className="text-end">{money(row.revenue_minor, d.currency)}</Td>
                  <Td className="text-end">{money(row.direct_cost_minor, d.currency)}</Td>
                  <Td className="text-end">{money(row.margin_minor, d.currency)}</Td>
                </tr>
              ))}
              <tr className="font-semibold">
                <Td colSpan={2}>{text('Total', 'الإجمالي')}</Td>
                <Td className="text-end">{money(d.total_revenue_minor, d.currency)}</Td>
                <Td className="text-end">{money(d.total_direct_cost_minor, d.currency)}</Td>
                <Td className="text-end">{money(d.total_margin_minor, d.currency)}</Td>
              </tr>
            </tbody>
          </Table>
        )}
        <h3 className="px-5 pt-4 text-sm font-semibold">{text('By service type', 'حسب نوع الخدمة')}</h3>
        <Table>
          <thead>
            <tr>
              <Th>{text('Service type', 'نوع الخدمة')}</Th>
              <Th className="text-end">{text('Revenue', 'الإيراد')}</Th>
              <Th className="text-end">{text('Direct cost', 'التكلفة المباشرة')}</Th>
              <Th className="text-end">{text('Margin', 'الهامش')}</Th>
            </tr>
          </thead>
          <tbody>
            {d.by_service_type.map((row) => (
              <tr key={row.service_type ?? 'none'}>
                <Td>{row.service_type ?? text('Unallocated', 'غير موزع')}</Td>
                <Td className="text-end">{money(row.revenue_minor, d.currency)}</Td>
                <Td className="text-end">{money(row.direct_cost_minor, d.currency)}</Td>
                <Td className="text-end">{money(row.margin_minor, d.currency)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </>
    )
  }

  if (report === 'sales') {
    const d = data as Sales
    return (
      <>
        {header(text('Sales by branch and user', 'المبيعات حسب الفرع والمستخدم'), d)}
        {notReconciled(d.reconciliation)}
        <h3 className="px-5 pt-4 text-sm font-semibold">{text('By branch', 'حسب الفرع')}</h3>
        <Table>
          <thead>
            <tr>
              <Th>{text('Branch', 'الفرع')}</Th>
              <Th className="text-end">{text('Revenue', 'الإيراد')}</Th>
            </tr>
          </thead>
          <tbody>
            {d.by_branch.map((row) => (
              <tr key={row.branch.id}>
                <Td>{name(row.branch)}</Td>
                <Td className="text-end">{money(row.revenue_minor, d.currency)}</Td>
              </tr>
            ))}
            <tr className="font-semibold">
              <Td>{text('Total', 'الإجمالي')}</Td>
              <Td className="text-end">{money(d.total_revenue_minor, d.currency)}</Td>
            </tr>
          </tbody>
        </Table>
        <h3 className="px-5 pt-4 text-sm font-semibold">{text('By user (booking owner)', 'حسب المستخدم (مالك الحجز)')}</h3>
        <Table>
          <thead>
            <tr>
              <Th>{text('User', 'المستخدم')}</Th>
              <Th className="text-end">{text('Bookings', 'الحجوزات')}</Th>
              <Th className="text-end">{text('Revenue', 'الإيراد')}</Th>
            </tr>
          </thead>
          <tbody>
            {d.by_user.map((row) => (
              <tr key={row.user?.id ?? 'unlinked'}>
                <Td>{row.user?.name ?? text('Not linked to a booking', 'غير مرتبط بحجز')}</Td>
                <Td className="text-end tabular">{row.bookings_count}</Td>
                <Td className="text-end">{money(row.revenue_minor, d.currency)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </>
    )
  }

  if (report === 'commissions') {
    const d = data as Commissions
    return (
      <>
        {header(text('Commissions summary', 'ملخص العمولات'), d)}
        {d.reconciliation.reconciled ? null : (
          <InlineNotice tone="warning">{text('Commission expense does not match the ledger account for this period.', 'مصروف العمولات لا يطابق حساب الأستاذ لهذه الفترة.')}</InlineNotice>
        )}
        {d.rows.length === 0 ? (
          <EmptyState icon="chart" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>{text('Beneficiary', 'المستفيد')}</Th>
                <Th>{text('Type', 'النوع')}</Th>
                <Th className="text-end">{text('Pending', 'قيد الاعتماد')}</Th>
                <Th className="text-end">{text('Expense (ledger)', 'المصروف (الأستاذ)')}</Th>
                <Th className="text-end">{text('Paid', 'المدفوع')}</Th>
              </tr>
            </thead>
            <tbody>
              {d.rows.map((row) => (
                <tr key={`${row.beneficiary_type}-${row.beneficiary?.id ?? 'none'}`}>
                  <Td>
                    {can('commissions.view') ? (
                      <Link to="/commissions" className="text-brand-1-accent underline">
                        {row.beneficiary ? name(row.beneficiary) : '—'}
                      </Link>
                    ) : row.beneficiary ? (
                      name(row.beneficiary)
                    ) : (
                      '—'
                    )}
                  </Td>
                  <Td>{row.beneficiary_type}</Td>
                  <Td className="text-end">{money(row.pending_minor, d.currency)}</Td>
                  <Td className="text-end">{money(row.expense_minor, d.currency)}</Td>
                  <Td className="text-end">{money(row.paid_minor, d.currency)}</Td>
                </tr>
              ))}
              <tr className="font-semibold">
                <Td colSpan={2}>{text('Total', 'الإجمالي')}</Td>
                <Td className="text-end">{money(d.total_pending_minor, d.currency)}</Td>
                <Td className="text-end">{money(d.total_expense_minor, d.currency)}</Td>
                <Td className="text-end">{money(d.total_paid_minor, d.currency)}</Td>
              </tr>
            </tbody>
          </Table>
        )}
      </>
    )
  }

  const d = data as Tax
  return (
    <>
      {header(text('Tax summary', 'ملخص الضرائب'), d)}
      {d.enabled ? null : <InlineNotice tone="warning">{text('Tax is disabled in settings.', 'الضريبة معطلة في الإعدادات.')}</InlineNotice>}
      <p className="text-medium mb-3 text-text-subtle">
        {text('Tax rate', 'نسبة الضريبة')}: {d.rate}% · {d.inclusive ? text('prices include tax', 'الأسعار شاملة الضريبة') : text('prices exclude tax', 'الأسعار غير شاملة الضريبة')}
      </p>
      {d.rows.length === 0 ? (
        <EmptyState icon="chart" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>{text('Tax account', 'حساب الضريبة')}</Th>
              <Th>{text('Side', 'النوع')}</Th>
              <Th className="text-end">{text('Amount', 'المبلغ')}</Th>
            </tr>
          </thead>
          <tbody>
            {d.rows.map((row) => (
              <tr key={`${row.side}-${row.account.code}`}>
                <Td>{`${row.account.code} · ${localizedName(row.account, ar ? 'ar' : 'en')}`}</Td>
                <Td>{row.side === 'output' ? text('Output tax', 'ضريبة المخرجات') : text('Input tax', 'ضريبة المدخلات')}</Td>
                <Td className="text-end">{money(row.amount_minor, d.currency)}</Td>
              </tr>
            ))}
            <tr className="font-semibold">
              <Td>{text('Output tax', 'ضريبة المخرجات')}</Td>
              <Td />
              <Td className="text-end">{money(d.total_output_minor, d.currency)}</Td>
            </tr>
            <tr className="font-semibold">
              <Td>{text('Input tax', 'ضريبة المدخلات')}</Td>
              <Td />
              <Td className="text-end">{money(d.total_input_minor, d.currency)}</Td>
            </tr>
            <tr className="font-semibold">
              <Td>{text('Net payable', 'الصافي المستحق')}</Td>
              <Td />
              <Td className="text-end">{money(d.total_net_payable_minor, d.currency)}</Td>
            </tr>
          </tbody>
        </Table>
      )}
    </>
  )
}
