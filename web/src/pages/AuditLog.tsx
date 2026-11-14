import { DateField } from '../components/DateField'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { auditBranchLabel, auditEntityLabel, auditQuery, auditSnapshot } from '../lib/audit'
import { downloadResponse } from '../lib/download'
import { formatDateTime } from '../lib/format'
import type { AuditEvent, AuditEventList, AuditFilters } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  PageHeader,
  Pagination,
  Select,
  Spinner,
  Table,
  Td,
  Th,
} from '../components/ui'
import { localizedName } from '../lib/names'

const emptyFilters: AuditFilters = {
  actor: '',
  action: '',
  entity_type: '',
  entity_id: '',
  branch: '',
  from: '',
  to: '',
}

const entityTypes = [
  'Lead',
  'Invoice',
  'Expense',
  'JournalEntry',
  'Booking',
  'Customer',
  'Supplier',
  'Payment',
  'FixedAsset',
  'User',
  'Branch',
  'Setting',
  'Role',
]

export default function AuditLog() {
  const { t, locale } = useI18n()
  const { branches, can } = useAuth()
  const [draft, setDraft] = useState<AuditFilters>(emptyFilters)
  const [filters, setFilters] = useState<AuditFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<AuditEvent | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState('')

  const query = useQuery({
    queryKey: ['audit-events', filters, page],
    queryFn: async () => {
      const { data } = await api.get<AuditEventList>('/audit-events', {
        params: { ...auditQuery(filters), page, per_page: 25 },
      })
      return data
    },
  })

  const update = (key: keyof AuditFilters, value: string) => {
    setDraft((current) => ({ ...current, [key]: value }))
  }

  const apply = (event: FormEvent) => {
    event.preventDefault()
    setFilters({ ...draft })
    setPage(1)
  }

  const reset = () => {
    setDraft(emptyFilters)
    setFilters(emptyFilters)
    setPage(1)
  }

  const exportTrail = async () => {
    setExporting(true)
    setExportError('')
    try {
      const response = await api.get<Blob>('/audit-events/export', {
        params: auditQuery(filters),
        responseType: 'blob',
      })
      downloadResponse(response, 'audit-events.csv')
    } catch {
      setExportError(t('exportFailed'))
    } finally {
      setExporting(false)
    }
  }

  const events = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <div>
      <PageHeader
        title={t('auditLog')}
        subtitle={t('auditLogSubtitle')}
        icon="shield"
        actions={
          can('audit.export') ? (
            <Button variant="secondary" icon="download" onClick={() => void exportTrail()} disabled={exporting}>
              {exporting ? <Spinner className="h-4 w-4" /> : t('exportAudit')}
            </Button>
          ) : null
        }
      />

      <Card className="mb-4">
        <form className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4" onSubmit={apply}>
          <Field label={t('auditActor')}>
            <Input
              id="audit-actor"
              value={draft.actor}
              onChange={(event) => update('actor', event.target.value)}
              placeholder={t('auditActorPlaceholder')}
            />
          </Field>
          <Field label={t('auditAction')}>
            <Input
              id="audit-action"
              value={draft.action}
              onChange={(event) => update('action', event.target.value)}
              placeholder="lead.created"
            />
          </Field>
          <Field label={t('auditEntityType')}>
            <Select
              id="audit-entity-type"
              value={draft.entity_type}
              onChange={(event) => update('entity_type', event.target.value)}
            >
              <option value="">{t('all')}</option>
              {entityTypes.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('auditEntityId')}>
            <Input
              id="audit-entity-id"
              type="number"
              min={1}
              value={draft.entity_id}
              onChange={(event) => update('entity_id', event.target.value)}
              placeholder="42"
            />
          </Field>
          <Field label={t('auditBranch')}>
            <Select id="audit-branch" value={draft.branch} onChange={(event) => update('branch', event.target.value)}>
              <option value="">{t('allBranches')}</option>
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {localizedName(branch, locale)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('auditFrom')}>
            <DateField id="audit-from" value={draft.from} onChange={(event) => update('from', event.target.value)} />
          </Field>
          <Field label={t('auditTo')}>
            <DateField id="audit-to" value={draft.to} onChange={(event) => update('to', event.target.value)} />
          </Field>
          <div className="flex items-end gap-2">
            <Button type="submit" icon="filter">
              {t('applyFilters')}
            </Button>
            <Button type="button" variant="secondary" onClick={reset}>
              {t('resetFilters')}
            </Button>
          </div>
        </form>
      </Card>

      {exportError ? <p className="mb-3 text-small text-danger-text">{exportError}</p> : null}

      <Card>
        {query.isLoading ? (
          <Loading />
        ) : query.isError ? (
          <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
        ) : events.length === 0 ? (
          <EmptyState title={t('noAuditEvents')} hint={t('noAuditEventsHint')} icon="shield" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>{t('auditTime')}</Th>
                <Th>{t('auditAction')}</Th>
                <Th>{t('auditActor')}</Th>
                <Th>{t('auditEntity')}</Th>
                <Th>{t('auditBranch')}</Th>
                <Th>{t('auditIp')}</Th>
                <Th>{t('details')}</Th>
              </tr>
            </thead>
            <tbody>
              {events.map((event) => (
                <tr key={event.id}>
                  <Td className="whitespace-nowrap text-text-subtle">{formatDateTime(event.created_at, locale)}</Td>
                  <Td>
                    <Badge tone="neutral">{event.action}</Badge>
                  </Td>
                  <Td>
                    {event.actor ? (
                      <div className="min-w-0">
                        <p className="truncate text-text-default">{event.actor.name}</p>
                        <p className="truncate text-small text-text-subtlest">{event.actor.email}</p>
                      </div>
                    ) : (
                      <span className="text-text-subtle">{t('systemActor')}</span>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap">{auditEntityLabel(event)}</Td>
                  <Td>{auditBranchLabel(event.branch, locale)}</Td>
                  <Td className="whitespace-nowrap text-text-subtle">{event.ip ?? '—'}</Td>
                  <Td>
                    <Button variant="ghost" icon="eye" onClick={() => setDetail(event)}>
                      {t('viewDetails')}
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        {meta ? <Pagination page={meta.current_page} lastPage={meta.last_page} onChange={setPage} /> : null}
      </Card>

      <Modal open={detail !== null} title={t('auditDetails')} onClose={() => setDetail(null)}>
        {detail ? (
          <div className="flex flex-col gap-4">
            <dl className="grid gap-3 sm:grid-cols-2">
              <Detail label={t('auditAction')} value={detail.action} />
              <Detail label={t('auditTime')} value={formatDateTime(detail.created_at, locale)} />
              <Detail label={t('auditActor')} value={detail.actor ? `${detail.actor.name} (${detail.actor.email})` : t('systemActor')} />
              <Detail label={t('auditEntity')} value={auditEntityLabel(detail)} />
              <Detail label={t('auditBranch')} value={auditBranchLabel(detail.branch, locale)} />
              <Detail label={t('auditIp')} value={detail.ip ?? '—'} />
              <Detail label={t('auditRequestId')} value={detail.request_id ?? '—'} />
            </dl>

            <div>
              <p className="label mb-1 text-text-subtle">{t('auditBefore')}</p>
              <pre dir="ltr" className="max-h-48 overflow-auto rounded-12 bg-surface-subtle p-3 text-small text-text-default">
                {auditSnapshot(detail.before)}
              </pre>
            </div>
            <div>
              <p className="label mb-1 text-text-subtle">{t('auditAfter')}</p>
              <pre dir="ltr" className="max-h-48 overflow-auto rounded-12 bg-surface-subtle p-3 text-small text-text-default">
                {auditSnapshot(detail.after)}
              </pre>
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-small text-text-subtlest">{label}</dt>
      <dd className="text-medium break-words text-text-default">{value}</dd>
    </div>
  )
}
