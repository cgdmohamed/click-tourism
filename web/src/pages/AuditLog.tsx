import { useState } from 'react'
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
  ErrorState,
  Loading,
  Modal,
  PageHeader,
  Pagination,
  Spinner,
  Table,
  Td,
  Th,
} from '../components/ui'
import { localizedName } from '../lib/names'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'
import { FilterControl, useFilters } from '../components/FilterDrawer'
import { ListEmpty } from '../components/ListEmpty'

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
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<AuditEvent | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState('')

  const filterState = useFilters(
    [
      { key: 'actor', label: t('auditActor'), type: 'text', placeholder: t('auditActorPlaceholder') },
      { key: 'action', label: t('auditAction'), type: 'text', placeholder: 'lead.created' },
      { key: 'entity_type', label: t('auditEntityType'), type: 'select', options: entityTypes.map((type) => ({ value: type, label: type })), allLabel: t('all') },
      { key: 'entity_id', label: t('auditEntityId'), type: 'text', placeholder: '42' },
      {
        key: 'branch',
        label: t('auditBranch'),
        type: 'select',
        options: branches.map((branch) => ({ value: branch.id, label: localizedName(branch, locale) })),
        allLabel: t('allBranches'),
      },
      { key: 'from', label: t('auditFrom'), type: 'date' },
      { key: 'to', label: t('auditTo'), type: 'date' },
    ],
    { onApply: () => setPage(1) },
  )
  const filters = filterState.applied as AuditFilters

  const query = useQuery({
    queryKey: ['audit-events', filters, page],
    queryFn: async () => {
      const { data } = await api.get<AuditEventList>('/audit-events', {
        params: { ...auditQuery(filters), page, per_page: 25 },
      })
      return data
    },
  })

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

      {exportError ? <p className="mb-3 text-small text-danger-text">{exportError}</p> : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <FilterControl state={filterState} />
        </div>
        {query.isLoading ? (
          <Loading />
        ) : query.isError ? (
          <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
        ) : events.length === 0 ? (
          <ListEmpty filters={filterState} title={t('noAuditEvents')} hint={t('noAuditEventsHint')} />
        ) : (
          <>
          <MobileCards items={events} getKey={(event) => String(event.id)} label={t('auditLog')}>
            {(event) => (
              <>
                <div className="flex items-start justify-between gap-3">
                  <Badge tone="neutral">{event.action}</Badge>
                  <span className="text-small text-text-subtle">{formatDateTime(event.created_at, locale)}</span>
                </div>
                <dl className="grid grid-cols-2 gap-3">
                  <CardField label={t('auditActor')}>{event.actor ? event.actor.name : t('systemActor')}</CardField>
                  <CardField label={t('auditEntity')}>{auditEntityLabel(event)}</CardField>
                  <CardField label={t('auditBranch')}>{auditBranchLabel(event.branch, locale)}</CardField>
                  <CardField label={t('auditIp')}>
                    <span dir="ltr">{event.ip ?? '—'}</span>
                  </CardField>
                </dl>
                <div className="flex gap-2">
                  <Button variant="secondary" icon="eye" onClick={() => setDetail(event)}>
                    {t('viewDetails')}
                  </Button>
                </div>
              </>
            )}
          </MobileCards>
          <TableOnly>
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
          </TableOnly>
          </>
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
