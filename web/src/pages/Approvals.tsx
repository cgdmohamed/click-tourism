import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate } from '../lib/format'
import type { Paginated } from '../lib/types'
import { ReasonModal } from '../components/ReasonModal'
import { Badge, Button, Card, EmptyState, ErrorState, Loading, Money, PageHeader } from '../components/ui'

import { REFUND_SOURCE, recordHref, rowAmount, rowDate, sourceParams, visibleSources } from '../lib/approvals'
import type { Row, Source } from '../lib/approvals'

type Pending = { source: Source; row: Row }

export default function Approvals() {
  const { can, activeBranch } = useAuth()
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const sources = visibleSources(can)
  const showRefunds = can(REFUND_SOURCE.permission)
  const [rejecting, setRejecting] = useState<Pending | null>(null)
  const [error, setError] = useState('')

  const queries = useQueries({
    queries: sources.map((source) => ({
      queryKey: ['approvals', source.key, activeBranch?.id ?? null],
      queryFn: async () =>
        (await api.get<Paginated<Row>>(source.list, { params: { ...sourceParams(source), per_page: 25 } })).data,
    })),
  })
  const refundQuery = useQuery({
    queryKey: ['approvals', 'refunds', activeBranch?.id ?? null],
    enabled: showRefunds,
    queryFn: async () =>
      (await api.get<Paginated<Row>>('/refunds', { params: { status: REFUND_SOURCE.status, per_page: 25 } })).data,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['approvals'] })
    await queryClient.invalidateQueries({ queryKey: ['approvals-count'] })
  }

  const approve = useMutation({
    mutationFn: async ({ source, row }: Pending) => {
      const { data } = await api.post<{ data?: { id?: string } }>(source.approvePath(row.id), undefined, { headers: { 'Idempotency-Key': idempotencyKey() } })
      return { source, id: data?.data?.id }
    },
    onSuccess: async ({ source, id }) => {
      setError('')
      await refresh()
      // A new draft invoice opens right away, where it is reviewed and sent for approval.
      if (source.key === 'ready-to-invoice' && id) navigate(`/invoices/${encodeURIComponent(id)}`)
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const reject = useMutation({
    mutationFn: async ({ pending, reason }: { pending: Pending; reason: string }) => {
      await api.post(pending.source.rejectPath!(pending.row.id), { reason })
    },
    onSuccess: async () => {
      setRejecting(null)
      setError('')
      await refresh()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const loading = queries.some((query) => query.isLoading) || (showRefunds && refundQuery.isLoading)
  const failed = queries.find((query) => query.isError) ?? (showRefunds && refundQuery.isError ? refundQuery : undefined)
  const total =
    queries.reduce((sum, query) => sum + (query.data?.meta.total ?? 0), 0) + (refundQuery.data?.meta.total ?? 0)

  if (sources.length === 0 && !showRefunds) {
    return (
      <>
        <PageHeader title={L('الاعتمادات', 'Approvals')} />
        <EmptyState title={L('لا توجد اعتمادات مطلوبة منك', 'Nothing to approve for your role')} />
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={L('الاعتمادات', 'Approvals')}
        subtitle={L('كل ما ينتظر اعتمادك في مكان واحد', 'Everything waiting for your approval in one place')}
      />
      {error ? <p role="alert" className="text-small mb-3 text-text-danger">{error}</p> : null}
      {loading ? (
        <Loading />
      ) : failed ? (
        <ErrorState message={errorMessage(failed.error)} onRetry={() => void refresh()} />
      ) : total === 0 ? (
        <EmptyState
          title={L('لا يوجد شيء ينتظر اعتمادك', 'You are all caught up')}
          hint={L('ستظهر هنا المصروفات والسندات والفواتير والعمولات عند إرسالها للاعتماد.', 'Submitted expenses, vouchers, invoices and commissions will appear here.')}
        />
      ) : (
        <div className="flex flex-col gap-4">
          {sources.map((source, index) => {
            const data = queries[index].data
            if (!data || data.data.length === 0) return null
            return (
              <Card key={source.key} className="p-4 sm:p-5">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <h2 className="heading-small">{source.label[locale]}</h2>
                  <Badge tone="warning">{data.meta.total}</Badge>
                </div>
                <ul className="divide-y divide-border-subtle">
                  {data.data.map((row) => (
                    <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                      <div className="min-w-0">
                        <Link to={recordHref(source, row.id)} className="label text-text-link hover:underline">
                          {row.number ?? row.id}
                        </Link>
                        <p className="text-small truncate text-text-subtle">
                          {[
                            locale === 'ar' ? (row.party ?? row.customer)?.name_ar : (row.party ?? row.customer)?.name_en,
                            row.payee_name,
                            row.description ?? row.memo,
                            rowDate(row) ? formatDate(rowDate(row) as string, locale) : null,
                          ]
                            .filter(Boolean)
                            .join(' · ')}
                        </p>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="label">
                          <Money amountMinor={rowAmount(row)} currency={row.currency ?? 'EGP'} />
                        </span>
                        {source.rejectPath ? (
                          <Button variant="ghost" onClick={() => { setError(''); setRejecting({ source, row }) }}>
                            {L('رفض', 'Reject')}
                          </Button>
                        ) : null}
                        <Button
                          variant="secondary"
                          disabled={approve.isPending}
                          onClick={() => approve.mutate({ source, row })}
                        >
                          {source.action[locale]}
                        </Button>
                      </div>
                    </li>
                  ))}
                </ul>
                {data.meta.total > data.data.length ? (
                  <Link to={source.page} className="text-small mt-3 inline-block text-text-link hover:underline">
                    {L(`عرض الكل (${data.meta.total})`, `View all (${data.meta.total})`)}
                  </Link>
                ) : null}
              </Card>
            )
          })}
          {refundQuery.data && refundQuery.data.data.length > 0 ? (
            <Card className="p-4 sm:p-5">
              <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="heading-small">{L('استردادات', 'Refunds')}</h2>
                <Badge tone="warning">{refundQuery.data.meta.total}</Badge>
              </div>
              <ul className="divide-y divide-border-subtle">
                {refundQuery.data.data.map((row) => (
                  <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div className="min-w-0">
                      <p className="label">{row.number}</p>
                      <p className="text-small truncate text-text-subtle">{row.reason}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="label">
                        <Money amountMinor={rowAmount(row)} currency={row.currency ?? 'EGP'} />
                      </span>
                      <Link to="/payments" className="text-small text-text-link hover:underline">
                        {L('فتح للاعتماد', 'Open to approve')}
                      </Link>
                    </div>
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
        </div>
      )}
      <ReasonModal
        open={rejecting !== null}
        title={L('رفض', 'Reject')}
        confirmLabel={L('رفض', 'Reject')}
        pending={reject.isPending}
        error={error}
        onClose={() => setRejecting(null)}
        onConfirm={(reason) => rejecting && reject.mutate({ pending: rejecting, reason })}
      />
    </>
  )
}
