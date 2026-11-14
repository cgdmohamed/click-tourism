import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, InlineNotice, Loading, Table, Td, Th } from './ui'

type Session = { id: number; device: string; created_at: string; last_used_at: string | null; expires_at: string | null; current: boolean }

/** The user's own signed-in devices with sign-out (GAP-CORE-04). Never shows a token value. */
export default function SessionsCard() {
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['auth-sessions'],
    queryFn: async () => (await api.get<{ data: Session[] }>('/auth/sessions')).data.data,
  })
  const revoke = useMutation({
    mutationFn: async (id: number | 'others') => {
      if (id === 'others') await api.delete('/auth/sessions')
      else await api.delete(`/auth/sessions/${id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth-sessions'] }),
  })

  const rows = query.data ?? []
  const others = rows.filter((row) => !row.current).length

  return (
    <Card>
      <CardHeader
        title={L('الأجهزة المتصلة', 'Signed-in devices')}
        subtitle={L('الأجهزة التي سجّلت الدخول بحسابك. اخرج من أي جهاز لا تعرفه.', 'Devices signed in to your account. Sign out any you do not recognise.')}
        icon="shield"
        actions={
          others > 0 ? (
            <Button variant="secondary" disabled={revoke.isPending} onClick={() => revoke.mutate('others')}>
              {L('خروج من كل الأجهزة الأخرى', 'Sign out all other devices')}
            </Button>
          ) : null
        }
      />
      {query.isLoading ? <Loading /> : null}
      {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
      {revoke.isError ? (
        <div className="px-5 pt-4" role="alert">
          <InlineNotice tone="negative">{errorMessage(revoke.error)}</InlineNotice>
        </div>
      ) : null}
      {query.isSuccess && rows.length === 0 ? <EmptyState title={L('لا توجد جلسات.', 'No sessions.')} /> : null}
      {rows.length > 0 ? (
        <Table>
          <thead>
            <tr>
              <Th>{L('الجهاز', 'Device')}</Th>
              <Th>{L('آخر استخدام', 'Last used')}</Th>
              <Th>{L('بدأ', 'Started')}</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <Td>
                  {row.device}
                  {row.current ? <span className="ms-2"><Badge tone="positive">{L('هذا الجهاز', 'This device')}</Badge></span> : null}
                </Td>
                <Td className="tabular">{row.last_used_at ? formatDateTime(row.last_used_at, locale) : '—'}</Td>
                <Td className="tabular">{formatDateTime(row.created_at, locale)}</Td>
                <Td className="text-end">
                  {row.current ? null : (
                    <Button variant="secondary" disabled={revoke.isPending} onClick={() => revoke.mutate(row.id)}>
                      {L('تسجيل خروج', 'Sign out')}
                    </Button>
                  )}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      ) : null}
    </Card>
  )
}
