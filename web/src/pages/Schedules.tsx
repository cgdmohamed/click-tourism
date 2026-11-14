import { DateField } from '../components/DateField'
import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  Money,
  PageHeader,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'

type AccountRef = { code?: string; name_ar?: string; name_en?: string }

type ScheduleEvent = {
  id: string
  sequence: number
  kind: 'recognition' | 'reversal'
  status: 'pending' | 'posted' | 'failed' | 'cancelled'
  due_date: string
  amount_minor: number
  journal_entry_id: string | null
  reverses_event_id: string | null
  failure_reason: string | null
}

type Schedule = {
  id: string
  number: string
  kind: 'prepaid_expense' | 'deferred_revenue' | 'accrued_expense' | 'accrued_revenue'
  status: 'draft' | 'active' | 'completed' | 'cancelled'
  description: string
  reference: string | null
  currency: string
  total_minor: number
  periods: number
  starts_on: string
  ends_on: string
  reversal_policy: 'none' | 'next_period'
  source_account: AccountRef | null
  target_account: AccountRef | null
  recognized_minor: number
  reversed_minor: number
  remaining_minor: number
  outstanding_minor: number
  next_due_date: string | null
  approved_at: string | null
  cancel_reason: string | null
  adjustment_document_ids: string[]
  events: ScheduleEvent[]
}

type EventRow = {
  id: string
  schedule_id: string
  schedule_number: string
  description: string | null
  kind: 'recognition' | 'reversal'
  status: 'pending' | 'posted' | 'failed' | 'cancelled'
  due_date: string
  amount_minor: number
  currency: string | null
  failure_reason: string | null
  journal_entry_id: string | null
}

type Period = { id: string; name: string; starts_on: string; ends_on: string; status: 'open' | 'soft_closed' | 'closed' }
type Year = { id: string; name: string; periods: Period[] }

type ChecklistCheck = {
  key: string
  label_ar: string
  label_en: string
  passed: boolean
  blocking: boolean
  count: number
  message: string | null
  details: Record<string, unknown>
}

type Checklist = {
  period: { id: string; name: string; starts_on: string; ends_on: string; status: string }
  can_close: boolean
  as_of: string
  checks: ChecklistCheck[]
}

type Tab = 'schedules' | 'upcoming' | 'exceptions' | 'checklist'

const KINDS: Schedule['kind'][] = ['prepaid_expense', 'deferred_revenue', 'accrued_expense', 'accrued_revenue']

const emptyForm = {
  kind: 'prepaid_expense' as Schedule['kind'],
  description: '',
  reference: '',
  currency: 'EGP',
  total: '',
  periods: '1',
  starts_on: todayISO(),
  source_account_code: '',
  target_account_code: '',
}

const emptyCancel = { id: '', reason: '', write_off: false, write_off_memo: '' }

export default function Schedules() {
  const { locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()

  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [tab, setTab] = useState<Tab>('schedules')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [cancelForm, setCancelForm] = useState(emptyCancel)
  const [detailId, setDetailId] = useState<string | null>(null)
  const [periodId, setPeriodId] = useState('')

  const manage = can('schedules.manage')
  const approve = can('schedules.approve')

  const kindLabel = (kind: Schedule['kind']) =>
    ({
      prepaid_expense: L('مصروف مدفوع مقدماً', 'Prepaid expense'),
      deferred_revenue: L('إيراد مؤجل', 'Deferred revenue'),
      accrued_expense: L('مصروف مستحق', 'Accrued expense'),
      accrued_revenue: L('إيراد مستحق', 'Accrued revenue'),
    })[kind]

  const statusLabels: Record<string, { ar: string; en: string }> = {
    draft: { ar: 'مسودة', en: 'Draft' },
    active: { ar: 'نشط', en: 'Active' },
    completed: { ar: 'مكتمل', en: 'Completed' },
    cancelled: { ar: 'ملغي', en: 'Cancelled' },
    pending: { ar: 'معلق', en: 'Pending' },
    posted: { ar: 'مرحّل', en: 'Posted' },
    failed: { ar: 'فشل', en: 'Failed' },
    open: { ar: 'مفتوحة', en: 'Open' },
    soft_closed: { ar: 'مغلقة مؤقتاً', en: 'Soft closed' },
    closed: { ar: 'مغلقة', en: 'Closed' },
  }

  const statusLabel = (status: string) => {
    const label = statusLabels[status]
    return label ? L(label.ar, label.en) : status
  }

  const accountLabel = (account: AccountRef | null) =>
    account ? `${account.code ?? ''} ${locale === 'ar' ? account.name_ar ?? '' : account.name_en ?? ''}`.trim() : '—'

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['accounting-schedules'] })
    await queryClient.invalidateQueries({ queryKey: ['fiscal-periods', 'schedules'] })
    await queryClient.invalidateQueries({ queryKey: ['period-checklist'] })
  }

  const schedulesQuery = useQuery({
    queryKey: ['accounting-schedules', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Schedule[] }>('/accounting-schedules')
      return data.data
    },
  })

  const upcomingQuery = useQuery({
    queryKey: ['accounting-schedules', 'upcoming', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: EventRow[] }>('/accounting-schedules/upcoming', { params: { days: 45 } })
      return data.data
    },
  })

  const exceptionsQuery = useQuery({
    queryKey: ['accounting-schedules', 'exceptions', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: EventRow[] }>('/accounting-schedules/exceptions')
      return data.data
    },
  })

  const periodsQuery = useQuery({
    queryKey: ['fiscal-periods', 'schedules'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Year[] }>('/fiscal-periods')
      return data.data
    },
  })

  const periods = useMemo(
    () => (periodsQuery.data ?? []).flatMap((year) => year.periods).sort((a, b) => (a.starts_on < b.starts_on ? 1 : -1)),
    [periodsQuery.data],
  )

  const selectedPeriod = periodId || periods[0]?.id || ''

  const checklistQuery = useQuery({
    queryKey: ['period-checklist', selectedPeriod, activeBranch?.id],
    enabled: tab === 'checklist' && selectedPeriod !== '',
    queryFn: async () => {
      const { data } = await api.get<{ data: Checklist }>(`/fiscal-periods/${selectedPeriod}/checklist`)
      return data.data
    },
  })

  const createSchedule = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Schedule }>('/accounting-schedules', {
        kind: form.kind,
        description: form.description,
        reference: form.reference || undefined,
        currency: form.currency,
        total_minor: toMinor(form.total, form.currency),
        periods: Number(form.periods),
        starts_on: form.starts_on,
        source_account_code: form.source_account_code || undefined,
        target_account_code: form.target_account_code || undefined,
      })
      return data.data
    },
    onSuccess: async (schedule) => {
      setFormOpen(false)
      setForm(emptyForm)
      setError('')
      setNotice(
        L(
          `أُنشئ جدول ${schedule.number} كمسودة. راجعه ثم اعتمده لبدء الترحيل.`,
          `Schedule ${schedule.number} created as a draft. Review it, then activate it to start posting.`,
        ),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const activateSchedule = useMutation({
    mutationFn: async (schedule: Schedule) => {
      await api.post(`/accounting-schedules/${schedule.id}/activate`)
    },
    onSuccess: async () => {
      setError('')
      setNotice(L('تم اعتماد الجدول وترحيل الاستحقاقات المستحقة.', 'Schedule activated and due events posted.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const cancelSchedule = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{
        data: { adjustment_document: { number: string } | null }
      }>(`/accounting-schedules/${cancelForm.id}/cancel`, {
        reason: cancelForm.reason,
        write_off: cancelForm.write_off,
        write_off_memo: cancelForm.write_off_memo || undefined,
      })
      return data.data.adjustment_document
    },
    onSuccess: async (document) => {
      setCancelForm(emptyCancel)
      setDetailId(null)
      setError('')
      setNotice(
        document
          ? L(
              `أُلغي الجدول. أُنشئ سند تسوية مسودة ${document.number} لشطب الرصيد المتبقي.`,
              `Schedule cancelled. Draft adjustment voucher ${document.number} was created to write off the remaining balance.`,
            )
          : L('أُلغي الجدول مع الاحتفاظ بالقيود المرحّلة.', 'Schedule cancelled; posted history was preserved.'),
      )
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const retryEvent = useMutation({
    mutationFn: async (event: EventRow) => {
      await api.post(`/accounting-schedules/${event.schedule_id}/events/${event.id}/post`)
    },
    onSuccess: async () => {
      setError('')
      setNotice(L('تم ترحيل الحدث بنجاح.', 'Event posted successfully.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const detail = (schedulesQuery.data ?? []).find((schedule) => schedule.id === detailId) ?? null
  const isLoading = schedulesQuery.isLoading || upcomingQuery.isLoading || exceptionsQuery.isLoading

  const tabButton = (key: Tab, label: string) => (
    <button
      type="button"
      className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
        tab === key
          ? 'border-border-selected bg-surface-selected text-text-default'
          : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
      }`}
      onClick={() => setTab(key)}
    >
      {label}
    </button>
  )

  const eventRows = (rows: EventRow[]) =>
    rows.length === 0 ? (
      <EmptyState title={L('لا توجد بنود', 'Nothing here')} hint={L('ستظهر البنود المستحقة هنا.', 'Due items will appear here.')} />
    ) : (
      <Table>
        <thead>
          <tr>
            <Th>{L('الجدول', 'Schedule')}</Th>
            <Th>{L('البيان', 'Description')}</Th>
            <Th>{L('النوع', 'Kind')}</Th>
            <Th>{L('تاريخ الاستحقاق', 'Due date')}</Th>
            <Th>{L('المبلغ', 'Amount')}</Th>
            <Th>{L('الحالة', 'Status')}</Th>
            <Th>{L('ملاحظة', 'Note')}</Th>
            {tab === 'exceptions' && manage ? <Th /> : null}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              <Td>
                <span className="tabular">{row.schedule_number}</span>
              </Td>
              <Td>{row.description ?? '—'}</Td>
              <Td>{row.kind === 'reversal' ? L('عكس', 'Reversal') : L('اعتراف', 'Recognition')}</Td>
              <Td>{formatDate(row.due_date, locale)}</Td>
              <Td>
                <Money amountMinor={row.amount_minor} currency={row.currency ?? 'EGP'} />
              </Td>
              <Td>
                <Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge>
              </Td>
              <Td className="text-small text-text-subtle">{row.failure_reason ?? '—'}</Td>
              {tab === 'exceptions' && manage ? (
                <Td>
                  <Button variant="secondary" onClick={() => retryEvent.mutate(row)} disabled={retryEvent.isPending}>
                    {L('إعادة المحاولة', 'Retry')}
                  </Button>
                </Td>
              ) : null}
            </tr>
          ))}
        </tbody>
      </Table>
    )

  return (
    <div>
      <PageHeader
        title={L('الاستحقاقات والمقدمات', 'Accruals & prepayments')}
        subtitle={L(
          'جداول الاعتراف بالمصروفات المدفوعة مقدماً والإيرادات المؤجلة والمستحقات، مع قائمة استثناءات وقائمة إغلاق الفترة.',
          'Recognition schedules for prepayments, deferred revenue and accruals, with an exception queue and a period-end checklist.',
        )}
        icon="calendarClock"
        actions={
          manage ? (
            <Button
              icon="plus"
              onClick={() => {
                setForm({ ...emptyForm, starts_on: todayISO() })
                setError('')
                setFormOpen(true)
              }}
            >
              {L('جدول جديد', 'New schedule')}
            </Button>
          ) : null
        }
      />

      {error ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}
      {notice ? (
        <p className="mb-4 rounded-12 border border-border-subtle bg-surface-subtle px-3 py-2 text-medium text-text-default">{notice}</p>
      ) : null}

      <div className="mb-4 flex flex-wrap gap-2">
        {tabButton('schedules', L('الجداول', 'Schedules'))}
        {tabButton('upcoming', L('القادم', 'Upcoming'))}
        {tabButton('exceptions', L('الاستثناءات', 'Exceptions'))}
        {tabButton('checklist', L('قائمة الإغلاق', 'Period checklist'))}
      </div>

      {isLoading ? <Loading /> : null}

      {tab === 'schedules' ? (
        schedulesQuery.isError ? (
          <ErrorState message={errorMessage(schedulesQuery.error)} onRetry={() => void schedulesQuery.refetch()} />
        ) : (
          <Card>
            <CardHeader
              title={L('جداول الاستحقاق والمقدمات', 'Accrual & prepayment schedules')}
              subtitle={L('المسودات قابلة للتعديل، وبعد الاعتماد تصبح القيود المرحّلة غير قابلة للتغيير.', 'Drafts are editable; posted events are immutable after activation.')}
            />
            {(schedulesQuery.data ?? []).length === 0 ? (
              <EmptyState
                title={L('لا توجد جداول بعد', 'No schedules yet')}
                hint={L('أنشئ جدولاً لمصروف مدفوع مقدماً أو إيراد مؤجل أو مستحق.', 'Create a prepaid, deferred revenue or accrual schedule.')}
              />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{L('الرقم', 'Number')}</Th>
                    <Th>{L('النوع', 'Kind')}</Th>
                    <Th>{L('البيان', 'Description')}</Th>
                    <Th>{L('الإجمالي', 'Total')}</Th>
                    <Th>{L('المعترف به', 'Recognized')}</Th>
                    <Th>{L('المتبقي', 'Remaining')}</Th>
                    <Th>{L('الحالة', 'Status')}</Th>
                    <Th>{L('الاستحقاق القادم', 'Next due')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {(schedulesQuery.data ?? []).map((schedule) => (
                    <tr key={schedule.id}>
                      <Td>
                        <span className="tabular">{schedule.number}</span>
                      </Td>
                      <Td>{kindLabel(schedule.kind)}</Td>
                      <Td>
                        <div>{schedule.description}</div>
                        <div className="text-small text-text-subtle">
                          {accountLabel(schedule.source_account)} → {accountLabel(schedule.target_account)}
                        </div>
                        {schedule.cancel_reason ? <div className="text-small text-danger-text">{schedule.cancel_reason}</div> : null}
                      </Td>
                      <Td>
                        <Money amountMinor={schedule.total_minor} currency={schedule.currency} />
                      </Td>
                      <Td>
                        <Money amountMinor={schedule.recognized_minor} currency={schedule.currency} />
                      </Td>
                      <Td>
                        <Money amountMinor={schedule.remaining_minor} currency={schedule.currency} />
                      </Td>
                      <Td>
                        <Badge tone={statusTone(schedule.status)}>{statusLabel(schedule.status)}</Badge>
                      </Td>
                      <Td>{formatDate(schedule.next_due_date, locale)}</Td>
                      <Td>
                        <div className="flex flex-wrap justify-end gap-2">
                          <Button variant="ghost" onClick={() => setDetailId(schedule.id)}>
                            {L('عرض', 'View')}
                          </Button>
                          {schedule.status === 'draft' && approve ? (
                            <Button variant="secondary" onClick={() => activateSchedule.mutate(schedule)} disabled={activateSchedule.isPending}>
                              {L('اعتماد', 'Activate')}
                            </Button>
                          ) : null}
                          {(schedule.status === 'draft' || schedule.status === 'active') && approve ? (
                            <Button
                              variant="danger"
                              onClick={() => {
                                setError('')
                                setCancelForm({ ...emptyCancel, id: schedule.id })
                              }}
                            >
                              {L('إلغاء', 'Cancel')}
                            </Button>
                          ) : null}
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        )
      ) : null}

      {tab === 'upcoming' ? (
        <Card>
          <CardHeader
            title={L('الاستحقاقات القادمة (45 يوماً)', 'Upcoming events (45 days)')}
            subtitle={L('تُرحّل تلقائياً يومياً، ويمكن إعادة المحاولة يدوياً عند إعادة فتح الفترة.', 'Posted daily by the scheduler; failed events can be retried manually after a period is reopened.')}
          />
          {upcomingQuery.isError ? (
            <ErrorState message={errorMessage(upcomingQuery.error)} onRetry={() => void upcomingQuery.refetch()} />
          ) : (
            eventRows(upcomingQuery.data ?? [])
          )}
        </Card>
      ) : null}

      {tab === 'exceptions' ? (
        <Card>
          <CardHeader
            title={L('قائمة الاستثناءات', 'Exception queue')}
            subtitle={L('أحداث تعذّر ترحيلها، غالباً لأن الفترة مغلقة. أعد فتح الفترة ثم أعد المحاولة.', 'Events the scheduler could not post, usually because the period was closed. Reopen the period, then retry.')}
          />
          {exceptionsQuery.isError ? (
            <ErrorState message={errorMessage(exceptionsQuery.error)} onRetry={() => void exceptionsQuery.refetch()} />
          ) : (
            eventRows(exceptionsQuery.data ?? [])
          )}
        </Card>
      ) : null}

      {tab === 'checklist' ? (
        <Card>
          <CardHeader
            title={L('قائمة إغلاق الفترة', 'Period-end checklist')}
            subtitle={L('فحوصات استشارية قبل إغلاق الفترة: مستندات غير مرحّلة، مطابقات مفتوحة، جداول مسودة، أرصدة غير طبيعية وتوازن ميزان المراجعة.', 'Advisory checks before closing: unposted documents, open reconciliations, draft schedules, abnormal balances and trial-balance tie-out.')}
            actions={
              <Select value={selectedPeriod} onChange={(event) => setPeriodId(event.target.value)} className="w-48">
                {periods.map((period) => (
                  <option key={period.id} value={period.id}>
                    {period.name}
                  </option>
                ))}
              </Select>
            }
          />
          {periods.length === 0 ? (
            <EmptyState title={L('لا توجد فترات مالية', 'No fiscal periods')} hint={L('أنشئ فترة مالية أولاً.', 'Create a fiscal period first.')} />
          ) : checklistQuery.isLoading ? (
            <Loading />
          ) : checklistQuery.isError ? (
            <ErrorState message={errorMessage(checklistQuery.error)} onRetry={() => void checklistQuery.refetch()} />
          ) : checklistQuery.data ? (
            <div className="px-5 py-4">
              <div className="mb-4 flex flex-wrap items-center gap-3">
                <Badge tone={checklistQuery.data.can_close ? 'positive' : 'warning'}>
                  {checklistQuery.data.can_close ? L('جاهزة للإغلاق', 'Ready to close') : L('تحتاج مراجعة', 'Needs review')}
                </Badge>
                <span className="text-small text-text-subtle">
                  {formatDate(checklistQuery.data.period.starts_on, locale)} → {formatDate(checklistQuery.data.period.ends_on, locale)} · {statusLabel(checklistQuery.data.period.status)}
                </span>
              </div>
              <ul className="space-y-2">
                {checklistQuery.data.checks.map((check) => (
                  <li key={check.key} className="flex flex-wrap items-start justify-between gap-3 rounded-12 border border-border-subtle px-4 py-3">
                    <div>
                      <p className="text-medium font-medium text-text-default">{locale === 'ar' ? check.label_ar : check.label_en}</p>
                      {check.message ? <p className="text-small mt-1 text-text-subtle">{check.message}</p> : null}
                    </div>
                    <Badge tone={check.passed ? 'positive' : 'negative'}>{check.passed ? L('سليم', 'Passed') : L('ملاحظة', 'Issue')}</Badge>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </Card>
      ) : null}

      <Modal
        open={formOpen}
        title={L('جدول استحقاق جديد', 'New accrual schedule')}
        onClose={() => setFormOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setFormOpen(false)}>
              {L('إلغاء', 'Cancel')}
            </Button>
            <Button onClick={() => createSchedule.mutate()} disabled={createSchedule.isPending || !form.description || !form.total}>
              {L('حفظ كمسودة', 'Save as draft')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('النوع', 'Kind')} required>
            <Select value={form.kind} onChange={(event) => setForm({ ...form, kind: event.target.value as Schedule['kind'] })}>
              {KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {kindLabel(kind)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={L('البيان', 'Description')} required>
            <Input value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} maxLength={255} />
          </Field>
          <Field label={L('المرجع', 'Reference')}>
            <Input value={form.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} maxLength={100} />
          </Field>
          <Field label={L('العملة', 'Currency')} hint={L('تُحسب المبالغ بأصغر وحدة في هذه العملة.', 'Amounts are converted using this currency.')}>
            <Input value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase().slice(0, 3) })} maxLength={3} />
          </Field>
          <Field label={L('الإجمالي', 'Total')} required>
            <Input inputMode="decimal" value={form.total} onChange={(event) => setForm({ ...form, total: event.target.value })} placeholder="1200.00" />
          </Field>
          <Field label={L('عدد الفترات', 'Periods')} required hint={L('شهري: يوزّع المبلغ على عدد الفترات، والباقي في الأخيرة.', 'Monthly: the total is split across periods; the residual goes to the last one.')}>
            <Input type="number" min={1} max={120} value={form.periods} onChange={(event) => setForm({ ...form, periods: event.target.value })} />
          </Field>
          <Field label={L('تاريخ البداية', 'Starts on')} required>
            <DateField value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} />
          </Field>
          <Field label={L('حساب المصدر', 'Source account code')} hint={L('اتركه فارغاً لاستخدام حساب الإعداد الافتراضي.', 'Leave empty to use the configured default account.')}>
            <Input value={form.source_account_code} onChange={(event) => setForm({ ...form, source_account_code: event.target.value })} maxLength={20} />
          </Field>
          <Field label={L('حساب الاعتراف', 'Recognition account code')} hint={L('مصروف أو إيراد يُعترف به على مدى الفترات.', 'Expense or revenue account recognized over the periods.')}>
            <Input value={form.target_account_code} onChange={(event) => setForm({ ...form, target_account_code: event.target.value })} maxLength={20} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={cancelForm.id !== ''}
        title={L('إلغاء الجدول', 'Cancel schedule')}
        onClose={() => setCancelForm(emptyCancel)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCancelForm(emptyCancel)}>
              {L('رجوع', 'Back')}
            </Button>
            <Button variant="danger" onClick={() => cancelSchedule.mutate()} disabled={cancelSchedule.isPending || !cancelForm.reason}>
              {L('تأكيد الإلغاء', 'Confirm cancellation')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-medium text-text-subtle">
            {L(
              'تُلغى الاستحقاقات المعلقة فقط، وتبقى القيود المرحّلة سليمة. يمكن شطب الرصيد المتبقي عبر سند تسوية مسودة يحتاج اعتماداً منفصلاً.',
              'Only pending events are cancelled; posted journals stay intact. The remaining balance can be written off through a draft adjustment voucher that needs separate approval.',
            )}
          </p>
          <Field label={L('سبب الإلغاء', 'Cancellation reason')} required>
            <Input value={cancelForm.reason} onChange={(event) => setCancelForm({ ...cancelForm, reason: event.target.value })} maxLength={255} />
          </Field>
          <label className="flex items-center gap-2 text-medium text-text-default">
            <input
              type="checkbox"
              checked={cancelForm.write_off}
              onChange={(event) => setCancelForm({ ...cancelForm, write_off: event.target.checked })}
            />
            {L('إنشاء سند تسوية مسودة لشطب الرصيد المتبقي', 'Create a draft adjustment voucher for the remaining balance')}
          </label>
          {cancelForm.write_off ? (
            <Field label={L('ملاحظة السند', 'Voucher memo')}>
              <Input value={cancelForm.write_off_memo} onChange={(event) => setCancelForm({ ...cancelForm, write_off_memo: event.target.value })} maxLength={255} />
            </Field>
          ) : null}
        </div>
      </Modal>

      <Modal open={detail !== null} title={detail?.number ?? ''} onClose={() => setDetailId(null)}>
        {detail ? (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={statusTone(detail.status)}>{statusLabel(detail.status)}</Badge>
              <Badge tone="brand">{kindLabel(detail.kind)}</Badge>
              <span className="text-small text-text-subtle">
                {formatDate(detail.starts_on, locale)} → {formatDate(detail.ends_on, locale)} · {detail.periods} {L('فترة', 'periods')}
              </span>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <p className="text-small text-text-subtle">{L('الإجمالي', 'Total')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.total_minor} currency={detail.currency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('المعترف به', 'Recognized')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.recognized_minor} currency={detail.currency} />
                </p>
              </div>
              <div>
                <p className="text-small text-text-subtle">{L('المتبقي', 'Remaining')}</p>
                <p className="tabular text-medium">
                  <Money amountMinor={detail.remaining_minor} currency={detail.currency} />
                </p>
              </div>
            </div>
            {detail.adjustment_document_ids.length > 0 ? (
              <p className="text-small text-text-subtle">
                {L('سندات التسوية المرتبطة', 'Linked adjustment vouchers')}: {detail.adjustment_document_ids.join(', ')}
              </p>
            ) : null}
            <Table>
              <thead>
                <tr>
                  <Th>#</Th>
                  <Th>{L('النوع', 'Kind')}</Th>
                  <Th>{L('تاريخ الاستحقاق', 'Due date')}</Th>
                  <Th>{L('المبلغ', 'Amount')}</Th>
                  <Th>{L('الحالة', 'Status')}</Th>
                  <Th>{L('القيد', 'Journal')}</Th>
                </tr>
              </thead>
              <tbody>
                {detail.events.map((event) => (
                  <tr key={event.id}>
                    <Td>{event.sequence}</Td>
                    <Td>{event.kind === 'reversal' ? L('عكس', 'Reversal') : L('اعتراف', 'Recognition')}</Td>
                    <Td>{formatDate(event.due_date, locale)}</Td>
                    <Td>
                      <Money amountMinor={event.amount_minor} currency={detail.currency} />
                    </Td>
                    <Td>
                      <Badge tone={statusTone(event.status)}>{statusLabel(event.status)}</Badge>
                      {event.failure_reason ? <p className="text-small text-danger-text">{event.failure_reason}</p> : null}
                    </Td>
                    <Td className="text-small text-text-subtle">{event.journal_entry_id ?? '—'}</Td>
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
