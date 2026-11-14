import { CardField, MobileCards, TableOnly } from '../components/MobileCards'
import { DateField } from '../components/DateField'
import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate } from '../lib/format'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, InlineNotice, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'
import { localizedName } from '../lib/names'
import { CodeField } from '../components/CodeField'
import { createdWithCode } from '../lib/codes'

type CostCenter = {
  id: string
  code: string
  name_ar: string
  name_en: string
  parent_id: string | null
  status: string
  effective_from: string | null
  effective_to: string | null
  is_active: boolean
}

type Form = {
  name_ar: string
  name_en: string
  parent_id: string
  effective_from: string
  effective_to: string
}

const emptyForm: Form = { name_ar: '', name_en: '', parent_id: '', effective_from: '', effective_to: '' }

/**
 * Analytical cost-center tree. Journal lines can be allocated to these
 * centers; archiving is blocked while allocations still reference them.
 */
export default function CostCenters() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CostCenter | null>(null)
  const [archiveTarget, setArchiveTarget] = useState<CostCenter | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [form, setForm] = useState<Form>(emptyForm)
  const ar = locale === 'ar'
  const canManage = can('cost_centers.manage')

  const centersQuery = useQuery({
    queryKey: ['cost-centers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CostCenter[] }>('/cost-centers', { params: { include_archived: 1 } })
      return data.data
    },
  })

  const centers = useMemo(() => centersQuery.data ?? [], [centersQuery.data])
  const byId = useMemo(() => new Map(centers.map((center) => [center.id, center])), [centers])

  const parentOptions = useMemo(
    () => centers.filter((center) => center.is_active && center.id !== editing?.id),
    [centers, editing],
  )

  const closeEditor = () => {
    setOpen(false)
    setEditing(null)
    setForm(emptyForm)
  }

  const startCreate = () => {
    setError('')
    setNotice('')
    setEditing(null)
    setForm(emptyForm)
    setOpen(true)
  }

  const startEdit = (center: CostCenter) => {
    setError('')
    setEditing(center)
    setForm({
      name_ar: center.name_ar,
      name_en: center.name_en,
      parent_id: center.parent_id ?? '',
      effective_from: center.effective_from ?? '',
      effective_to: center.effective_to ?? '',
    })
    setOpen(true)
  }

  const save = useMutation({
    mutationFn: async () => {
      if (!form.name_ar.trim() || !form.name_en.trim()) {
        throw new Error(ar ? 'الاسمان مطلوبان' : 'Both names are required')
      }
      // No code is sent: the server generates it on create and it never changes afterwards.
      const payload = {
        name_ar: form.name_ar.trim(),
        name_en: form.name_en.trim(),
        parent_id: form.parent_id || null,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
      }
      if (editing) {
        await api.patch(`/cost-centers/${editing.id}`, payload)
        return null
      }
      const { data } = await api.post<{ data: CostCenter }>('/cost-centers', payload)
      return data.data
    },
    onSuccess: async (created) => {
      if (created) setNotice(createdWithCode(ar, 'مركز التكلفة', 'Cost center', created.code))
      closeEditor()
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['cost-centers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const archive = useMutation({
    mutationFn: async (center: CostCenter) => {
      await api.post(`/cost-centers/${center.id}/archive`)
    },
    onSuccess: async () => {
      setArchiveTarget(null)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['cost-centers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <div>
      <PageHeader
        title={ar ? 'مراكز التكلفة' : 'Cost centers'}
        subtitle={ar ? 'شجرة التحليل الإداري لتوزيع بنود اليومية' : 'Analytical tree used to allocate journal lines'}
        icon="hash"
        actions={
          canManage ? (
            <Button icon="plus" onClick={startCreate}>{ar ? 'مركز جديد' : 'New cost center'}</Button>
          ) : null
        }
      />

      {notice ? (
        <div className="mb-4" role="status">
          <InlineNotice tone="positive">{notice}</InlineNotice>
        </div>
      ) : null}
      {error && !open && !archiveTarget ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}
      {!canManage ? (
        <p className="mb-4 rounded-12 border border-warning-border bg-warning-background px-3 py-2 text-medium text-warning-text">{t('permissionDenied')}</p>
      ) : null}

      <Card>
        <CardHeader title={ar ? 'الشجرة' : 'Tree'} subtitle={ar ? `${centers.length} مركز` : `${centers.length} centers`} />
        {centersQuery.isLoading ? <Loading /> : null}
        {centersQuery.isError ? (
          <ErrorState message={errorMessage(centersQuery.error)} onRetry={() => void centersQuery.refetch()} />
        ) : null}
        {!centersQuery.isLoading && !centersQuery.isError && centers.length === 0 ? <EmptyState /> : null}
        {centers.length > 0 ? (
          <>
          <MobileCards items={centers} getKey={(center) => center.id} label={ar ? 'مراكز التكلفة' : 'Cost centers'}>
            {(center) => {
              const parent = center.parent_id ? byId.get(center.parent_id) : null
              return (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <span className="text-medium font-semibold">
                      <span className="tabular">{center.code}</span> · {localizedName(center, ar ? 'ar' : 'en')}
                    </span>
                    <Badge tone={center.status === 'active' ? 'positive' : 'neutral'}>{center.status === 'active' ? (ar ? 'نشط' : 'Active') : (ar ? 'مؤرشف' : 'Archived')}</Badge>
                  </div>
                  <dl className="grid grid-cols-2 gap-3">
                    <CardField label={ar ? 'المركز الأب' : 'Parent'}>{parent ? `${parent.code} — ${localizedName(parent, ar ? 'ar' : 'en')}` : '—'}</CardField>
                    <CardField label={ar ? 'السريان' : 'Effective'}>
                      {center.effective_from || center.effective_to
                        ? `${center.effective_from ? formatDate(center.effective_from, locale) : '…'} → ${center.effective_to ? formatDate(center.effective_to, locale) : '…'}`
                        : '—'}
                    </CardField>
                  </dl>
                  {canManage ? (
                    <div className="flex gap-2">
                      <Button variant="secondary" onClick={() => startEdit(center)}>{t('edit')}</Button>
                      {center.is_active ? (
                        <Button variant="secondary" onClick={() => { setError(''); setArchiveTarget(center) }}>{ar ? 'أرشفة' : 'Archive'}</Button>
                      ) : null}
                    </div>
                  ) : null}
                </>
              )
            }}
          </MobileCards>
          <TableOnly>
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('name')}</Th>
                <Th>{ar ? 'المركز الأب' : 'Parent'}</Th>
                <Th>{ar ? 'السريان' : 'Effective'}</Th>
                <Th>{t('status')}</Th>
                {canManage ? <Th /> : null}
              </tr>
            </thead>
            <tbody>
              {centers.map((center) => {
                const parent = center.parent_id ? byId.get(center.parent_id) : null
                return (
                  <tr key={center.id}>
                    <Td className="tabular font-medium">{center.code}</Td>
                    <Td>
                      <p className="font-medium">{localizedName(center, ar ? 'ar' : 'en')}</p>
                    </Td>
                    <Td>{parent ? `${parent.code} — ${localizedName(parent, ar ? 'ar' : 'en')}` : '—'}</Td>
                    <Td className="text-small text-text-subtle">
                      {center.effective_from || center.effective_to
                        ? `${center.effective_from ? formatDate(center.effective_from, locale) : '…'} → ${center.effective_to ? formatDate(center.effective_to, locale) : '…'}`
                        : '—'}
                    </Td>
                    <Td><Badge tone={center.status === 'active' ? 'positive' : 'neutral'}>{center.status === 'active' ? (ar ? 'نشط' : 'Active') : (ar ? 'مؤرشف' : 'Archived')}</Badge></Td>
                    {canManage ? (
                      <Td className="text-end">
                        <div className="flex justify-end gap-1">
                          <Button variant="ghost" onClick={() => startEdit(center)}>{t('edit')}</Button>
                          {center.is_active ? (
                            <Button variant="ghost" onClick={() => { setError(''); setArchiveTarget(center) }}>{ar ? 'أرشفة' : 'Archive'}</Button>
                          ) : null}
                        </div>
                      </Td>
                    ) : null}
                  </tr>
                )
              })}
            </tbody>
          </Table>
          </TableOnly>
          </>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={editing ? (ar ? `تعديل ${editing.code}` : `Edit ${editing.code}`) : (ar ? 'مركز تكلفة جديد' : 'New cost center')}
        onClose={closeEditor}
        footer={
          <>
            <Button variant="secondary" onClick={closeEditor}>{t('cancel')}</Button>
            <Button disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? t('saving') : t('save')}</Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <CodeField code={editing?.code} label={t('code')} ar={ar} />
          <Field label={ar ? 'المركز الأب' : 'Parent'}>
            <Select value={form.parent_id} onChange={(event) => setForm({ ...form, parent_id: event.target.value })}>
              <option value="">{ar ? 'بدون' : 'None'}</option>
              {parentOptions.map((center) => (
                <option key={center.id} value={center.id}>{center.code} — {localizedName(center, ar ? 'ar' : 'en')}</option>
              ))}
            </Select>
          </Field>
          <Field label={t('nameAr')} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} />
          </Field>
          <Field label={t('nameEn')} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'ساري من' : 'Effective from'}>
            <DateField value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'ساري إلى' : 'Effective to'}>
            <DateField value={form.effective_to} onChange={(event) => setForm({ ...form, effective_to: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <Modal
        open={archiveTarget !== null}
        title={ar ? 'أرشفة مركز التكلفة' : 'Archive cost center'}
        onClose={() => setArchiveTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setArchiveTarget(null)}>{t('cancel')}</Button>
            <Button disabled={archive.isPending} onClick={() => archiveTarget && archive.mutate(archiveTarget)}>
              {archive.isPending ? t('saving') : (ar ? 'أرشفة' : 'Archive')}
            </Button>
          </>
        }
      >
        <p className="text-medium text-text-subtle">
          {ar
            ? `ستتوقف ${archiveTarget?.code ?? ''} عن الظهور في القوائم الجديدة. لا يمكن الأرشفة ما دامت هناك توزيعات مرتبطة.`
            : `${archiveTarget?.code ?? ''} will stop appearing in new pickers. Archiving is refused while allocations still reference it.`}
        </p>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
