import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { Badge, Button, Card, CardHeader, Field, Modal, Select, Textarea } from './ui'

type Resolution = {
  status: 'approved' | 'rejected' | 'deferred'
  option?: string
  note: string
  decided_by: string
  decided_by_name: string
  decided_at: string
}

type Decision = {
  id: string
  type: string
  title_en: string
  title_ar: string
  blocks: string
  status: string
  resolution: Resolution | null
}

const statuses: Array<{ value: Resolution['status']; ar: string; en: string }> = [
  { value: 'approved', ar: 'معتمد', en: 'Approved' },
  { value: 'rejected', ar: 'مرفوض', en: 'Rejected' },
  { value: 'deferred', ar: 'مؤجل', en: 'Deferred' },
]

/** Open accounting decisions with resolution tracking (settings.update records a response). */
export function DecisionsPanel() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const [target, setTarget] = useState<Decision | null>(null)
  const [status, setStatus] = useState<Resolution['status']>('approved')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')

  const query = useQuery({
    queryKey: ['decisions'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Decision[] }>('/decisions')
      return data.data
    },
    enabled: can('settings.view'),
  })

  const resolve = useMutation({
    mutationFn: async () => {
      if (!target) return
      await api.post(`/decisions/${target.id}/resolve`, { status, note })
    },
    onSuccess: async () => {
      setTarget(null)
      setNote('')
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['decisions'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const open = (decision: Decision) => {
    setError('')
    setNote(decision.resolution?.note ?? '')
    setStatus(decision.resolution?.status ?? 'approved')
    setTarget(decision)
  }

  const label = (value: Resolution['status']) => {
    const found = statuses.find((item) => item.value === value)
    return found ? L(found.ar, found.en) : value
  }

  if (query.isError) {
    return (
      <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
        {L('تعذّر تحميل القرارات المعلّقة', 'Could not load unresolved decisions')}
      </p>
    )
  }

  if (!query.data || query.data.length === 0) return null

  return (
    <>
      <Card className="mb-6 border-warning-border">
        <CardHeader
          title={L('قرارات محاسبية بانتظار الاعتماد', 'Accounting decisions awaiting approval')}
          subtitle={L(
            'هذه القرارات قابلة للتهيئة لكل تثبيت ولم تُفترض في الكود.',
            'These decisions are configurable per installation and are not silently assumed in code.',
          )}
        />
        <ul>
          {query.data.map((decision) => (
            <li key={decision.id} className="flex flex-wrap items-start gap-2 border-b border-border-subtle px-5 py-3 last:border-b-0">
              <Badge tone="warning">{decision.id}</Badge>
              <div className="min-w-0 flex-1">
                <p className="text-medium font-medium text-text-default">{locale === 'ar' ? decision.title_ar : decision.title_en}</p>
                <p className="text-small text-text-subtle">
                  {L('يعطّل', 'Blocks')}: <span className="tabular">{decision.blocks}</span>
                </p>
                {decision.resolution ? (
                  <p className="text-small text-text-subtle">
                    {decision.resolution.note} — {decision.resolution.decided_by_name} · {formatDateTime(decision.resolution.decided_at, locale)}
                  </p>
                ) : null}
              </div>
              <Badge tone={decision.resolution?.status === 'rejected' ? 'negative' : 'neutral'}>
                {decision.resolution ? label(decision.resolution.status) : L('مفتوح', 'Open')}
              </Badge>
              {can('settings.update') ? (
                <Button variant="ghost" onClick={() => open(decision)}>
                  {L('تسجيل قرار', 'Record decision')}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      </Card>

      <Modal
        open={target !== null}
        title={L('تسجيل قرار', 'Record decision')}
        onClose={() => setTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setTarget(null)}>{L('إلغاء', 'Cancel')}</Button>
            <Button onClick={() => resolve.mutate()} disabled={resolve.isPending || note.trim() === ''}>{L('حفظ', 'Save')}</Button>
          </>
        }
      >
        {target ? (
          <div className="flex flex-col gap-4">
            <p className="text-medium font-medium">{target.id} — {locale === 'ar' ? target.title_ar : target.title_en}</p>
            <Field label={L('الحالة', 'Status')} required>
              <Select value={status} onChange={(event) => setStatus(event.target.value as Resolution['status'])}>
                {statuses.map((item) => (
                  <option key={item.value} value={item.value}>{L(item.ar, item.en)}</option>
                ))}
              </Select>
            </Field>
            <Field label={L('ملاحظة', 'Note')} required>
              <Textarea value={note} maxLength={1000} onChange={(event) => setNote(event.target.value)} />
            </Field>
            {error ? <p className="text-small text-danger-text">{error}</p> : null}
          </div>
        ) : null}
      </Modal>
    </>
  )
}
