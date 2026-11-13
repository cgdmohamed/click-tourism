import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatMoney } from '../lib/format'
import { Badge, Button, EmptyState, ErrorState, InlineNotice, Loading, Modal, Table, Td, Th } from './ui'

type Row = { booking_id: string; booking_number: string; estimated_minor: number; recognized_minor: number; accrual_minor: number; already_accrued: boolean }
type Preview = { period: { id: number; name: string; ends_on: string }; reversal_date: string; rows: Row[]; total_minor: number }

/**
 * DEC-002: costs incurred but not yet invoiced at a period end. Review first; confirming posts one
 * entry per booking that reverses by itself when the next period opens, so the supplier invoice that
 * arrives later is never counted twice. Confirming again posts only what is missing.
 */
export function CostAccrualDialog({ period, baseCurrency, onClose }: { period: { id: string | number; name: string } | null; baseCurrency: string; onClose: () => void }) {
  const { locale } = useI18n()
  const { can, activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const L = (a: string, e: string) => (locale === 'ar' ? a : e)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  // A result or error belongs to the branch it was produced for.
  useEffect(() => {
    setMessage('')
    setError('')
  }, [activeBranch?.id, period?.id])

  const query = useQuery({
    queryKey: ['cost-accrual', period?.id, activeBranch?.id],
    queryFn: async () => (await api.get<{ data: Preview }>(`/fiscal-periods/${period?.id}/cost-accrual`)).data.data,
    enabled: period !== null,
  })

  const confirm = useMutation({
    mutationFn: async () => (await api.post<{ data: { status: string; entries: number } }>(`/fiscal-periods/${period?.id}/cost-accrual`)).data.data,
    onSuccess: async (result) => {
      setError('')
      setMessage(
        result.status === 'posted'
          ? L(`تم ترحيل ${result.entries} قيد استحقاق.`, `${result.entries} accrual entries posted.`)
          : L('لا توجد استحقاقات جديدة.', 'No new accruals to post.'),
      )
      await queryClient.invalidateQueries({ queryKey: ['cost-accrual'] })
      await queryClient.invalidateQueries({ queryKey: ['booking'] })
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const data = query.data
  const money = (minor: number) => formatMoney(minor, baseCurrency, locale)

  return (
    <Modal
      open={period !== null}
      title={`${L('استحقاق تكاليف الموردين', 'Supplier cost accrual')} · ${period?.name ?? ''}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{L('إغلاق', 'Close')}</Button>
          {can('periods.close') ? (
            <Button onClick={() => confirm.mutate()} disabled={query.isFetching || !data || data.total_minor <= 0 || confirm.isPending}>{L('تأكيد وترحيل', 'Confirm and post')}</Button>
          ) : null}
        </>
      }
    >
      {query.isLoading ? <Loading /> : null}
      {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
      {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
      {message ? <InlineNotice tone="positive">{message}</InlineNotice> : null}
      {data ? (
        <div className="flex flex-col gap-4">
          <InlineNotice tone="warning">
            {L(
              `تقدير لتكاليف خدمات بدأت ولم تُفوتر بعد. يُعكس القيد تلقائياً بتاريخ ${data.reversal_date} فلا تُحتسب التكلفة مرتين عند وصول الفاتورة.`,
              `An estimate for services that started and are not invoiced yet. The entry reverses automatically on ${data.reversal_date}, so the cost is not counted twice when the invoice arrives.`,
            )}
          </InlineNotice>
          {data.rows.length === 0 ? (
            <EmptyState />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الحجز', 'Booking')}</Th>
                  <Th className="text-end">{L('التكلفة المقدّرة', 'Estimated')}</Th>
                  <Th className="text-end">{L('المعترف بها', 'Recognized')}</Th>
                  <Th className="text-end">{L('الاستحقاق', 'Accrual')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {data.rows.map((row) => (
                  <tr key={row.booking_id}>
                    <Td className="tabular">{row.booking_number}</Td>
                    <Td className="text-end tabular">{money(row.estimated_minor)}</Td>
                    <Td className="text-end tabular">{money(row.recognized_minor)}</Td>
                    <Td className="text-end tabular">{money(row.accrual_minor)}</Td>
                    <Td>{row.already_accrued ? <Badge tone="positive">{L('مُرحَّل', 'Posted')}</Badge> : null}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
          <p className="text-medium font-semibold tabular">{L('المتبقي للترحيل', 'Left to post')}: {money(data.total_minor)}</p>
        </div>
      ) : null}
    </Modal>
  )
}
