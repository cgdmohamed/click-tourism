import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { Lead, Paginated } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Pagination, Select, Table, Td, Th, statusTone } from '../components/ui'
import { statusLabel } from '../lib/labels'

const stages = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost']
const sources = ['whatsapp', 'facebook', 'instagram', 'website', 'referral', 'walk_in', 'other']

export default function Leads() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
  const [stageFilter, setStageFilter] = useState('')
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ name: '', phone: '', email: '', source: 'whatsapp', notes: '' })

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const query = useQuery({
    queryKey: ['leads', stageFilter, search, page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Lead>>('/leads', {
        params: { stage: stageFilter || undefined, search: search || undefined, page, per_page: 20 },
      })
      return data
    },
  })

  const leads = useMemo(() => query.data?.data ?? [], [query.data])

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const { data } = await api.post<{ data: Lead }>('/leads', { ...form, branch_id: activeBranch.id })
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm({ name: '', phone: '', email: '', source: 'whatsapp', notes: '' })
      await queryClient.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const convert = useMutation({
    mutationFn: async (lead: Lead) => {
      const { data } = await api.post(`/leads/${lead.id}/convert`, {})
      return data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['leads'] })
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const staleCount = useMemo(() => {
    const cutoff = Date.now() - 24 * 60 * 60 * 1000
    return leads.filter((lead) => {
      if (lead.status !== 'open') return false
      const reference = lead.last_activity_at ?? lead.created_at
      return new Date(reference).getTime() < cutoff
    }).length
  }, [leads])

  const stageLabel = (value: string) => {
    const labels: Record<string, [string, string]> = {
      new: ['جديد', 'New'],
      contacted: ['تم التواصل', 'Contacted'],
      qualified: ['مؤهل', 'Qualified'],
      proposal: ['عرض سعر', 'Proposal'],
      won: ['تم البيع', 'Won'],
      lost: ['خسارة', 'Lost'],
    }
    const found = labels[value]
    return found ? found[locale === 'ar' ? 0 : 1] : value
  }

  return (
    <div>
      <PageHeader
        title={t('crm')}
        subtitle={locale === 'ar' ? 'توزيع العملاء المحتملين وإعادة التوزيع بعد 24 ساعة' : 'Lead routing with 24-hour inactivity reassignment'}
        icon="target"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{locale === 'ar' ? 'عميل محتمل جديد' : 'New lead'}</Button>}
      />

      {staleCount > 0 ? (
        <div className="mb-4 rounded-16 border border-warning-border bg-warning-background px-4 py-3 text-medium text-warning-text">
          {locale === 'ar'
            ? `${staleCount} عميل محتمل بدون نشاط خلال 24 ساعة — سيُعاد توزيعه تلقائيًا.`
            : `${staleCount} lead(s) without activity for 24h — automatic reassignment will apply.`}
        </div>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder={t('search')}
            className="max-w-xs"
          />
          <Select
            value={stageFilter}
            onChange={(event) => {
              setStageFilter(event.target.value)
              setPage(1)
            }}
            className="max-w-[180px]"
            aria-label={t('stage')}
          >
            <option value="">{locale === 'ar' ? 'كل المراحل' : 'All stages'}</option>
            {stages.map((stage) => (
              <option key={stage} value={stage}>
                {stageLabel(stage)}
              </option>
            ))}
          </Select>
        </div>

        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && leads.length === 0 ? <EmptyState /> : null}
        {leads.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('name')}</Th>
                <Th>{t('phone')}</Th>
                <Th>{t('source')}</Th>
                <Th>{t('stage')}</Th>
                <Th>{t('lastActivity')}</Th>
                <Th>{t('status')}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {leads.map((lead) => (
                <tr key={lead.id}>
                  <Td className="font-medium">{lead.name}</Td>
                  <Td dir="ltr">{lead.phone ?? '—'}</Td>
                  <Td>{lead.source}</Td>
                  <Td>{stageLabel(lead.stage)}</Td>
                  <Td>{formatDateTime(lead.last_activity_at ?? lead.created_at, locale)}</Td>
                  <Td>
                    <Badge tone={statusTone(lead.status)}>{statusLabel(lead.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end">
                    {lead.status === 'open' ? (
                      <Button variant="ghost" onClick={() => convert.mutate(lead)}>
                        {locale === 'ar' ? 'تحويل لعميل' : 'Convert'}
                      </Button>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
        <Pagination page={query.data?.meta.current_page ?? 1} lastPage={query.data?.meta.last_page ?? 1} onChange={setPage} />
        {error && !open ? <p className="px-5 py-3 text-sm text-danger-text">{error}</p> : null}
      </Card>

      <Modal
        open={open}
        title={locale === 'ar' ? 'عميل محتمل جديد' : 'New lead'}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={create.isPending || !form.name} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('name')} required>
            <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label={t('phone')}>
            <Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('email')}>
            <Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('source')}>
            <Select value={form.source} onChange={(event) => setForm({ ...form, source: event.target.value })}>
              {sources.map((source) => (
                <option key={source} value={source}>
                  {source}
                </option>
              ))}
            </Select>
          </Field>
          <div className="sm:col-span-2">
            <Field label={locale === 'ar' ? 'ملاحظات' : 'Notes'}>
              <Input value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
            </Field>
          </div>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
