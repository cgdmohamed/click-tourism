import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import { supplierCategoryLabel, statusLabel } from '../lib/labels'
import type { SupplierProfile as SupplierProfilePayload } from '../lib/types'
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

export default function SupplierProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const [tab, setTab] = useState('overview')
  const [range, setRange] = useState({ from: `${new Date().getFullYear()}-01-01`, to: todayISO() })

  const query = useQuery({
    queryKey: ['supplier-profile', id, range.from, range.to],
    queryFn: async () => {
      const { data } = await api.get<{ data: SupplierProfilePayload }>(`/suppliers/${id}`, {
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
        subtitle={t('supplierProfile')}
        icon="truck"
        initials={initialsOf(name)}
        backTo="/suppliers"
        backLabel={t('suppliers')}
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
            <MetaItem icon="tag">{supplierCategoryLabel(party.category, locale)}</MetaItem>
            <MetaItem icon="clock">{formatDate(profile.metrics.last_activity_on, locale)}</MetaItem>
          </>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <ProfileStat label={t('invoices')} value={profile.metrics.invoices_count} hint={`${profile.metrics.open_invoices_count} ${t('openInvoices')}`} icon="fileText" />
        <ProfileStat label={t('obligations')} value={profile.metrics.obligations_count} hint={`${profile.metrics.open_obligations_count} ${t('openObligations')}`} icon="handshake" />
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
        <ProfileTabs tabs={tabs} active={tab} onChange={setTab} label={t('supplierProfile')} />

        {tab === 'overview' ? (
          <div className="flex flex-col gap-4">
            <Card>
              <CardHeader title={t('supplier')} icon="truck" />
              <DetailGrid>
                <DetailItem label={t('nameAr')}>{party.name_ar}</DetailItem>
                <DetailItem label={t('nameEn')}>{party.name_en}</DetailItem>
                <DetailItem label={t('category')}>{supplierCategoryLabel(party.category, locale)}</DetailItem>
                <DetailItem label={t('phone')} dir="ltr">{party.phone || '—'}</DetailItem>
                <DetailItem label={t('email')} dir="ltr">{party.email || '—'}</DetailItem>
                <DetailItem label={t('settlementCurrency')}>{party.settlement_currency || '—'}</DetailItem>
              </DetailGrid>
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
              <CardHeader title={t('obligations')} icon="handshake" actions={<Link to="/supplier-settlements" className="text-small font-medium text-brand-1-accent hover:underline">{t('viewAll')}</Link>} />
              {profile.obligations.length === 0 ? (
                <EmptyState icon="handshake" title={t('noObligations')} />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('invoiceReference')}</Th>
                      <Th>{t('amount')}</Th>
                      <Th>{t('paidAmount')}</Th>
                      <Th>{t('remaining')}</Th>
                      <Th>{t('status')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.obligations.map((obligation) => (
                      <tr key={obligation.id}>
                        <Td className="font-medium">{obligation.invoice_reference || '—'}</Td>
                        <Td><Money amountMinor={obligation.amount_minor} currency={obligation.currency} /></Td>
                        <Td><Money amountMinor={obligation.paid_minor} currency={obligation.currency} /></Td>
                        <Td><Money amountMinor={obligation.balance_minor} currency={obligation.currency} /></Td>
                        <Td><Badge tone={statusTone(obligation.status)}>{statusLabel(obligation.status, locale)}</Badge></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>

            <Card>
              <CardHeader title={t('supplierPayments')} icon="creditCard" />
              {profile.payments.length === 0 ? (
                <EmptyState icon="creditCard" title={t('noPayments')} />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('date')}</Th>
                      <Th>{t('amount')}</Th>
                      <Th>{t('applied')}</Th>
                      <Th>{t('unapplied')}</Th>
                      <Th>{t('status')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {profile.payments.map((payment) => (
                      <tr key={payment.id}>
                        <Td className="font-medium">{formatDate(payment.paid_at, locale)}</Td>
                        <Td><Money amountMinor={payment.amount_minor} currency={payment.currency} /></Td>
                        <Td><Money amountMinor={payment.applied_minor} currency={payment.currency} /></Td>
                        <Td><Money amountMinor={payment.unallocated_minor} currency={payment.currency} /></Td>
                        <Td><Badge tone={statusTone(payment.status)}>{statusLabel(payment.status, locale)}</Badge></Td>
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
