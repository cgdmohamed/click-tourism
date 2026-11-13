import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { CATEGORIES, FIELDS, canExtract, categoryLabel, formatSize, initialValues, invoicePrefill, toConfirmPayload } from '../lib/attachments'
import type { AttachableType, AttachmentRow, Extraction } from '../lib/attachments'
import { formatDateTime } from '../lib/format'
import { useFeatureFlags } from '../lib/useSetting'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, InlineNotice, Input, Loading, Modal, Select, Table, Td, Th } from './ui'

type Listing = { items: AttachmentRow[]; hidden_sensitive_count: number }

/** Fetches a protected file with the session token and exposes it as a temporary object URL. */
function useFileUrl(attachmentId: string | null) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    if (!attachmentId) return
    let alive = true
    let created: string | null = null
    api
      .get(`/attachments/${attachmentId}/preview`, { responseType: 'blob' })
      .then((response) => {
        if (!alive) return
        created = URL.createObjectURL(response.data as Blob)
        setUrl(created)
      })
      .catch(() => alive && setFailed(true))
    return () => {
      alive = false
      if (created) URL.revokeObjectURL(created)
      setUrl(null)
      setFailed(false)
    }
  }, [attachmentId])
  return { url, failed }
}

/**
 * Documents of one record (ADR-0021): upload several files, classify, preview, download, add notes
 * and, when switched on, ask for the details to be read automatically. Reading is a proposal that
 * a person reviews and corrects; failure never blocks entering the details by hand.
 */
export default function AttachmentsCard({ type, recordId, bare = false }: { type: AttachableType; recordId: string; bare?: boolean }) {
  const { locale } = useI18n()
  const { can, activeBranch } = useAuth()
  const { flags } = useFeatureFlags()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const fileInput = useRef<HTMLInputElement>(null)

  const [category, setCategory] = useState('other')
  const [notes, setNotes] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [rejected, setRejected] = useState<{ name: string; reason: string }[]>([])
  const [preview, setPreview] = useState<AttachmentRow | null>(null)
  const [editing, setEditing] = useState<AttachmentRow | null>(null)
  const [removing, setRemoving] = useState<AttachmentRow | null>(null)
  const [reviewing, setReviewing] = useState<AttachmentRow | null>(null)

  const queryKey = ['attachments', type, recordId, activeBranch?.id]
  const listing = useQuery({
    queryKey,
    enabled: can('attachments.view'),
    queryFn: async () => (await api.get<{ data: Listing }>('/attachments', { params: { attachable_type: type, attachable_id: recordId } })).data.data,
  })

  const upload = useMutation({
    mutationFn: async () => {
      const form = new FormData()
      form.append('attachable_type', type)
      form.append('attachable_id', recordId)
      form.append('category', category)
      if (notes.trim()) form.append('notes', notes.trim())
      files.forEach((file) => form.append('files[]', file))
      return (await api.post<{ data: { items: AttachmentRow[]; rejected: { name: string; reason: string }[] } }>('/attachments', form)).data.data
    },
    onSuccess: async (result) => {
      setError('')
      setRejected(result.rejected)
      const duplicates = result.items.filter((item) => item.duplicate).length
      setMessage(
        L(`تم رفع ${result.items.length - duplicates} ملف.`, `${result.items.length - duplicates} file(s) uploaded.`) +
          (duplicates ? L(` ${duplicates} موجود مسبقاً ولم يتكرر.`, ` ${duplicates} already existed and was not duplicated.`) : ''),
      )
      setFiles([])
      setNotes('')
      if (fileInput.current) fileInput.current.value = ''
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const remove = useMutation({
    mutationFn: async (row: AttachmentRow) => api.delete(`/attachments/${row.id}`),
    onSuccess: async () => {
      setRemoving(null)
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (e) => setError(errorMessage(e)),
  })

  async function download(row: AttachmentRow) {
    try {
      const response = await api.get(`/attachments/${row.id}/download`, { responseType: 'blob' })
      const href = URL.createObjectURL(response.data as Blob)
      const link = document.createElement('a')
      link.href = href
      link.download = row.name
      link.click()
      URL.revokeObjectURL(href)
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  if (!can('attachments.view')) return null
  const rows = listing.data?.items ?? []
  const extractionOn = flags?.attachments_extraction_enabled === true && can('attachments.extract')

  const body = (
    <>
      {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
      {message ? <InlineNotice tone="positive">{message}</InlineNotice> : null}
      {rejected.length > 0 ? (
        <InlineNotice tone="warning">
          {rejected.map((item) => (
            <p key={item.name}>{item.name}: {item.reason}</p>
          ))}
        </InlineNotice>
      ) : null}

      {can('attachments.upload') ? (
        <form
          className="grid gap-3 border-b border-border-subtle px-5 py-4 sm:grid-cols-12"
          onSubmit={(event) => {
            event.preventDefault()
            if (files.length > 0) upload.mutate()
          }}
        >
          <div className="sm:col-span-4">
            <Field label={L('التصنيف', 'Category')}>
              <Select value={category} onChange={(event) => setCategory(event.target.value)}>
                {CATEGORIES.map((entry) => (
                  <option key={entry.value} value={entry.value}>{entry[locale === 'ar' ? 'ar' : 'en']}</option>
                ))}
              </Select>
            </Field>
          </div>
          <div className="sm:col-span-4">
            <Field label={L('ملاحظات', 'Notes')}>
              <Input value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={1000} />
            </Field>
          </div>
          <div className="sm:col-span-4">
            <Field label={L('الملفات (مستندات أو صور)', 'Files (documents or images)')}>
              <input
                ref={fileInput}
                type="file"
                multiple
                accept="application/pdf,image/jpeg,image/png,image/webp"
                className="text-small"
                onChange={(event) => setFiles(Array.from(event.target.files ?? []))}
              />
            </Field>
          </div>
          <div className="sm:col-span-12">
            <Button type="submit" icon="upload" disabled={files.length === 0 || upload.isPending}>
              {L('رفع', 'Upload')}
            </Button>
          </div>
        </form>
      ) : null}

      {listing.isLoading ? <Loading /> : null}
      {listing.isError ? <ErrorState message={errorMessage(listing.error)} onRetry={() => void listing.refetch()} /> : null}
      {listing.data && (listing.data.hidden_sensitive_count ?? 0) > 0 ? (
        <p className="px-5 pt-3 text-small text-text-subtle">
          {L(`${listing.data.hidden_sensitive_count} مستند يحتوي بيانات شخصية لا تملك صلاحية عرضه.`, `${listing.data.hidden_sensitive_count} document(s) with personal data are hidden from your role.`)}
        </p>
      ) : null}
      {listing.data && rows.length === 0 ? <EmptyState title={L('لا توجد مستندات', 'No documents')} /> : null}
      {rows.length > 0 ? (
        <Table>
          <thead>
            <tr>
              <Th>{L('الملف', 'File')}</Th>
              <Th>{L('التصنيف', 'Category')}</Th>
              <Th>{L('الحجم', 'Size')}</Th>
              <Th>{L('رُفع بواسطة', 'Uploaded by')}</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <Td>
                  {row.name}
                  {row.notes ? <div className="text-small text-text-subtle">{row.notes}</div> : null}
                </Td>
                <Td>
                  <Badge tone={row.sensitive ? 'warning' : 'neutral'}>{categoryLabel(row.category, locale === 'ar' ? 'ar' : 'en')}</Badge>
                </Td>
                <Td className="tabular">{formatSize(row.size_bytes)}</Td>
                <Td>
                  {row.uploaded_by ?? '—'}
                  <div className="text-small text-text-subtle">{row.created_at ? formatDateTime(row.created_at, locale) : ''}</div>
                </Td>
                <Td className="text-end">
                  <div className="flex flex-wrap justify-end gap-1">
                    <Button variant="ghost" icon="eye" aria-label={L('معاينة', 'Preview')} onClick={() => setPreview(row)} />
                    <Button variant="ghost" icon="download" aria-label={L('تنزيل', 'Download')} onClick={() => void download(row)} />
                    {extractionOn && canExtract(row.category) ? (
                      <Button variant="ghost" icon="sparkles" aria-label={L('قراءة البيانات تلقائياً', 'Read details automatically')} onClick={() => setReviewing(row)} />
                    ) : null}
                    {can('attachments.upload') ? <Button variant="ghost" icon="edit" aria-label={L('تعديل', 'Edit')} onClick={() => setEditing(row)} /> : null}
                    {can('attachments.delete') ? <Button variant="ghost" icon="trash" aria-label={L('حذف', 'Delete')} onClick={() => setRemoving(row)} /> : null}
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      ) : null}

      <PreviewModal row={preview} onClose={() => setPreview(null)} />
      <EditModal row={editing} queryKey={queryKey} onClose={() => setEditing(null)} />
      <ReviewModal row={reviewing} onClose={() => setReviewing(null)} />
      <Modal
        open={removing !== null}
        title={L('حذف المستند', 'Delete document')}
        onClose={() => setRemoving(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRemoving(null)}>{L('إلغاء', 'Cancel')}</Button>
            <Button variant="danger" disabled={remove.isPending} onClick={() => removing && remove.mutate(removing)}>{L('حذف', 'Delete')}</Button>
          </>
        }
      >
        <p className="text-medium">
          {L('سيختفي المستند من السجل ويبقى الملف محفوظاً لأغراض التدقيق.', 'The document disappears from the record; the file is kept for audit.')} {removing?.name}
        </p>
      </Modal>
    </>
  )

  if (bare) return <div>{body}</div>

  return (
    <Card>
      <CardHeader title={L('المستندات', 'Documents')} icon="folder" actions={<Badge tone="neutral">{rows.length}</Badge>} />
      {body}
    </Card>
  )
}

function PreviewModal({ row, onClose }: { row: AttachmentRow | null; onClose: () => void }) {
  const { locale } = useI18n()
  const { url, failed } = useFileUrl(row?.id ?? null)
  return (
    <Modal open={row !== null} title={row?.name ?? ''} onClose={onClose} footer={<Button variant="secondary" onClick={onClose}>{locale === 'ar' ? 'إغلاق' : 'Close'}</Button>}>
      <DocumentView url={url} failed={failed} mime={row?.mime ?? ''} />
    </Modal>
  )
}

function DocumentView({ url, failed, mime }: { url: string | null; failed: boolean; mime: string }) {
  const { locale } = useI18n()
  if (failed) return <InlineNotice tone="negative">{locale === 'ar' ? 'تعذّر تحميل المستند.' : 'The document could not be loaded.'}</InlineNotice>
  if (!url) return <Loading />
  return mime === 'application/pdf' ? (
    <iframe src={url} title="document" sandbox="" className="h-[60vh] w-full rounded-12 border border-border-default" />
  ) : (
    <img src={url} alt="" className="max-h-[60vh] w-full rounded-12 border border-border-default object-contain" />
  )
}

function EditModal({ row, queryKey, onClose }: { row: AttachmentRow | null; queryKey: unknown[]; onClose: () => void }) {
  const { locale } = useI18n()
  const queryClient = useQueryClient()
  const [category, setCategory] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  useEffect(() => {
    setCategory(row?.category ?? '')
    setNotes(row?.notes ?? '')
    setError('')
  }, [row])
  const save = useMutation({
    mutationFn: async () => api.patch(`/attachments/${row?.id}`, { category, notes: notes.trim() || null }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Modal
      open={row !== null}
      title={locale === 'ar' ? 'تعديل المستند' : 'Edit document'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>{locale === 'ar' ? 'حفظ' : 'Save'}</Button>
        </>
      }
    >
      {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
      <div className="flex flex-col gap-3">
        <Field label={locale === 'ar' ? 'التصنيف' : 'Category'}>
          <Select value={category} onChange={(event) => setCategory(event.target.value)}>
            {CATEGORIES.map((entry) => (
              <option key={entry.value} value={entry.value}>{entry[locale === 'ar' ? 'ar' : 'en']}</option>
            ))}
          </Select>
        </Field>
        <Field label={locale === 'ar' ? 'ملاحظات' : 'Notes'}>
          <Input value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={1000} />
        </Field>
      </div>
    </Modal>
  )
}

/**
 * Original document and proposed values side by side. Nothing is saved until the reviewer confirms,
 * unclear or missing values are marked, and confirming creates no invoice, payment or posting.
 */
function ReviewModal({ row, onClose }: { row: AttachmentRow | null; onClose: () => void }) {
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const navigate = useNavigate()
  const { url, failed } = useFileUrl(row?.id ?? null)
  const [extraction, setExtraction] = useState<Extraction | null>(null)
  const [values, setValues] = useState<Record<string, string>>({})
  const [error, setError] = useState('')

  // The attachment the dialog currently shows. A response that arrives after the dialog was closed or
  // moved to another document belongs to the old one and is dropped, so values are never mixed up.
  const activeId = useRef<string | null>(null)
  activeId.current = row?.id ?? null

  const start = useMutation({
    mutationFn: async (id: string) => ({ id, extraction: (await api.post<{ data: Extraction }>(`/attachments/${id}/extraction`)).data.data }),
    onSuccess: ({ id, extraction: data }) => {
      if (activeId.current !== id) return
      setExtraction(data)
      setValues(initialValues(data))
    },
    onError: (e, id) => {
      if (activeId.current === id) setError(errorMessage(e))
    },
  })
  useEffect(() => {
    setExtraction(null)
    setValues({})
    setError('')
    start.reset()
    confirm.reset()
    reject.reset()
    if (row) start.mutate(row.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row?.id])

  const confirm = useMutation({
    mutationFn: async (target: { attachmentId: string; extractionId: string; values: Record<string, string> }) => ({
      attachmentId: target.attachmentId,
      extraction: (await api.post<{ data: Extraction }>(`/attachment-extractions/${target.extractionId}/confirm`, { values: toConfirmPayload(target.values) })).data.data,
    }),
    onSuccess: ({ attachmentId, extraction: data }) => {
      if (activeId.current !== attachmentId) return
      setExtraction(data)
      setError('')
    },
    onError: (e, target) => {
      if (activeId.current === target.attachmentId) setError(errorMessage(e))
    },
  })
  const reject = useMutation({
    mutationFn: async (target: { attachmentId: string; extractionId: string }) => {
      await api.post(`/attachment-extractions/${target.extractionId}/reject`)
      return target.attachmentId
    },
    onSuccess: (attachmentId) => {
      if (activeId.current === attachmentId) onClose()
    },
    onError: (e, target) => {
      if (activeId.current === target.attachmentId) setError(errorMessage(e))
    },
  })

  const fields = extraction ? (FIELDS[extraction.category] ?? []) : []
  const flagged = extraction?.proposed?.fields ?? {}
  const confirmed = extraction?.status === 'confirmed'

  return (
    <Modal
      open={row !== null}
      title={L('مراجعة البيانات المقروءة', 'Review the extracted details')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{L('إغلاق', 'Close')}</Button>
          {extraction?.status === 'succeeded' ? (
            <>
              <Button variant="danger" disabled={reject.isPending} onClick={() => row && extraction && reject.mutate({ attachmentId: row.id, extractionId: extraction.id })}>{L('رفض الاقتراح', 'Reject')}</Button>
              <Button disabled={confirm.isPending} onClick={() => row && extraction && confirm.mutate({ attachmentId: row.id, extractionId: extraction.id, values })}>{L('اعتماد القيم', 'Confirm values')}</Button>
            </>
          ) : null}
          {confirmed && extraction?.category === 'supplier_invoice' ? (
            <Button
              icon="arrowRight"
              onClick={() => navigate('/invoices', { state: { invoicePrefill: invoicePrefill(extraction.confirmed ?? {}) } })}
            >
              {L('فتح فاتورة مورد بهذه القيم', 'Open a supplier invoice with these values')}
            </Button>
          ) : null}
        </>
      }
    >
      {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
      <div className="grid gap-4 lg:grid-cols-2">
        <DocumentView url={url} failed={failed} mime={row?.mime ?? ''} />
        <div className="flex flex-col gap-3">
          {start.isPending ? <Loading /> : null}
          {extraction?.status === 'failed' ? (
            <InlineNotice tone="warning">
              {L('تعذّرت القراءة التلقائية لهذا المستند. يمكنك إدخال البيانات يدوياً ومتابعة عملك.', 'The document could not be read automatically. You can enter the details by hand and carry on.')}
            </InlineNotice>
          ) : null}
          {extraction?.status === 'succeeded' ? (
            <InlineNotice tone="warning">
              {L('هذه قيم مقترحة فقط. راجعها وصحّحها قبل الاعتماد؛ لا يُنشأ أي سجل مالي من هذه الشاشة.', 'These are proposed values only. Check and correct them before confirming; no financial record is created from this screen.')}
            </InlineNotice>
          ) : null}
          {confirmed ? <InlineNotice tone="positive">{L('تم اعتماد القيم وحفظها مع المستند.', 'The values were confirmed and stored with the document.')}</InlineNotice> : null}
          {extraction && extraction.status !== 'failed'
            ? fields.map((field) => {
                const flag = flagged[field.key]?.flag ?? null
                return (
                  <Field key={field.key} label={field[locale === 'ar' ? 'ar' : 'en']}>
                    <div className="flex items-center gap-2">
                      <Input
                        value={values[field.key] ?? ''}
                        disabled={confirmed}
                        dir="auto"
                        maxLength={255}
                        onChange={(event) => setValues({ ...values, [field.key]: event.target.value })}
                      />
                      {flag && !confirmed ? (
                        <Badge tone="warning">{flag === 'missing' ? L('غير موجود', 'Missing') : L('غير واضح', 'Unclear')}</Badge>
                      ) : null}
                    </div>
                  </Field>
                )
              })
            : null}
        </div>
      </div>
    </Modal>
  )
}

/** A "Documents" action for list rows: opens the record's documents in a dialog. */
export function DocumentsButton({ type, recordId }: { type: AttachableType; recordId: string }) {
  const { locale } = useI18n()
  const { can } = useAuth()
  const [open, setOpen] = useState(false)
  if (!can('attachments.view')) return null
  return (
    <>
      <Button variant="ghost" icon="folder" aria-label={locale === 'ar' ? 'المستندات' : 'Documents'} onClick={() => setOpen(true)} />
      <Modal
        open={open}
        title={locale === 'ar' ? 'المستندات' : 'Documents'}
        onClose={() => setOpen(false)}
        footer={<Button variant="secondary" onClick={() => setOpen(false)}>{locale === 'ar' ? 'إغلاق' : 'Close'}</Button>}
      >
        {open ? <AttachmentsCard type={type} recordId={recordId} bare /> : null}
      </Modal>
    </>
  )
}
