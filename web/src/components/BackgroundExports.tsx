import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { downloadResponse } from '../lib/download'
import { formatDateTime } from '../lib/format'
import { Badge, Button, InlineNotice, Select, Spinner } from './ui'

export type ExportJob = {
  id: string
  report: string
  format: 'csv' | 'xlsx' | 'pdf'
  status: 'queued' | 'running' | 'succeeded' | 'failed'
  file_name: string | null
  size_bytes: number | null
  error: string | null
  created_at: string
  finished_at: string | null
  expires_at: string | null
}

/** True while a job can still change (the list keeps polling). */
export function isActive(job: Pick<ExportJob, 'status'>): boolean {
  return job.status === 'queued' || job.status === 'running'
}

/**
 * Background exports (ADR-0025, AC-RPT-007): run the current report as a job, watch its status,
 * download the file only when it succeeded. The job re-runs the normal export as the user.
 */
export function BackgroundExports({ report, params, disabled }: { report: string; params: Record<string, string>; disabled: boolean }) {
  const { t, locale } = useI18n()
  const { can, activeBranch } = useAuth()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()
  const [format, setFormat] = useState<'csv' | 'xlsx' | 'pdf'>('xlsx')
  const [message, setMessage] = useState('')

  const jobs = useQuery({
    queryKey: ['export-jobs', activeBranch?.id],
    enabled: can('reports.export'),
    queryFn: async () => (await api.get<{ data: ExportJob[] }>('/exports')).data.data,
    refetchInterval: (query) => ((query.state.data ?? []).some(isActive) ? 3000 : false),
  })

  const start = useMutation({
    mutationFn: async () => (await api.post<{ data: ExportJob }>('/exports', { report, format, language: locale, params })).data.data,
    onSuccess: async () => {
      setMessage('')
      await queryClient.invalidateQueries({ queryKey: ['export-jobs'] })
    },
    onError: (cause) => setMessage(errorMessage(cause)),
  })
  const remove = useMutation({
    mutationFn: async (id: string) => api.delete(`/exports/${encodeURIComponent(id)}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['export-jobs'] }),
  })

  async function download(job: ExportJob) {
    try {
      const response = await api.get<Blob>(`/exports/${encodeURIComponent(job.id)}/download`, { responseType: 'blob' })
      downloadResponse(response, job.file_name ?? `${job.report}.${job.format}`)
    } catch {
      setMessage(t('exportFailed'))
      await queryClient.invalidateQueries({ queryKey: ['export-jobs'] })
    }
  }

  if (!can('reports.export')) return null
  const rows = jobs.data ?? []
  const label = (job: ExportJob) => {
    switch (job.status) {
      case 'queued':
        return L('في الانتظار', 'Queued')
      case 'running':
        return L('قيد التنفيذ', 'Running')
      case 'succeeded':
        return L('جاهز', 'Ready')
      default:
        return L('فشل', 'Failed')
    }
  }

  return (
    <section className="flex flex-col gap-3 border-t border-border-subtle px-5 py-4" aria-label={L('التصدير في الخلفية', 'Background export')}>
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="label">{L('تصدير في الخلفية', 'Export in the background')}</h3>
        <Select aria-label={L('الصيغة', 'Format')} value={format} onChange={(event) => setFormat(event.target.value as typeof format)} className="max-w-32">
          <option value="csv">CSV</option>
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
        </Select>
        <Button variant="secondary" icon="download" disabled={disabled || start.isPending} onClick={() => start.mutate()}>
          {L('ابدأ التصدير', 'Start export')}
        </Button>
        <span className="text-small text-text-subtle">
          {L('للتقارير الكبيرة: يعمل بالخلفية ويمكنك متابعة العمل، ويُحمَّل الملف الكامل عند نجاحه فقط.', 'For large reports: it runs in the background and the complete file is offered only when it succeeds.')}
        </span>
      </div>
      {message ? <InlineNotice tone="negative">{message}</InlineNotice> : null}
      {rows.length > 0 ? (
        <ul className="flex flex-col gap-2" aria-label={L('عمليات التصدير', 'Exports')}>
          {rows.map((job) => (
            <li key={job.id} className="flex flex-wrap items-center gap-3 rounded-12 border border-border-subtle px-3 py-2">
              <span className="text-medium font-medium">{job.report}</span>
              <span className="text-small uppercase text-text-subtle">{job.format}</span>
              <span role="status">
                <Badge tone={job.status === 'succeeded' ? 'positive' : job.status === 'failed' ? 'negative' : 'warning'}>
                  {isActive(job) ? <Spinner className="me-1 inline-block h-3 w-3" /> : null}
                  {label(job)}
                </Badge>
              </span>
              <span className="text-small text-text-subtle tabular">{formatDateTime(job.created_at, locale)}</span>
              {job.status === 'failed' && job.error ? <span className="text-small text-danger-text">{job.error}</span> : null}
              <span className="ms-auto flex gap-2">
                {job.status === 'succeeded' ? (
                  <Button variant="secondary" icon="download" onClick={() => void download(job)}>
                    {L('تحميل', 'Download')}
                  </Button>
                ) : null}
                {!isActive(job) ? (
                  <Button variant="ghost" icon="trash" aria-label={L('حذف', 'Delete')} onClick={() => remove.mutate(job.id)} />
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  )
}
