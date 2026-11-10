import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate } from '../lib/format'
import type { Booking, Payment } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Loading, Money, PageHeader, StatCard, Table, Td, Th, statusTone } from '../components/ui'
import { Icon } from '../components/icons'

type Summary = {
  open_bookings: number
  confirmed_bookings: number
  collected_minor: number
  overdue_minor: number
  currency: string
  recent_bookings: Booking[]
  recent_payments: Payment[]
}

export default function Dashboard() {
  const { t, locale } = useI18n()
  const { activeBranch, user } = useAuth()
  const query = useQuery({
    queryKey: ['dashboard', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Summary }>('/dashboard/summary')
      return data.data
    },
  })

  return (
    <div>
      <PageHeader
        title={`${t('welcome')}${user ? `، ${user.name}` : ''}`}
        subtitle={activeBranch ? (locale === 'ar' ? activeBranch.name_ar : activeBranch.name_en) : undefined}
        icon="dashboard"
        actions={
          <>
            <Link to="/bookings">
              <Button icon="plus">{t('newBooking')}</Button>
            </Link>
            <Link to="/payments">
              <Button variant="secondary" icon="plus">
                {t('newPayment')}
              </Button>
            </Link>
          </>
        }
      />

      {query.isLoading ? <Loading /> : null}
      {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}

      {query.data ? (
        <div className="flex flex-col gap-6">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label={t('openBookings')} value={query.data.open_bookings} icon="calendar" />
            <StatCard label={t('confirmed')} value={query.data.confirmed_bookings} icon="checkCircle" />
            <StatCard
              label={t('collected')}
              value={<Money amountMinor={query.data.collected_minor} currency={query.data.currency} />}
              tone="positive"
              icon="coins"
            />
            <StatCard
              label={t('overdue')}
              value={<Money amountMinor={query.data.overdue_minor} currency={query.data.currency} />}
              tone={query.data.overdue_minor > 0 ? 'negative' : undefined}
              icon="alertTriangle"
            />
          </div>

          <div className="grid gap-6 xl:grid-cols-2">
            <Card>
              <CardHeader
                title={t('bookings')}
                icon="plane"
                actions={
                  <Link to="/bookings" className="text-small inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline">
                    {t('viewAll')}
                    <Icon name="arrowRight" className="h-3.5 w-3.5" directional />
                  </Link>
                }
              />
              {query.data.recent_bookings.length === 0 ? (
                <EmptyState icon="plane" />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('code')}</Th>
                      <Th>{t('customer')}</Th>
                      <Th>{t('date')}</Th>
                      <Th>{t('status')}</Th>
                      <Th className="text-end">{t('total')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {query.data.recent_bookings.map((booking) => (
                      <tr key={booking.id}>
                        <Td className="font-medium">{booking.number}</Td>
                        <Td>{booking.customer?.name_ar ?? booking.customer?.name_en ?? '—'}</Td>
                        <Td>{formatDate(booking.start_date, locale)}</Td>
                        <Td>
                          <Badge tone={statusTone(booking.status)}>{booking.status}</Badge>
                        </Td>
                        <Td className="text-end">
                          <Money amountMinor={booking.total_sell_minor} currency={booking.currency} />
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>

            <Card>
              <CardHeader
                title={t('payments')}
                icon="creditCard"
                actions={
                  <Link to="/payments" className="text-small inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline">
                    {t('viewAll')}
                    <Icon name="arrowRight" className="h-3.5 w-3.5" directional />
                  </Link>
                }
              />
              {query.data.recent_payments.length === 0 ? (
                <EmptyState icon="creditCard" />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('code')}</Th>
                      <Th>{t('date')}</Th>
                      <Th>{t('paymentMethod')}</Th>
                      <Th className="text-end">{t('amount')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {query.data.recent_payments.map((payment) => (
                      <tr key={payment.id}>
                        <Td className="font-medium">{payment.number}</Td>
                        <Td>{formatDate(payment.paid_at, locale)}</Td>
                        <Td>{payment.method}</Td>
                        <Td className="text-end">
                          <Money amountMinor={payment.amount_minor} currency={payment.currency} />
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          </div>
        </div>
      ) : null}
    </div>
  )
}
