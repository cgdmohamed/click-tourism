import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import type { Paginated } from '../lib/types'
import { ReasonModal } from './ReasonModal'
import { Button } from './ui'

/**
 * Submit / approve / reject on a record's own page (same endpoints and permissions as the list rows), and a link
 * to the next record that is still waiting, so an approver can work through the queue without going back.
 */
export function RecordActions({
  apiBase,
  page,
  id,
  status,
  submitPermission,
  approvePermission,
  detailKey,
}: {
  /** e.g. `/expenses` (the API collection). */
  apiBase: string
  /** Front-end collection page, e.g. `/expenses`. */
  page: string
  id: string
  status: string
  submitPermission: string
  approvePermission: string
  /** React Query key of the detail the page shows. */
  detailKey: readonly unknown[]
}) {
  const { can } = useAuth()
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()
  const [rejecting, setRejecting] = useState(false)
  const [error, setError] = useState('')
  const canApprove = can(approvePermission)

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: detailKey })
    await queryClient.invalidateQueries({ queryKey: ['approvals'] })
    await queryClient.invalidateQueries({ queryKey: ['approvals-count'] })
  }

  const step = useMutation({
    mutationFn: async (path: 'submit' | 'approve') => {
      await api.post(`${apiBase}/${encodeURIComponent(id)}/${path}`, undefined, path === 'approve' ? { headers: { 'Idempotency-Key': idempotencyKey() } } : undefined)
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reject = useMutation({
    mutationFn: async (reason: string) => {
      await api.post(`${apiBase}/${encodeURIComponent(id)}/reject`, { reason })
    },
    onSuccess: async () => {
      setRejecting(false)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const waiting = useQuery({
    queryKey: ['approvals', 'next', apiBase],
    enabled: canApprove,
    queryFn: async () => (await api.get<Paginated<{ id: string }>>(apiBase, { params: { status: 'submitted', per_page: 5 } })).data,
  })
  const next = waiting.data?.data.find((row) => row.id !== id)

  const showSubmit = status === 'draft' && can(submitPermission)
  const showDecision = status === 'submitted' && canApprove
  if (!showSubmit && !showDecision && !(canApprove && next)) return null

  return (
    <>
      {error ? <span role="alert" className="text-small text-text-danger">{error}</span> : null}
      {showSubmit ? (
        <Button disabled={step.isPending} onClick={() => step.mutate('submit')}>{L('إرسال للاعتماد', 'Submit for approval')}</Button>
      ) : null}
      {showDecision ? (
        <>
          <Button variant="ghost" onClick={() => { setError(''); setRejecting(true) }}>{L('رفض', 'Reject')}</Button>
          <Button disabled={step.isPending} onClick={() => step.mutate('approve')}>{L('اعتماد', 'Approve')}</Button>
        </>
      ) : null}
      {canApprove && next ? (
        <Link to={`${page}/${encodeURIComponent(next.id)}`} className="text-small font-medium text-brand-1-accent hover:underline">
          {L(`التالي بانتظار الاعتماد (${waiting.data?.meta.total ?? ''})`, `Next waiting (${waiting.data?.meta.total ?? ''})`)}
        </Link>
      ) : null}
      <ReasonModal
        open={rejecting}
        title={L('رفض', 'Reject')}
        confirmLabel={L('رفض', 'Reject')}
        pending={reject.isPending}
        error={error}
        onClose={() => setRejecting(false)}
        onConfirm={(reason) => reject.mutate(reason)}
      />
    </>
  )
}
