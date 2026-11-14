import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { statusLabel } from '../lib/labels'
import type { LeadDetail } from '../lib/types'
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
  Pagination,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import { DetailGrid, DetailItem, MetaItem, ProfileHeader, ProfileStat } from '../components/profile'
import { Icon } from '../components/icons'
import type { IconName } from '../components/icons'
import { AiBadge } from '../components/AiBadge'

const stageLabels: Record<string, [string, string]> = {
  new: ['جديد', 'New'],
  contacted: ['تم التواصل', 'Contacted'],
  qualified: ['مؤهل', 'Qualified'],
  proposal: ['عرض سعر', 'Proposal'],
  won: ['تم البيع', 'Won'],
  lost: ['خسارة', 'Lost'],
}

const sourceLabels: Record<string, [string, string]> = {
  whatsapp: ['واتساب', 'WhatsApp'],
  facebook: ['فيسبوك', 'Facebook'],
  instagram: ['إنستغرام', 'Instagram'],
  website: ['الموقع الإلكتروني', 'Website'],
  phone: ['هاتف', 'Phone'],
  walk_in: ['زيارة للفرع', 'Walk-in'],
  referral: ['توصية عميل', 'Referral'],
  other: ['أخرى', 'Other'],
}

const activityLabels: Record<string, [string, string]> = {
  created: ['تم إنشاء العميل المحتمل', 'Lead captured'],
  note: ['ملاحظة', 'Note'],
  stage_changed: ['تغيير المرحلة', 'Stage changed'],
  assigned: ['تعيين مسؤول', 'Owner assigned'],
  reassigned: ['إعادة توزيع', 'Reassigned'],
  escalated: ['تصعيد', 'Escalated'],
  converted: ['تحويل إلى عميل', 'Converted to customer'],
}

const historyLabels: Record<string, [string, string]> = {
  assigned: ['تعيين يدوي', 'Manual assignment'],
  reassigned: ['إعادة توزيع تلقائية', 'Automatic reassignment'],
  escalated: ['تصعيد', 'Escalated'],
}

function labelOf(labels: Record<string, [string, string]>, value: string, locale: 'ar' | 'en') {
  const found = labels[value]
  return found ? found[locale === 'ar' ? 0 : 1] : value
}

function activityIcon(type: string): IconName {
  switch (type) {
    case 'created':
      return 'plus'
    case 'note':
      return 'edit'
    case 'stage_changed':
      return 'listChecks'
    case 'assigned':
      return 'userCheck'
    case 'reassigned':
      return 'refresh'
    case 'escalated':
      return 'alertTriangle'
    case 'converted':
      return 'checkCircle'
    default:
      return 'activity'
  }
}

export default function LeadProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const query = useQuery({
    queryKey: ['lead-profile', id, page],
    queryFn: async () => {
      const { data } = await api.get<{
        data: LeadDetail
        meta: { current_page: number; last_page: number; total: number; per_page: number }
      }>(`/leads/${id}`, { params: { page, per_page: 20 } })
      return data
    },
  })

  const addNote = useMutation({
    mutationFn: async () => {
      await api.patch(`/leads/${id}`, { note })
    },
    onSuccess: async () => {
      setNote('')
      setError('')
      setPage(1)
      await queryClient.invalidateQueries({ queryKey: ['lead-profile', id] })
      await queryClient.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const convert = useMutation({
    mutationFn: async () => {
      await api.post(`/leads/${id}/convert`, {})
    },
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['lead-profile', id] })
      await queryClient.invalidateQueries({ queryKey: ['leads'] })
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />

  const lead = query.data.data
  const meta = query.data.meta

  return (
    <div>
      <ProfileHeader
        title={lead.name}
        subtitle={`${labelOf(sourceLabels, lead.source, locale)} · ${labelOf(stageLabels, lead.stage, locale)}`}
        icon="target"
        backTo="/leads"
        backLabel={t('crm')}
        badge={
          <>
            <Badge tone={statusTone(lead.status)}>{statusLabel(lead.status, locale)}</Badge>
            <AiBadge via={lead.created_via} />
          </>
        }
        actions={
          lead.status === 'open' && can('leads.convert') ? (
            <Button icon="checkCircle" disabled={convert.isPending} onClick={() => convert.mutate()}>
              {convert.isPending ? t('saving') : L('تحويل إلى عميل', 'Convert to customer')}
            </Button>
          ) : lead.status === 'converted' && lead.converted_customer_id && !lead.converted_booking_id && can('bookings.create') ? (
            <Link
              to={`/bookings?customer_id=${lead.converted_customer_id}&create=1`}
              className="inline-flex items-center rounded-8 bg-brand-1 px-3 py-2 text-small font-semibold text-white"
            >
              {L('إنشاء حجز لهذا العميل', 'Create a booking for this customer')}
            </Link>
          ) : null
        }
        meta={
          <>
            {lead.phone ? <MetaItem icon="phone" dir="ltr">{lead.phone}</MetaItem> : null}
            {lead.email ? <MetaItem icon="mail" dir="ltr">{lead.email}</MetaItem> : null}
            <MetaItem icon="user">{lead.owner?.name ?? L('غير معيّن', 'Unassigned')}</MetaItem>
            <MetaItem icon="calendar">{formatDateTime(lead.created_at, locale)}</MetaItem>
          </>
        }
      />

      {error ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
          {error}
        </p>
      ) : null}

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <ProfileStat
          label={t('status')}
          value={<Badge tone={statusTone(lead.status)}>{statusLabel(lead.status, locale)}</Badge>}
          icon="activity"
        />
        <ProfileStat
          label={L('الموافقة على التواصل', 'Contact consent')}
          value={
            <Badge tone={lead.consent ? 'positive' : 'warning'}>
              {lead.consent ? L('موافق', 'Opted in') : L('غير موافق', 'No consent')}
            </Badge>
          }
          icon={lead.consent ? 'checkCircle' : 'alertTriangle'}
          tone={lead.consent ? 'positive' : 'warning'}
        />
        <ProfileStat
          label={L('محاولات إعادة التوزيع', 'Reassignment attempts')}
          value={lead.reassignment_attempts}
          icon="refresh"
          tone={lead.reassignment_attempts > 0 ? 'warning' : undefined}
        />
        <ProfileStat
          label={t('lastActivity')}
          value={formatDateTime(lead.last_activity_at ?? lead.created_at, locale)}
          icon="clock"
        />
      </div>

      <div className="flex flex-col gap-4">
        <Card>
          <CardHeader title={L('بيانات العميل المحتمل', 'Lead details')} icon="target" />
          <DetailGrid>
            <DetailItem label={t('source')}>{labelOf(sourceLabels, lead.source, locale)}</DetailItem>
            <DetailItem label={t('stage')}>{labelOf(stageLabels, lead.stage, locale)}</DetailItem>
            <DetailItem label={L('فئة الخدمة', 'Service category')}>{lead.service_category || '—'}</DetailItem>
            <DetailItem label={t('owner')}>{lead.owner?.name ?? L('غير معيّن', 'Unassigned')}</DetailItem>
            <DetailItem label={L('الفرع', 'Branch')} dir="ltr">{lead.branch_id}</DetailItem>
            <DetailItem label={L('تاريخ الإنشاء', 'Created')}>{formatDateTime(lead.created_at, locale)}</DetailItem>
            <DetailItem label={t('lastActivity')}>{formatDateTime(lead.last_activity_at ?? lead.created_at, locale)}</DetailItem>
            <DetailItem label={L('تاريخ التصعيد', 'Escalated')}>
              {lead.escalated_at ? formatDateTime(lead.escalated_at, locale) : '—'}
            </DetailItem>
            <DetailItem label={L('العميل المحوّل', 'Converted customer')}>
              {lead.converted_customer_id ? (
                <Link to={`/customers/${lead.converted_customer_id}`} className="text-brand-1-accent hover:underline">
                  {lead.converted_customer_id}
                </Link>
              ) : (
                '—'
              )}
            </DetailItem>
            <DetailItem label={L('الحجز المحوّل', 'Converted booking')}>{lead.converted_booking_id || '—'}</DetailItem>
            <DetailItem label={L('ملاحظات', 'Notes')}>{lead.notes || '—'}</DetailItem>
          </DetailGrid>
        </Card>

        {can('leads.update') ? (
          <Card>
            <CardHeader title={L('إضافة ملاحظة', 'Add a note')} icon="edit" />
            <div className="flex flex-wrap items-end gap-3 p-5">
              <div className="min-w-64 flex-1">
                <Field label={L('ملاحظة النشاط', 'Activity note')}>
                  <Input value={note} onChange={(event) => setNote(event.target.value)} />
                </Field>
              </div>
              <Button disabled={addNote.isPending || !note.trim()} onClick={() => addNote.mutate()}>
                {addNote.isPending ? t('saving') : t('save')}
              </Button>
            </div>
          </Card>
        ) : null}

        <Card>
          <CardHeader
            title={L('سجل المسؤول', 'Owner history')}
            subtitle={L('التعيينات وإعادة التوزيع والتصعيد', 'Assignments, reassignments, and escalations')}
            icon="userCheck"
            actions={<Badge tone="neutral">{lead.owner_history.length}</Badge>}
          />
          {lead.owner_history.length === 0 ? (
            <EmptyState icon="userCheck" />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الإجراء', 'Action')}</Th>
                  <Th>{L('من', 'From')}</Th>
                  <Th>{L('إلى', 'To')}</Th>
                  <Th className="text-end">{L('المحاولة', 'Attempt')}</Th>
                  <Th>{t('date')}</Th>
                </tr>
              </thead>
              <tbody>
                {lead.owner_history.map((change, index) => (
                  <tr key={`${change.action}-${change.at}-${index}`}>
                    <Td>
                      <Badge
                        tone={change.action === 'escalated' ? 'negative' : change.action === 'reassigned' ? 'warning' : 'brand'}
                      >
                        {labelOf(historyLabels, change.action, locale)}
                      </Badge>
                    </Td>
                    <Td>{change.from?.name ?? '—'}</Td>
                    <Td>{change.to?.name ?? L('غير معيّن', 'Unassigned')}</Td>
                    <Td className="text-end tabular">{change.attempt ?? '—'}</Td>
                    <Td>{formatDateTime(change.at, locale)}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>

        <Card>
          <CardHeader
            title={L('سجل النشاط', 'Activity timeline')}
            subtitle={L('كل تفاعل مسجل على هذا العميل المحتمل', 'Every interaction recorded on this lead')}
            icon="activity"
            actions={<Badge tone="neutral">{meta.total}</Badge>}
          />
          {lead.activities.length === 0 ? (
            <EmptyState icon="activity" title={t('noActivity')} />
          ) : (
            <ol className="divide-y divide-border-subtle">
              {lead.activities.map((activity) => (
                <li key={activity.id} className="flex gap-3 px-5 py-4">
                  <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-selected text-brand-1-accent">
                    <Icon name={activityIcon(activity.type)} size={16} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-medium font-semibold text-text-default">
                        {labelOf(activityLabels, activity.type, locale)}
                      </span>
                      <span className="text-small text-text-subtlest">{formatDateTime(activity.created_at, locale)}</span>
                    </div>
                    {activity.body ? (
                      <p className="text-medium mt-1 whitespace-pre-wrap text-text-default">{activity.body}</p>
                    ) : null}
                    <p className="text-small mt-1 text-text-subtle">
                      {activity.actor ? `${L('بواسطة', 'by')} ${activity.actor.name}` : L('النظام', 'System')}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
          <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setPage} />
        </Card>
      </div>
    </div>
  )
}
