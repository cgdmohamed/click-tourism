import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatMoney } from '../lib/format'
import { Badge, Button, EmptyState, ErrorState, InlineNotice, Loading, Modal, Table, Td, Th } from './ui'

type Row = {
  account_code: string
  account_name: string
  currency: string
  party_type: string | null
  party_name: string | null
  rate: string
  native_minor: number
  carrying_base_minor: number
  revalued_base_minor: number
  difference_minor: number
}
type Preview = {
  period: { id: number; name: string; ends_on: string }
  enabled: boolean
  policy_approved: boolean
  auto_reverse: boolean
  reversal_date: string
  run_status: string | null
  rows: Row[]
  totals: { gain_minor: number; loss_minor: number; net_minor: number }
}

/**
 * DEC-008: review the estimated unrealized exchange gain or loss of a period for the active
 * branch before confirming it. Nothing is posted until the confirm button is used, and
 * confirming again never posts a second entry.
 */
export function RevaluationDialog({ period, baseCurrency, onClose }: { period: { id: string | number; name: string } | null; baseCurrency: string; onClose: () => void }) {
  const { locale } = useI18n()
  const { can, activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const ar = locale === 'ar'
  const L = (a: string, e: string) => (ar ? a : e)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  // A result or error belongs to the branch it was produced for.
  useEffect(() => {
    setMessage('')
    setError('')
  }, [activeBranch?.id, period?.id])

  const query = useQuery({
    queryKey: ['revaluation', period?.id, activeBranch?.id],
    queryFn: async () => (await api.get<{ data: Preview }>(`/fiscal-periods/${period?.id}/revaluation`)).data.data,
    enabled: period !== null,
  })

  const confirm = useMutation({
    mutationFn: async () => (await api.post<{ data: { status: string; entry_number: string | null } }>(`/fiscal-periods/${period?.id}/revaluation`)).data.data,
    onSuccess: async (result) => {
      setError('')
      setMessage(
        result.status === 'posted'
          ? L(`تم ترحيل القيد ${result.entry_number ?? ''}`, `Posted entry ${result.entry_number ?? ''}`)
          : result.status === 'already_posted'
            ? L('رُحِّلت إعادة التقييم لهذه الفترة سابقاً ولم يُنشأ قيد جديد.', 'This period was already revalued; no new entry was posted.')
            : L('لا توجد فروق لإعادة تقييمها.', 'Nothing to revalue.'),
      )
      await queryClient.invalidateQueries({ queryKey: ['revaluation'] })
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const data = query.data
  const blocked = query.isFetching || !data || !data.enabled || !data.policy_approved || data.rows.length === 0 || data.run_status === 'posted' || data.run_status === 'reversal_pending'
  const money = (minor: number) => formatMoney(minor, baseCurrency, locale)

  return (
    <Modal
      open={period !== null}
      title={`${L('إعادة تقييم العملات الأجنبية', 'Foreign-currency revaluation')} · ${period?.name ?? ''}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{L('إغلاق', 'Close')}</Button>
          {can('periods.close') ? (
            <Button onClick={() => confirm.mutate()} disabled={blocked || confirm.isPending}>{L('تأكيد وترحيل', 'Confirm and post')}</Button>
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
              'هذا تقدير غير محقق: يُحسب بأسعار الإغلاق المسجلة في جدول أسعار الصرف حتى نهاية الفترة، ولا يُرحَّل شيء قبل التأكيد.',
              'This is an unrealized estimate at the closing rates recorded in the exchange-rate table; nothing is posted before you confirm.',
            )}
          </InlineNotice>
          {!data.enabled ? <InlineNotice tone="warning">{L('إعادة التقييم غير مفعّلة في الإعدادات المحاسبية.', 'Revaluation is not enabled in the accounting settings.')}</InlineNotice> : null}
          {data.enabled && !data.policy_approved ? (
            <InlineNotice tone="warning">{L('تتطلب موافقة المحاسب على السياسة المحاسبية قبل الترحيل.', 'The accountant must approve the accounting policy before posting.')}</InlineNotice>
          ) : null}
          {data.run_status ? <Badge tone="neutral">{data.run_status}</Badge> : null}
          {data.rows.length === 0 ? (
            <EmptyState />
          ) : (
            <>
              <Table>
                <thead>
                  <tr>
                    <Th>{L('الحساب', 'Account')}</Th>
                    <Th>{L('الطرف', 'Party')}</Th>
                    <Th>{L('العملة', 'Currency')}</Th>
                    <Th className="text-end">{L('السعر', 'Rate')}</Th>
                    <Th className="text-end">{L('الدفترية', 'Carrying')}</Th>
                    <Th className="text-end">{L('بعد التقييم', 'Revalued')}</Th>
                    <Th className="text-end">{L('الفرق', 'Difference')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((row, index) => (
                    <tr key={index}>
                      <Td className="tabular">{row.account_code} · {row.account_name}</Td>
                      <Td>{row.party_name ?? '—'}</Td>
                      <Td className="tabular">{row.currency}</Td>
                      <Td className="text-end tabular">{row.rate}</Td>
                      <Td className="text-end tabular">{money(row.carrying_base_minor)}</Td>
                      <Td className="text-end tabular">{money(row.revalued_base_minor)}</Td>
                      <Td className="text-end tabular">{money(row.difference_minor)}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <dl className="grid grid-cols-3 gap-3 text-medium">
                <div><dt className="text-text-subtle">{L('ربح غير محقق', 'Unrealized gain')}</dt><dd className="tabular">{money(data.totals.gain_minor)}</dd></div>
                <div><dt className="text-text-subtle">{L('خسارة غير محققة', 'Unrealized loss')}</dt><dd className="tabular">{money(data.totals.loss_minor)}</dd></div>
                <div><dt className="text-text-subtle">{L('الصافي', 'Net')}</dt><dd className="tabular font-semibold">{money(data.totals.net_minor)}</dd></div>
              </dl>
              {data.auto_reverse ? (
                <p className="text-small text-text-subtle">
                  {L(`يُعكس القيد تلقائياً بتاريخ ${data.reversal_date}.`, `The entry reverses automatically on ${data.reversal_date}.`)}
                </p>
              ) : null}
            </>
          )}
        </div>
      ) : null}
    </Modal>
  )
}
