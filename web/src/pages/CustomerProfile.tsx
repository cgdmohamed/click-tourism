import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import { customerTypeLabel, paymentMethodLabel, statusLabel } from '../lib/labels'
import type { CustomerProfile as CustomerProfilePayload } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Loading, Money, Table, Td, Th, statusTone } from '../components/ui'
import { DetailGrid, DetailItem, MetaItem, ProfileHeader, ProfileStat, ProfileTabs, StatementView } from '../components/profile'

function nameOf(party: { name_ar: string; name_en: string }, locale: 'ar' | 'en') {
  return (locale === 'ar' ? party.name_ar : party.name_en) || party.name_ar || party.name_en
}

function initialsOf(name: string) {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('') || '?'
  )
}

export default function CustomerProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const [tab, setTab] = useState('overview')
  const [range, setRange] = useState({ from: `${new Date().getFullYear()}-01-01`, to: todayISO() })

  const query = useQuery({
    queryKey: ['customer-profile', id, range.from, range.to],
    queryFn: async () => {
      const { data } = await api.get<{ data: CustomerProfilePayload }>(`/customers/${id}`, {
        params: { from: range.from, to: range.to },
      })
      return data.data
    },
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />

  const profile = query.data
  const party = profile.party
  const name = nameOf(party, locale)

  const tabs = [
    { id: 'overview', label: t('overview'), icon: 'dashboard' as const },
    { id: 'statement', label: t('statement'), icon: 'ledger' as const, count: profile.statement.total },
  ]

  return (
    <div>
      <ProfileHeader
        title={name}
        subtitle={t('customerProfile')}
        icon="users"
        initials={initialsOf(name)}
        backTo="/customers"
        backLabel={t('customers')}
        badge={<Badge tone={party.is_active ? 'positive' : 'neutral'}>{party.is_active ? t('active') : t('inactive')}</Badge>}
        actions={
          <Button variant="secondary" icon="printer" onClick={() => window.print()}>
            {t('print')}
          </Button>
        }
        meta={
          <>
            {party.phone ? <MetaItem icon="phone" dir="ltr">{party.phone}</MetaItem> : null}
            {party.email ? <MetaItem icon="mail" dir="ltr">{party.email}</MetaItem> : null}
            {party.tax_id ? <MetaItem icon="hash" dir="ltr">{party.tax_id}</MetaItem> : null}
            <MetaItem icon="clock">{formatDate(profile.metrics.last_activity_on, locale)}</MetaItem>
          </>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <ProfileStat label={t('bookings')} value={profile.metrics.bookings_count} hint={`${profile.metrics.open_bookings_count} ${t('openBookings')}`} icon="plane" />
        <ProfileStat label={t('invoices')} value={profile.metrics.invoices_count} hint={`${profile.metrics.open_invoices_count} ${t('openInvoices')}`} icon="fileText" />
        <ProfileStat label={t('payments')} value={profile.metrics.payments_count} icon="creditCard" />
        <ProfileStat
          label={t('outstanding')}
          value={<Money amountMinor={profile.statement.reconciliation.operational_open_invoices_minor} currency={profile.statement.currency} />}
          hint={t('statementHint')}
          icon="banknote"
          tone={profile.statement.reconciliation.operational_open_invoices_minor > 0 ? 'warning' : 'positive'}
        />
      </div>

      <div className="flex flex-col gap-4">
        <ProfileTabs tabs={tabs} active={tab} onChange={setTab} label={t('customerProfile')} />

        {tab === 'overview' ? (
          <div className="flex flex-col gap-4">
            <Card>
              <CardHeader title={t('customer')} icon="user" />
              <DetailGrid>
                <DetailItem label={t('nameAr')}>{party.name_ar}</DetailItem>
                <DetailItem label={t('nameEn')}>{party.name_en}</DetailItem>
                <DetailItem label={t('type')}>{customerTypeLabel(party.type, locale)}</DetailItem>
                <DetailItem label={t('phone')} dir="ltr">{party.phone || '—'}</DetailItem>
                <DetailItem label={t('email')} dir="ltr">{party.email || '—'}</DetailItem>
                <DetailItem label={t('taxId')} dir="ltr">{party.tax_id || '—'}</DetailItem>
              </DetailGrid>
            </Card>

            <Card>
              <CardHeader
                title={t('bookings')}
                icon="plane"
                actions={
                  <Link to={`/bookings?customer_id=${party.id}`} className="text-small inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline">
                    {t('viewAll')}
                  </Link>
                }
              />
              {profile.bookings.length === 0 ? (
                <EmptyState icon="plane" title={t('noBookings')} />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('code')}</Th>
                      <Th>{t('startDate')}</Th>
                      <Th>{t('endDate')}</Th>
                      <Th>{t('status')}</Th>
                      <Th className="text-end">{t('total')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.bookings.map((booking) => (
                      <tr key={booking.id}>
                        <Td className="font-medium">{booking.number}</Td>
                        <Td>{formatDate(booking.start_date, locale)}</Td>
                        <Td>{formatDate(booking.end_date, locale)}</Td>
                        <Td>
                          <Badge tone={statusTone(booking.status)}>{statusLabel(booking.status, locale)}</Badge>
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
                title={t('invoices')}
                icon="fileText"
                actions={
                  <Link to="/invoices" className="text-small inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline">
                    {t('viewAll')}
                  </Link>
                }
              />
              {profile.invoices.length === 0 ? (
                <EmptyState icon="fileText" title={t('noInvoices')} />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('invoice')}</Th>
                      <Th>{t('issueDate')}</Th>
                      <Th>{t('status')}</Th>
                      <Th className="text-end">{t('total')}</Th>
                      <Th className="text-end">{t('outstanding')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.invoices.map((invoice) => (
                      <tr key={invoice.id}>
                        <Td className="font-medium">
                          <Link to={`/invoices/${invoice.id}`} className="text-brand-1-accent hover:underline">
                            {invoice.number}
                          </Link>
                        </Td>
                        <Td>{formatDate(invoice.issue_date, locale)}</Td>
                        <Td>
                          <Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge>
                        </Td>
                        <Td className="text-end">
                          <Money amountMinor={invoice.total_minor} currency={invoice.currency} />
                        </Td>
                        <Td className="text-end">
                          <Money amountMinor={invoice.outstanding_minor} currency={invoice.currency} />
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>

            <Card>
              <CardHeader title={t('payments')} icon="creditCard" />
              {profile.payments.length === 0 ? (
                <EmptyState icon="creditCard" title={t('noPayments')} />
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
                    {profile.payments.map((payment) => (
                      <tr key={payment.id}>
                        <Td className="font-medium">{payment.number}</Td>
                        <Td>{formatDate(payment.paid_at, locale)}</Td>
                        <Td>{paymentMethodLabel(payment.method, locale)}</Td>
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
        ) : (
          <StatementView
            statement={profile.statement}
            from={range.from}
            to={range.to}
            onRangeChange={(from, to) => setRange({ from, to })}
          />
        )}
      </div>
    </div>
  )
}
