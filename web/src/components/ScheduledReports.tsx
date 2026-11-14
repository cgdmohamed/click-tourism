import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { PERIODS, PERIOD_LABEL, SIMPLE_REPORTS, dayOptions, invalidRecipients, parseRecipients } from '../lib/reportSchedules'
import type { Period } from '../lib/reportSchedules'
import { useFeatureFlags } from '../lib/useSetting'
import { Badge, Button, Field, InlineNotice, Input, Modal, Select } from './ui'

type Schedule = {
  id: string
  name: string | null
  report: string
  format: 'csv' | 'xlsx' | 'pdf'
  period: Period | null
  frequency: 'daily' | 'weekly' | 'monthly'
  day: number | null
  recipients: string[]
  is_active: boolean
  next_run_at: string | null
  last_run_at: string | null
  last_status: 'sent' | 'failed' | 'mail_not_configured' | null
  last_error: string | null
}

const REPORT_LABEL: Record<string, { ar: string; en: string }> = {
  'trial-balance': { ar: 'ميزان المراجعة', en: 'Trial balance' },
  'income-statement': { ar: 'قائمة الدخل', en: 'Income statement' },
  'balance-sheet': { ar: 'المركز المالي', en: 'Balance sheet' },
  profitability: { ar: 'الربحية', en: 'Profitability' },
  sales: { ar: 'المبيعات', en: 'Sales' },
  commissions: { ar: 'العمولات', en: 'Commissions' },
  tax: { ar: 'الضرائب', en: 'Tax' },
}
const WEEKDAYS = { ar: ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'], en: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] }

/**
 * Reports e-mailed on a schedule (ADR-0026). Needs `reports.schedule`; sending uses the SMTP settings of the
 * server environment, so the card says so when the server has no real mailer.
 */
export function ScheduledReports() {
  const { locale } = useI18n()
  const { can, activeBranch } = useAuth()
  const { flags } = useFeatureFlags()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [message, setMessage] = useState('')
  const [form, setForm] = useState({ name: '', report: 'trial-balance', format: 'xlsx', frequency: 'monthly', day: 1, period: 'previous_month', recipients: '' })

  const list = useQuery({
    queryKey: ['report-schedules', activeBranch?.id],
    enabled: can('reports.schedule'),
    queryFn: async () => (await api.get<{ data: Schedule[] }>('/report-schedules')).data.data,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['report-schedules'] })

  const recipients = parseRecipients(form.recipients)
  const bad = invalidRecipients(recipients)
  const valid = recipients.length >= 1 && recipients.length <= 10 && bad.length === 0

  const create = useMutation({
    mutationFn: async () =>
      api.post('/report-schedules', {
        name: form.name || undefined,
        report: form.report,
        format: form.format,
        frequency: form.frequency,
        day: form.frequency === 'daily' ? undefined : form.day,
        period: form.period,
        recipients,
      }),
    onSuccess: async () => {
      setOpen(false)
      setMessage('')
      setForm({ ...form, name: '', recipients: '' })
      await refresh()
    },
    onError: (cause) => setMessage(errorMessage(cause)),
  })
  const toggle = useMutation({ mutationFn: async (row: Schedule) => api.patch(`/report-schedules/${row.id}`, { is_active: !row.is_active }), onSuccess: refresh })
  const remove = useMutation({ mutationFn: async (id: string) => api.delete(`/report-schedules/${id}`), onSuccess: refresh })
  const runNow = useMutation({ mutationFn: async (id: string) => api.post(`/report-schedules/${id}/run`), onSuccess: refresh, onError: (cause) => setMessage(errorMessage(cause)) })

  if (!can('reports.schedule')) return null
  const rows = list.data ?? []
  const statusLabel = (row: Schedule) => {
    if (row.last_status === 'sent') return <Badge tone="positive">{L('أُرسل', 'Sent')}</Badge>
    if (row.last_status === 'failed') return <Badge tone="negative">{L('فشل', 'Failed')}</Badge>
    if (row.last_status === 'mail_not_configured') return <Badge tone="warning">{L('البريد غير مُعدّ', 'Mail not configured')}</Badge>
    return <span className="text-text-subtle">—</span>
  }
  const when = (row: Schedule) =>
    row.frequency === 'daily'
      ? L('يومياً 06:00', 'Daily 06:00')
      : row.frequency === 'weekly'
        ? `${L('أسبوعياً', 'Weekly')} ${WEEKDAYS[locale][(row.day ?? 1) - 1]} 06:00`
        : `${L('شهرياً يوم', 'Monthly on day')} ${row.day ?? 1} 06:00`

  return (
    <section className="flex flex-col gap-3 border-t border-border-subtle px-5 py-4" aria-label={L('إرسال التقارير بالبريد', 'Scheduled reports')}>
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="label">{L('إرسال التقارير بالبريد', 'E-mail reports on a schedule')}</h3>
        <Button variant="secondary" icon="plus" onClick={() => setOpen(true)}>
          {L('جدولة جديدة', 'New schedule')}
        </Button>
      </div>
      {flags?.mail_configured === false ? (
        <InlineNotice tone="warning">
          {L(
            'خادم البريد (SMTP) غير مُعدّ في بيئة الخادم، فلن يُرسل شيء. اضبط متغيرات MAIL_* في بيئة الخادم ثم أعد التشغيل.',
            'The server has no SMTP mailer configured, so nothing will be sent. Set the MAIL_* variables in the server environment and restart.',
          )}
        </InlineNotice>
      ) : null}
      {message ? <InlineNotice tone="negative">{message}</InlineNotice> : null}

      {rows.length > 0 ? (
        <ul className="flex flex-col gap-2" aria-label={L('الجدولات', 'Schedules')}>
          {rows.map((row) => (
            <li key={row.id} className="flex flex-col gap-1 rounded-12 border border-border-subtle px-3 py-2">
              <div className="flex flex-wrap items-center gap-3">
                <span className="text-medium font-medium">{row.name || (REPORT_LABEL[row.report] ? REPORT_LABEL[row.report][locale] : row.report)}</span>
                <span className="text-small uppercase text-text-subtle">{row.format}</span>
                <span className="text-small text-text-subtle">{when(row)}</span>
                {row.period ? <span className="text-small text-text-subtle">{PERIOD_LABEL[row.period][locale]}</span> : null}
                {row.is_active ? null : <Badge tone="neutral">{L('متوقفة', 'Paused')}</Badge>}
                <span role="status">{statusLabel(row)}</span>
                <span className="ms-auto flex gap-2">
                  <Button variant="secondary" disabled={runNow.isPending} onClick={() => runNow.mutate(row.id)}>
                    {L('أرسل الآن', 'Send now')}
                  </Button>
                  <Button variant="secondary" disabled={toggle.isPending} onClick={() => toggle.mutate(row)}>
                    {row.is_active ? L('إيقاف', 'Pause') : L('تشغيل', 'Resume')}
                  </Button>
                  <Button variant="ghost" icon="trash" aria-label={L('حذف', 'Delete')} onClick={() => remove.mutate(row.id)} />
                </span>
              </div>
              <p className="text-small text-text-subtle" dir="ltr">{row.recipients.join(', ')}</p>
              {row.next_run_at ? <p className="text-small text-text-subtle">{L('التالي', 'Next')}: {formatDateTime(row.next_run_at, locale)}</p> : null}
              {row.last_status === 'failed' || row.last_status === 'mail_not_configured' ? <p className="text-small text-danger-text">{row.last_error}</p> : null}
            </li>
          ))}
        </ul>
      ) : null}

      <Modal
        open={open}
        title={L('جدولة تقرير بالبريد', 'Schedule a report by e-mail')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {L('إلغاء', 'Cancel')}
            </Button>
            <Button disabled={!valid || create.isPending} onClick={() => create.mutate()}>
              {L('حفظ الجدولة', 'Save schedule')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <Field label={L('الاسم (اختياري)', 'Name (optional)')}>
            <Input value={form.name} maxLength={100} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={L('التقرير', 'Report')}>
              <Select value={form.report} onChange={(event) => setForm({ ...form, report: event.target.value })}>
                {SIMPLE_REPORTS.map((key) => (
                  <option key={key} value={key}>
                    {REPORT_LABEL[key][locale]}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={L('الصيغة', 'Format')}>
              <Select value={form.format} onChange={(event) => setForm({ ...form, format: event.target.value })}>
                <option value="xlsx">XLSX</option>
                <option value="pdf">PDF</option>
                <option value="csv">CSV</option>
              </Select>
            </Field>
            <Field label={L('الفترة التي يغطيها', 'Period covered')}>
              <Select value={form.period} onChange={(event) => setForm({ ...form, period: event.target.value })}>
                {PERIODS.map((key) => (
                  <option key={key} value={key}>
                    {PERIOD_LABEL[key][locale]}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={L('التكرار', 'Frequency')}>
              <Select
                value={form.frequency}
                onChange={(event) => setForm({ ...form, frequency: event.target.value, day: 1 })}
              >
                <option value="daily">{L('يومي', 'Daily')}</option>
                <option value="weekly">{L('أسبوعي', 'Weekly')}</option>
                <option value="monthly">{L('شهري', 'Monthly')}</option>
              </Select>
            </Field>
            {form.frequency !== 'daily' ? (
              <Field label={form.frequency === 'weekly' ? L('اليوم', 'Weekday') : L('يوم الشهر (1-28)', 'Day of month (1-28)')}>
                <Select value={String(form.day)} onChange={(event) => setForm({ ...form, day: Number(event.target.value) })}>
                  {dayOptions(form.frequency as 'weekly' | 'monthly').map((day) => (
                    <option key={day} value={day}>
                      {form.frequency === 'weekly' ? WEEKDAYS[locale][day - 1] : day}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : null}
          </div>
          <Field
            label={L('المستلمون (بريد، حتى 10)', 'Recipients (e-mail, up to 10)')}
            hint={L('افصل بفاصلة أو سطر جديد. يصلهم الملف كمرفق، فاختر عناوين موثوقة.', 'Separate with a comma or a new line. They receive the file as an attachment: choose trusted addresses.')}
            error={bad.length > 0 ? `${L('غير صالح', 'Not valid')}: ${bad.join(', ')}` : recipients.length > 10 ? L('الحد الأقصى 10 عناوين', 'At most 10 addresses') : undefined}
          >
            <Input dir="ltr" value={form.recipients} placeholder="boss@example.com, cfo@example.com" onChange={(event) => setForm({ ...form, recipients: event.target.value })} />
          </Field>
          <p className="text-small text-text-subtle">
            {L('يُنشأ التقرير بصلاحياتك أنت وفرعك الحالي في الساعة 06:00، ويُرسل بإعدادات SMTP الموجودة في بيئة الخادم.', 'The report is produced with your own permissions and current branch at 06:00 and sent with the SMTP settings of the server environment.')}
          </p>
        </div>
      </Modal>
    </section>
  )
}
