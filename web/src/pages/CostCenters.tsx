import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate } from '../lib/format'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'

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
  code: string
  name_ar: string
  name_en: string
  parent_id: string
  effective_from: string
  effective_to: string
}

const emptyForm: Form = { code: '', name_ar: '', name_en: '', parent_id: '', effective_from: '', effective_to: '' }

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
    setEditing(null)
    setForm(emptyForm)
    setOpen(true)
  }

  const startEdit = (center: CostCenter) => {
    setError('')
    setEditing(center)
    setForm({
      code: center.code,
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
      if (!form.code.trim() || !form.name_ar.trim() || !form.name_en.trim()) {
        throw new Error(ar ? 'الرمز والاسمان مطلوبان' : 'Code and both names are required')
      }
      const payload = {
        code: form.code.trim(),
        name_ar: form.name_ar.trim(),
        name_en: form.name_en.trim(),
        parent_id: form.parent_id || null,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
      }
      if (editing) await api.patch(`/cost-centers/${editing.id}`, payload)
      else await api.post('/cost-centers', payload)
    },
    onSuccess: async () => {
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
                      <p className="font-medium">{ar ? center.name_ar : center.name_en}</p>
                      <p className="text-xs text-text-subtle">{ar ? center.name_en : center.name_ar}</p>
                    </Td>
                    <Td>{parent ? `${parent.code} — ${ar ? parent.name_ar : parent.name_en}` : '—'}</Td>
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
          <Field label={t('code')} required>
            <Input value={form.code} maxLength={40} onChange={(event) => setForm({ ...form, code: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'المركز الأب' : 'Parent'}>
            <Select value={form.parent_id} onChange={(event) => setForm({ ...form, parent_id: event.target.value })}>
              <option value="">{ar ? 'بدون' : 'None'}</option>
              {parentOptions.map((center) => (
                <option key={center.id} value={center.id}>{center.code} — {ar ? center.name_ar : center.name_en}</option>
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
            <Input type="date" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'ساري إلى' : 'Effective to'}>
            <Input type="date" value={form.effective_to} onChange={(event) => setForm({ ...form, effective_to: event.target.value })} dir="ltr" />
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
