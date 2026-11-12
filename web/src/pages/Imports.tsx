import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import {
  canConfirmImport,
  formatBytes,
  importActionLabel,
  importFileErrorText,
  importPollInterval,
  importRowErrorText,
  importStatusLabel,
  importStatusTone,
  isImportActive,
} from '../lib/imports'
import type { ImportJob, ImportTemplate, ImportTemplates, Paginated } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  InlineNotice,
  Input,
  Loading,
  PageHeader,
  Pagination,
  Select,
  Spinner,
  Table,
  Td,
  Th,
} from '../components/ui'

const text = {
  en: {
    title: 'Data import',
    subtitle: 'Bulk-load customers, suppliers, employees, tour packages, the chart of accounts and opening balances from CSV or Excel.',
    template: '1. Choose data and download the template',
    data: 'Data to import',
    version: 'Template version',
    headerLanguage: 'Header language',
    arabic: 'Arabic',
    english: 'English',
    downloadCsv: 'Download CSV',
    downloadXlsx: 'Download Excel',
    columns: 'Template columns',
    column: 'Column',
    required: 'Required',
    type: 'Type',
    notes: 'Notes',
    upload: '2. Upload and preview',
    duplicatePolicy: 'When a matching record already exists',
    skip: 'Skip it',
    update: 'Update it',
    fail: 'Report an error',
    onError: 'When some rows have errors',
    onErrorSkip: 'Import the valid rows',
    onErrorAbort: 'Import nothing',
    atomic: 'This file is imported all-or-nothing: any row error stops the whole import.',
    file: 'File (.csv or .xlsx)',
    limits: (rows: number, size: string) => `Up to ${rows.toLocaleString('en')} rows and ${size}. Cells starting with = + - or @ are rejected.`,
    preview: 'Upload and preview',
    uploading: 'Uploading…',
    result: '3. Review and confirm',
    status: 'Status',
    rows: 'Rows',
    toCreate: 'To create',
    toUpdate: 'To update',
    skipped: 'Skipped',
    errors: 'Rows with errors',
    created: 'Created',
    updated: 'Updated',
    confirm: 'Confirm import',
    confirming: 'Starting…',
    discard: 'Discard',
    newImport: 'New import',
    errorReport: 'Download error report',
    errorList: 'Row errors',
    moreErrors: 'Showing the first rows only; download the report for all of them.',
    row: 'Row',
    action: 'Action',
    field: 'Field',
    message: 'Message',
    previewRows: 'Preview (first rows)',
    unknownColumns: 'Ignored columns not in the template',
    queuedHint: 'Large files are processed in the background. This page refreshes automatically.',
    previewedHint: 'Nothing has been written yet. Review the rows, then confirm.',
    completedHint: 'Import finished.',
    partialHint: 'Some rows were imported and some failed. Download the error report, fix those rows and upload them again.',
    failedHint: 'Nothing was imported.',
    batchCreated: 'A draft opening-balance batch was created. Review and post it from Opening balances.',
    asOfDate: 'As-of date',
    memo: 'Memo',
    history: 'Recent imports',
    noHistory: 'No imports yet',
    date: 'Date',
    file2: 'File',
    by: 'By',
    open: 'Open',
    loadError: 'Could not load import templates',
    noTemplates: 'You do not have permission to import any data.',
    retry: 'Retry',
  },
  ar: {
    title: 'استيراد البيانات',
    subtitle: 'تحميل العملاء والموردين والموظفين والبرامج السياحية ودليل الحسابات والأرصدة الافتتاحية دفعة واحدة من ملف CSV أو Excel.',
    template: '١. اختر البيانات ونزّل القالب',
    data: 'البيانات المراد استيرادها',
    version: 'إصدار القالب',
    headerLanguage: 'لغة العناوين',
    arabic: 'العربية',
    english: 'الإنجليزية',
    downloadCsv: 'تنزيل CSV',
    downloadXlsx: 'تنزيل Excel',
    columns: 'أعمدة القالب',
    column: 'العمود',
    required: 'مطلوب',
    type: 'النوع',
    notes: 'ملاحظات',
    upload: '٢. الرفع والمعاينة',
    duplicatePolicy: 'عند وجود سجل مطابق',
    skip: 'تخطيه',
    update: 'تحديثه',
    fail: 'تسجيله كخطأ',
    onError: 'عند وجود أخطاء في بعض الصفوف',
    onErrorSkip: 'استيراد الصفوف السليمة',
    onErrorAbort: 'عدم استيراد أي شيء',
    atomic: 'يُستورد هذا الملف كاملاً أو لا يُستورد: أي خطأ في صف يوقف الاستيراد بالكامل.',
    file: 'الملف (‎.csv أو ‎.xlsx)',
    limits: (rows: number, size: string) => `حتى ${rows.toLocaleString('ar')} صف و${size}. تُرفض الخلايا التي تبدأ بـ = + - أو @.`,
    preview: 'رفع ومعاينة',
    uploading: 'جارٍ الرفع…',
    result: '٣. المراجعة والتأكيد',
    status: 'الحالة',
    rows: 'الصفوف',
    toCreate: 'سيُنشأ',
    toUpdate: 'سيُحدَّث',
    skipped: 'متخطى',
    errors: 'صفوف بها أخطاء',
    created: 'أُنشئ',
    updated: 'حُدِّث',
    confirm: 'تأكيد الاستيراد',
    confirming: 'جارٍ البدء…',
    discard: 'إلغاء المعاينة',
    newImport: 'استيراد جديد',
    errorReport: 'تنزيل تقرير الأخطاء',
    errorList: 'أخطاء الصفوف',
    moreErrors: 'تُعرض الأخطاء الأولى فقط؛ نزّل التقرير لرؤيتها كلها.',
    row: 'صف',
    action: 'الإجراء',
    field: 'الحقل',
    message: 'الرسالة',
    previewRows: 'معاينة (أول الصفوف)',
    unknownColumns: 'أعمدة متجاهلة ليست في القالب',
    queuedHint: 'تُعالج الملفات الكبيرة في الخلفية، وتتحدث هذه الصفحة تلقائياً.',
    previewedHint: 'لم يُكتب أي شيء بعد. راجع الصفوف ثم أكّد.',
    completedHint: 'اكتمل الاستيراد.',
    partialHint: 'استُورد بعض الصفوف وفشل بعضها. نزّل تقرير الأخطاء وصحح الصفوف وارفعها مجدداً.',
    failedHint: 'لم يُستورد أي شيء.',
    batchCreated: 'أُنشئت دفعة أرصدة افتتاحية بحالة مسودة. راجعها وارحّلها من صفحة الأرصدة الافتتاحية.',
    asOfDate: 'تاريخ الرصيد',
    memo: 'ملاحظة',
    history: 'آخر عمليات الاستيراد',
    noHistory: 'لا توجد عمليات استيراد بعد',
    date: 'التاريخ',
    file2: 'الملف',
    by: 'بواسطة',
    open: 'فتح',
    loadError: 'تعذر تحميل قوالب الاستيراد',
    noTemplates: 'لا تملك صلاحية استيراد أي بيانات.',
    retry: 'إعادة المحاولة',
  },
} as const

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function filenameFrom(disposition: string | undefined, fallback: string): string {
  const match = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  return match ? decodeURIComponent(match[1]) : fallback
}

function apiErrorText(cause: unknown, locale: 'ar' | 'en'): string {
  if (axios.isAxiosError<{ code?: string; message?: string }>(cause)) {
    const data = cause.response?.data
    if (data?.code) return importFileErrorText(data.code, errorMessage(cause), locale)
  }
  return errorMessage(cause)
}

export default function Imports() {
  const { locale } = useI18n()
  const tx = text[locale]
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()

  const [entity, setEntity] = useState(searchParams.get('entity') ?? '')
  const [headerLocale, setHeaderLocale] = useState<'ar' | 'en'>(locale)
  const [policy, setPolicy] = useState<'skip' | 'update' | 'fail' | ''>('')
  const [onError, setOnError] = useState<'skip' | 'abort'>('skip')
  const [file, setFile] = useState<File | null>(null)
  const [fileKey, setFileKey] = useState(0)
  const [options, setOptions] = useState<Record<string, string>>({})
  const [jobId, setJobId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [historyPage, setHistoryPage] = useState(1)

  const templatesQuery = useQuery({
    queryKey: ['import-templates', activeBranch?.id],
    queryFn: async () => (await api.get<{ data: ImportTemplates }>('/imports/templates')).data.data,
  })

  const templates = templatesQuery.data?.templates
  const template: ImportTemplate | undefined = useMemo(
    () => templates?.find((item) => item.entity === entity) ?? templates?.[0],
    [templates, entity],
  )
  const effectivePolicy = policy || template?.default_duplicate_policy || 'skip'

  const jobQuery = useQuery({
    queryKey: ['import-job', jobId],
    enabled: !!jobId,
    queryFn: async () => (await api.get<{ data: ImportJob }>(`/imports/${jobId}`)).data.data,
    refetchInterval: (query) => importPollInterval(query.state.data?.status),
  })
  const job = jobQuery.data

  const historyQuery = useQuery({
    queryKey: ['imports', activeBranch?.id, historyPage],
    queryFn: async () => (await api.get<Paginated<ImportJob>>('/imports', { params: { per_page: 8, page: historyPage } })).data,
    refetchInterval: job && isImportActive(job.status) ? 4000 : false,
  })

  // A finished job changes the history list and the data lists behind it.
  const finalStatus = job && !isImportActive(job.status) && job.status !== 'previewed' ? job.status : null
  useEffect(() => {
    if (finalStatus) void queryClient.invalidateQueries({ queryKey: ['imports'] })
  }, [finalStatus, job?.id, queryClient])

  const upload = useMutation({
    mutationFn: async () => {
      if (!template || !file) throw new Error('missing')
      const body = new FormData()
      body.append('entity', template.entity)
      body.append('file', file)
      body.append('dry_run', '1')
      body.append('template_version', String(template.version))
      if (template.supports_duplicate_policy) body.append('duplicate_policy', effectivePolicy)
      body.append('on_error', template.atomic ? 'skip' : onError)
      for (const option of template.options) {
        if (options[option.key]) body.append(`options[${option.key}]`, options[option.key])
      }
      return (await api.post<{ data: ImportJob }>('/imports', body)).data.data
    },
    onSuccess: (created) => {
      setError('')
      setJobId(created.id)
      void queryClient.invalidateQueries({ queryKey: ['imports'] })
    },
    onError: (cause) => {
      setJobId(null)
      setError(apiErrorText(cause, locale))
    },
  })

  const confirm = useMutation({
    mutationFn: async () => (await api.post<{ data: ImportJob }>(`/imports/${jobId}/confirm`)).data.data,
    onSuccess: (updated) => {
      setError('')
      queryClient.setQueryData(['import-job', updated.id], updated)
      void queryClient.invalidateQueries({ queryKey: ['imports'] })
    },
    onError: (cause) => setError(apiErrorText(cause, locale)),
  })

  const discard = useMutation({
    mutationFn: async () => api.delete(`/imports/${jobId}`),
    onSuccess: () => {
      resetJob()
      void queryClient.invalidateQueries({ queryKey: ['imports'] })
    },
    onError: (cause) => setError(apiErrorText(cause, locale)),
  })

  function resetJob() {
    setJobId(null)
    setFile(null)
    setFileKey((key) => key + 1)
    setError('')
  }

  async function downloadTemplate(format: 'csv' | 'xlsx') {
    if (!template) return
    try {
      const response = await api.get<Blob>(`/imports/templates/${template.entity}`, {
        params: { format, locale: headerLocale },
        responseType: 'blob',
      })
      saveBlob(response.data, filenameFrom(response.headers['content-disposition'], `${template.entity}-template.${format}`))
    } catch (cause) {
      setError(apiErrorText(cause, locale))
    }
  }

  async function downloadReport(target: ImportJob) {
    try {
      const response = await api.get<Blob>(`/imports/${target.id}/errors`, { responseType: 'blob' })
      saveBlob(response.data, filenameFrom(response.headers['content-disposition'], `import-${target.entity}-errors.csv`))
    } catch (cause) {
      setError(apiErrorText(cause, locale))
    }
  }

  const missingOption = !!template?.options.some((option) => option.required && !options[option.key])
  const entityLabel = (key: string) => {
    const found = templates?.find((item) => item.entity === key)
    return found ? (locale === 'ar' ? found.label_ar : found.label_en) : key
  }
  const jobTemplate = templates?.find((item) => item.entity === job?.entity)
  const columnLabel = (key: string) => {
    const column = jobTemplate?.columns.find((item) => item.key === key)
    return column ? (locale === 'ar' ? column.label_ar : column.label_en) : key
  }
  const previewKeys = useMemo(() => {
    const keys: string[] = []
    for (const row of job?.preview ?? []) {
      for (const key of Object.keys(row.values ?? {})) {
        if (key !== 'reason' && !keys.includes(key)) keys.push(key)
      }
    }
    return keys.slice(0, 5)
  }, [job?.preview])
  const batchId = (job?.options?.result as { opening_balance_batch_id?: string } | undefined)?.opening_balance_batch_id

  if (templatesQuery.isLoading) return <Loading />
  if (templatesQuery.isError) return <ErrorState message={tx.loadError} onRetry={() => void templatesQuery.refetch()} />

  return (
    <div>
      <PageHeader title={tx.title} subtitle={tx.subtitle} icon="upload" />

      {!template ? (
        <EmptyState title={tx.noTemplates} />
      ) : (
        <div className="grid gap-6">
          <Card>
            <CardHeader title={tx.template} />
<div className="p-5">
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label={tx.data}>
                <Select
                  value={template.entity}
                  onChange={(event) => {
                    setEntity(event.target.value)
                    setPolicy('')
                    setOptions({})
                    resetJob()
                  }}
                >
                  {templates?.map((item) => (
                    <option key={item.entity} value={item.entity}>
                      {locale === 'ar' ? item.label_ar : item.label_en}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label={tx.headerLanguage}>
                <Select value={headerLocale} onChange={(event) => setHeaderLocale(event.target.value as 'ar' | 'en')}>
                  <option value="ar">{tx.arabic}</option>
                  <option value="en">{tx.english}</option>
                </Select>
              </Field>
              <div className="flex items-end gap-2">
                <Button variant="secondary" icon="download" onClick={() => void downloadTemplate('csv')}>
                  {tx.downloadCsv}
                </Button>
                <Button variant="secondary" icon="download" onClick={() => void downloadTemplate('xlsx')}>
                  {tx.downloadXlsx}
                </Button>
              </div>
            </div>
            <p className="text-small mt-3 text-text-subtle">
              {tx.version}: <Badge tone="brand">v{template.version}</Badge>
            </p>
            <details className="mt-4">
              <summary className="label cursor-pointer">{tx.columns}</summary>
              <div className="mt-3">
                <Table>
                  <thead>
                    <tr>
                      <Th>{tx.column}</Th>
                      <Th>{tx.required}</Th>
                      <Th>{tx.type}</Th>
                      <Th>{tx.notes}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {template.columns.map((column) => (
                      <tr key={column.key}>
                        <Td>{locale === 'ar' ? column.label_ar : column.label_en}</Td>
                        <Td>{column.required ? '*' : ''}</Td>
                        <Td>{column.type}</Td>
                        <Td>{locale === 'ar' ? column.description_ar : column.description_en}</Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </div>
            </details>
          </div>
</Card>

          <Card>
            <CardHeader title={tx.upload} />
<div className="p-5">
            <div className="grid gap-4 sm:grid-cols-2">
              {template.supports_duplicate_policy ? (
                <Field label={tx.duplicatePolicy}>
                  <Select value={effectivePolicy} onChange={(event) => setPolicy(event.target.value as 'skip' | 'update' | 'fail')}>
                    <option value="skip">{tx.skip}</option>
                    <option value="update">{tx.update}</option>
                    <option value="fail">{tx.fail}</option>
                  </Select>
                </Field>
              ) : null}
              {!template.atomic ? (
                <Field label={tx.onError}>
                  <Select value={onError} onChange={(event) => setOnError(event.target.value as 'skip' | 'abort')}>
                    <option value="skip">{tx.onErrorSkip}</option>
                    <option value="abort">{tx.onErrorAbort}</option>
                  </Select>
                </Field>
              ) : null}
              {template.options.map((option) => (
                <Field key={option.key} label={locale === 'ar' ? option.label_ar : option.label_en} required={option.required}>
                  <Input
                    type={option.key === 'as_of_date' ? 'date' : 'text'}
                    value={options[option.key] ?? ''}
                    onChange={(event) => setOptions((current) => ({ ...current, [option.key]: event.target.value }))}
                  />
                </Field>
              ))}
              <Field
                label={tx.file}
                required
                hint={tx.limits(templatesQuery.data?.limits.max_rows ?? 0, formatBytes(templatesQuery.data?.limits.max_file_bytes ?? 0))}
              >
                <Input
                  key={fileKey}
                  type="file"
                  accept=".csv,.xlsx"
                  onChange={(event) => {
                    setFile(event.target.files?.[0] ?? null)
                    setJobId(null)
                    setError('')
                  }}
                />
              </Field>
            </div>
            {template.atomic ? <p className="text-small mt-3 text-text-subtle">{tx.atomic}</p> : null}
            {error ? (
              <div className="mt-4" role="alert">
                <InlineNotice tone="negative">{error}</InlineNotice>
              </div>
            ) : null}
            <div className="mt-4 flex justify-end">
              <Button onClick={() => upload.mutate()} disabled={upload.isPending || !file || missingOption}>
                {upload.isPending ? tx.uploading : tx.preview}
              </Button>
            </div>
          </div>
</Card>

          {job ? (
            <Card>
              <CardHeader title={`${tx.result} — ${entityLabel(job.entity)}`} />
<div className="p-5">
              <div className="flex flex-wrap items-center gap-3">
                <Badge tone={importStatusTone(job.status)}>{importStatusLabel(job.status, locale)}</Badge>
                {isImportActive(job.status) ? <Spinner /> : null}
                <span className="text-small text-text-subtle">
                  {job.original_name} · {formatBytes(job.file_size)} · v{job.template_version}
                </span>
              </div>
              <p className="text-medium mt-3 text-text-subtle">
                {isImportActive(job.status)
                  ? tx.queuedHint
                  : job.status === 'previewed'
                    ? tx.previewedHint
                    : job.status === 'completed'
                      ? tx.completedHint
                      : job.status === 'partial'
                        ? tx.partialHint
                        : job.failure_message
                          ? importFileErrorText(job.failure_code ?? undefined, job.failure_message, locale)
                          : tx.failedHint}
              </p>

              <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                {[
                  [tx.rows, job.total_rows],
                  [job.status === 'previewed' ? tx.toCreate : tx.created, job.created_count],
                  [job.status === 'previewed' ? tx.toUpdate : tx.updated, job.updated_count],
                  [tx.skipped, job.skipped_count],
                  [tx.errors, job.error_count],
                ].map(([label, value]) => (
                  <div key={String(label)} className="rounded-12 border border-border-subtle bg-surface-subtle px-3 py-2">
                    <dt className="text-small text-text-subtle">{label}</dt>
                    <dd className="heading-small tabular">{Number(value).toLocaleString(locale)}</dd>
                  </div>
                ))}
              </dl>

              {job.warnings?.map((warning) => (
                <p key={warning.code} className="text-small mt-3 text-warning-text">
                  {tx.unknownColumns}: {warning.columns?.join(', ')}
                </p>
              ))}
              {batchId ? <div className="mt-3"><InlineNotice tone="positive">{tx.batchCreated}</InlineNotice></div> : null}

              {job.errors && job.errors.length > 0 ? (
                <div className="mt-5">
                  <h3 className="label mb-2">{tx.errorList}</h3>
                  <div className="max-h-72 overflow-y-auto">
                    <Table>
                      <thead>
                        <tr>
                          <Th>{tx.row}</Th>
                          <Th>{tx.field}</Th>
                          <Th>{tx.message}</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {job.errors.map((item, index) => (
                          <tr key={`${item.row}-${item.code}-${index}`}>
                            <Td>{item.row || '—'}</Td>
                            <Td>{item.field ? columnLabel(item.field) : '—'}</Td>
                            <Td>{importRowErrorText(item, locale)}</Td>
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  </div>
                  {job.error_count > job.errors.length ? <p className="text-small mt-2 text-text-subtle">{tx.moreErrors}</p> : null}
                </div>
              ) : null}

              {job.preview && job.preview.length > 0 ? (
                <div className="mt-5">
                  <h3 className="label mb-2">{tx.previewRows}</h3>
                  <div className="max-h-80 overflow-auto">
                    <Table>
                      <thead>
                        <tr>
                          <Th>{tx.row}</Th>
                          <Th>{tx.action}</Th>
                          {previewKeys.map((key) => (
                            <Th key={key}>{columnLabel(key)}</Th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {job.preview.map((item) => (
                          <tr key={`${item.row}-${item.action}`}>
                            <Td>{item.row || '—'}</Td>
                            <Td>
                              <Badge tone={item.action === 'error' ? 'negative' : item.action === 'skip' ? 'neutral' : 'positive'}>
                                {importActionLabel(item.action, locale)}
                              </Badge>
                            </Td>
                            {previewKeys.map((key) => (
                              <Td key={key}>{item.values?.[key] == null ? '' : String(item.values[key])}</Td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </Table>
                  </div>
                </div>
              ) : null}

              <div className="mt-5 flex flex-wrap justify-end gap-2">
                {job.has_error_report ? (
                  <Button variant="secondary" icon="download" onClick={() => void downloadReport(job)}>
                    {tx.errorReport}
                  </Button>
                ) : null}
                {job.status === 'previewed' ? (
                  <Button variant="secondary" onClick={() => discard.mutate()} disabled={discard.isPending}>
                    {tx.discard}
                  </Button>
                ) : null}
                {job.status === 'previewed' ? (
                  <Button onClick={() => confirm.mutate()} disabled={confirm.isPending || !canConfirmImport(job)}>
                    {confirm.isPending ? tx.confirming : tx.confirm}
                  </Button>
                ) : null}
                {job.status !== 'previewed' && !isImportActive(job.status) ? (
                  <Button onClick={resetJob}>{tx.newImport}</Button>
                ) : null}
              </div>
            </div>
</Card>
          ) : null}

          <Card>
            <CardHeader title={tx.history} />
<div className="p-5">
            {historyQuery.data && historyQuery.data.data.length > 0 ? (
              <>
                <Table>
                  <thead>
                    <tr>
                      <Th>{tx.date}</Th>
                      <Th>{tx.data}</Th>
                      <Th>{tx.file2}</Th>
                      <Th>{tx.status}</Th>
                      <Th>{tx.by}</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    {historyQuery.data.data.map((item) => (
                      <tr key={item.id}>
                        <Td>{formatDateTime(item.created_at, locale)}</Td>
                        <Td>{entityLabel(item.entity)}</Td>
                        <Td>{item.original_name}</Td>
                        <Td>
                          <Badge tone={importStatusTone(item.status)}>{importStatusLabel(item.status, locale)}</Badge>
                        </Td>
                        <Td>{item.created_by ?? '—'}</Td>
                        <Td>
                          <Button variant="ghost" onClick={() => setJobId(item.id)}>
                            {tx.open}
                          </Button>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
                <Pagination page={historyPage} lastPage={historyQuery.data.meta.last_page} onChange={setHistoryPage} />
              </>
            ) : (
              <p className="text-medium text-text-subtle">{historyQuery.isLoading ? '…' : tx.noHistory}</p>
            )}
          </div>
</Card>
        </div>
      )}
    </div>
  )
}
