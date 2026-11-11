import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate } from '../lib/format'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th, statusTone } from '../components/ui'
import { Icon } from '../components/icons'
import { statusLabel } from '../lib/labels'

type Period = { id: string; name: string; starts_on: string; ends_on: string; status: 'open' | 'soft_closed' | 'closed' }
type Year = { id: string; name: string; starts_on: string; ends_on: string; status: 'open' | 'closed'; closed_at: string | null; periods: Period[] }
type Suggestion = { name: string; starts_on: string; ends_on: string; fiscal_year_start: string }
type Check = { code: string; passed: boolean; message: string | null; count: number | null }
type Checks = { can_close: boolean; checks: Check[] }

const checkLabels: Record<string, { ar: string; en: string }> = {
  year_open: { ar: 'السنة المالية مفتوحة', en: 'Fiscal year is open' },
  draft_entries: { ar: 'لا توجد قيود مسودة', en: 'No draft journal entries' },
  submitted_expenses: { ar: 'لا توجد مصروفات بانتظار الاعتماد', en: 'No expenses awaiting approval' },
  trial_balance: { ar: 'ميزان المراجعة متوازن', en: 'Trial balance is balanced' },
  retained_earnings_account: { ar: 'حساب الأرباح المحتجزة صالح', en: 'Retained earnings account is valid' },
  last_period_postable: { ar: 'آخر فترة تقبل الترحيل', en: 'Final period accepts postings' },
}

export default function Periods() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [error, setError] = useState('')
  const [checkYear, setCheckYear] = useState<Year | null>(null)
  const [reopenYear, setReopenYear] = useState<Year | null>(null)
  const [reason, setReason] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [form, setForm] = useState({ name: '', starts_on: '', ends_on: '' })
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null)
  const [confirmCustom, setConfirmCustom] = useState(false)

  const yearsQuery = useQuery({
    queryKey: ['fiscal-years'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Year[] }>('/fiscal-periods')
      return data.data
    },
  })

  const checksQuery = useQuery({
    queryKey: ['close-checks', checkYear?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Checks }>(`/fiscal-years/${checkYear?.id}/close-checks`)
      return data.data
    },
    enabled: checkYear !== null,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['fiscal-years'] })
    await queryClient.invalidateQueries({ queryKey: ['close-checks'] })
  }

  const closeYear = useMutation({
    mutationFn: async () => {
      await api.post(`/fiscal-years/${checkYear?.id}/close`)
    },
    onSuccess: async () => {
      setCheckYear(null)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reopen = useMutation({
    mutationFn: async () => {
      await api.post(`/fiscal-years/${reopenYear?.id}/reopen`, { reason })
    },
    onSuccess: async () => {
      setReopenYear(null)
      setReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const setStatus = useMutation({
    mutationFn: async ({ period, status }: { period: Period; status: Period['status'] }) => {
      await api.patch(`/fiscal-periods/${period.id}/status`, { status })
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createYear = useMutation({
    mutationFn: async () => {
      await api.post('/fiscal-periods', { ...form, confirm_custom_start: confirmCustom })
    },
    onSuccess: async () => {
      setCreateOpen(false)
      setForm({ name: '', starts_on: '', ends_on: '' })
      setConfirmCustom(false)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const openCreate = async () => {
    setError('')
    setConfirmCustom(false)
    setCreateOpen(true)
    try {
      const { data } = await api.get<{ data: Suggestion }>('/fiscal-years/suggested')
      setSuggestion(data.data)
      setForm({ name: data.data.name, starts_on: data.data.starts_on, ends_on: data.data.ends_on })
    } catch (cause) {
      setError(errorMessage(cause))
    }
  }

  const customStart = suggestion !== null && form.starts_on !== '' && form.starts_on.slice(5) !== suggestion.fiscal_year_start

  const years = yearsQuery.data ?? []

  return (
    <div>
      <PageHeader
        title={L('السنوات والفترات المالية', 'Fiscal years & periods')}
        subtitle={L('إقفال الفترات والسنوات المالية وإعادة فتحها', 'Close and reopen periods and fiscal years')}
        icon="calendar"
        actions={
          can('periods.manage') ? (
            <Button icon="plus" onClick={() => void openCreate()}>{L('سنة مالية جديدة', 'New fiscal year')}</Button>
          ) : null
        }
      />

      {error && !checkYear && !reopenYear && !createOpen ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      {yearsQuery.isLoading ? <Loading /> : null}
      {yearsQuery.isError ? <ErrorState message={errorMessage(yearsQuery.error)} onRetry={() => void yearsQuery.refetch()} /> : null}
      {!yearsQuery.isLoading && years.length === 0 ? <EmptyState /> : null}

      <div className="flex flex-col gap-6">
        {years.map((year) => (
          <Card key={year.id}>
            <CardHeader
              title={`${year.name} · ${formatDate(year.starts_on, locale)} → ${formatDate(year.ends_on, locale)}`}
              subtitle={year.closed_at ? `${L('أُقفلت في', 'Closed on')} ${formatDate(year.closed_at, locale)}` : undefined}
              actions={
                <div className="flex items-center gap-2">
                  <Badge tone={statusTone(year.status)}>{statusLabel(year.status, locale)}</Badge>
                  {year.status === 'open' && can('periods.close') ? (
                    <Button variant="secondary" onClick={() => { setError(''); setCheckYear(year) }}>{L('إقفال السنة', 'Close year')}</Button>
                  ) : null}
                  {year.status === 'closed' && can('periods.reopen') ? (
                    <Button variant="danger" onClick={() => { setError(''); setReopenYear(year) }}>{L('إعادة فتح', 'Reopen')}</Button>
                  ) : null}
                </div>
              }
            />
            <Table>
              <thead>
                <tr>
                  <Th>{t('name')}</Th>
                  <Th>{t('from')}</Th>
                  <Th>{t('to')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {year.periods.map((period) => (
                  <tr key={period.id}>
                    <Td className="tabular">{period.name}</Td>
                    <Td className="tabular">{formatDate(period.starts_on, locale)}</Td>
                    <Td className="tabular">{formatDate(period.ends_on, locale)}</Td>
                    <Td>
                      <Badge tone={period.status === 'open' ? 'positive' : period.status === 'soft_closed' ? 'warning' : 'neutral'}>{statusLabel(period.status, locale)}</Badge>
                    </Td>
                    <Td className="text-end">
                      {year.status === 'open' && can('periods.manage') ? (
                        <Select
                          value={period.status}
                          onChange={(event) => setStatus.mutate({ period, status: event.target.value as Period['status'] })}
                          className="max-w-40"
                        >
                          <option value="open">open</option>
                          <option value="soft_closed">soft_closed</option>
                          <option value="closed">closed</option>
                        </Select>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
        ))}
      </div>

      <Modal
        open={checkYear !== null}
        title={`${L('إقفال السنة', 'Close fiscal year')} ${checkYear?.name ?? ''}`}
        onClose={() => setCheckYear(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCheckYear(null)}>{t('cancel')}</Button>
            <Button onClick={() => closeYear.mutate()} disabled={closeYear.isPending || !checksQuery.data?.can_close}>{L('إقفال السنة', 'Close year')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-4 text-medium text-text-subtle">
          {L(
            'سيُنشأ قيد إقفال لكل فرع لنقل صافي الربح أو الخسارة إلى الأرباح المحتجزة، ثم تُقفل كل الفترات.',
            'A closing entry is posted per branch to move net profit or loss into retained earnings, then every period is locked.',
          )}
        </p>
        {checksQuery.isLoading ? <Loading /> : null}
        {checksQuery.isError ? <ErrorState message={errorMessage(checksQuery.error)} /> : null}
        <ul className="flex flex-col gap-2">
          {(checksQuery.data?.checks ?? []).map((check) => (
            <li key={check.code} className="flex items-start gap-2 text-medium">
              <span className={check.passed ? 'mt-0.5 text-success-text' : 'mt-0.5 text-danger-text'}>
                <Icon name={check.passed ? 'checkCircle' : 'xCircle'} className="h-4 w-4" />
              </span>
              <span>
                {locale === 'ar' ? checkLabels[check.code]?.ar : checkLabels[check.code]?.en}
                {!check.passed && check.message ? <span className="block text-small text-danger-text">{check.message}</span> : null}
              </span>
            </li>
          ))}
        </ul>
      </Modal>

      <Modal
        open={reopenYear !== null}
        title={`${L('إعادة فتح السنة', 'Reopen fiscal year')} ${reopenYear?.name ?? ''}`}
        onClose={() => setReopenYear(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReopenYear(null)}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => reopen.mutate()} disabled={reopen.isPending || reason.trim() === ''}>{L('إعادة فتح', 'Reopen')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-4 text-medium text-text-subtle">
          {L('تُعكس قيود الإقفال (ولا تُحذف) وتُفتح كل الفترات.', 'Closing entries are reversed (never deleted) and every period is opened.')}
        </p>
        <Field label={L('السبب', 'Reason')} required>
          <Input value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>

      <Modal
        open={createOpen}
        title={L('سنة مالية جديدة', 'New fiscal year')}
        onClose={() => setCreateOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>{t('cancel')}</Button>
            <Button onClick={() => createYear.mutate()} disabled={createYear.isPending || (customStart && !confirmCustom)}>{t('save')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('name')} required>
            <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('from')} required>
            <Input type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('to')} required>
            <Input type="date" value={form.ends_on} onChange={(event) => setForm({ ...form, ends_on: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {customStart ? (
          <label className="mt-4 flex items-start gap-2 text-medium text-warning-text">
            <input type="checkbox" checked={confirmCustom} onChange={(event) => setConfirmCustom(event.target.checked)} />
            <span>
              {L(
                `تاريخ البداية يختلف عن بداية السنة المالية المضبوطة (${suggestion?.fiscal_year_start ?? ''}). أؤكد المتابعة.`,
                `The start date differs from the configured fiscal year start (${suggestion?.fiscal_year_start ?? ''}). I confirm this is intended.`,
              )}
            </span>
          </label>
        ) : null}
      </Modal>
    </div>
  )
}
