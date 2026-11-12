import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import { employeeCategoryLabel, employeeTransactionLabel, statusLabel } from '../lib/labels'
import type { EmployeeProfile as EmployeeProfilePayload } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, InlineNotice, Loading, Money, Table, Td, Th, statusTone } from '../components/ui'
import { DetailGrid, DetailItem, MetaItem, ProfileHeader, ProfileStat, ProfileTabs, StatementView } from '../components/profile'
import { localizedName } from '../lib/names'

function nameOf(party: { name_ar: string; name_en: string }, locale: 'ar' | 'en') {
  return (localizedName(party, locale)) || party.name_ar || party.name_en
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

export default function EmployeeProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const [tab, setTab] = useState('overview')
  const [range, setRange] = useState({ from: `${new Date().getFullYear()}-01-01`, to: todayISO() })

  const query = useQuery({
    queryKey: ['employee-profile', id, range.from, range.to],
    queryFn: async () => {
      const { data } = await api.get<{ data: EmployeeProfilePayload }>(`/employees/${id}`, {
        params: { from: range.from, to: range.to },
      })
      return data.data
    },
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />

  const profile = query.data
  const employee = profile.employee
  const name = nameOf(employee, locale)

  const tabs = [
    { id: 'overview', label: t('overview'), icon: 'dashboard' as const },
    { id: 'advances', label: t('advances'), icon: 'banknote' as const, count: profile.metrics.advances_count },
    { id: 'custodies', label: t('custodies'), icon: 'wallet' as const, count: profile.metrics.custodies_count },
    { id: 'transactions', label: t('transactions'), icon: 'activity' as const, count: profile.metrics.transactions_count },
    { id: 'statement', label: t('statement'), icon: 'ledger' as const, count: profile.statement.total },
  ]

  return (
    <div>
      <ProfileHeader
        title={name}
        subtitle={`${t('employeeProfile')} · ${employee.internal_code}`}
        icon="userCheck"
        initials={initialsOf(name)}
        backTo="/employees"
        backLabel={t('employees')}
        badge={<Badge tone={employee.is_active ? 'positive' : 'neutral'}>{employee.is_active ? t('active') : t('inactive')}</Badge>}
        actions={
          <Button variant="secondary" icon="printer" onClick={() => window.print()}>
            {t('print')}
          </Button>
        }
        meta={
          <>
            {employee.phone ? <MetaItem icon="phone" dir="ltr">{employee.phone}</MetaItem> : null}
            {employee.email ? <MetaItem icon="mail" dir="ltr">{employee.email}</MetaItem> : null}
            <MetaItem icon="briefcase">{employeeCategoryLabel(employee.category, locale)}</MetaItem>
            <MetaItem icon="clock">{formatDate(profile.metrics.last_activity_on, locale)}</MetaItem>
          </>
        }
        note={<InlineNotice tone="info">{t('employeeFinancialNote')}</InlineNotice>}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <ProfileStat
          label={t('currentBalance')}
          value={<Money amountMinor={employee.balance_minor ?? 0} currency={profile.statement.currency} />}
          hint={t('statementHint')}
          icon="banknote"
          tone={(employee.balance_minor ?? 0) > 0 ? 'warning' : 'positive'}
        />
        <ProfileStat
          label={t('advancesOutstanding')}
          value={<Money amountMinor={profile.metrics.advances_outstanding_minor} currency={profile.statement.currency} />}
          hint={`${profile.metrics.open_advances_count} / ${profile.metrics.advances_count}`}
          icon="coins"
        />
        <ProfileStat
          label={t('custodyOutstanding')}
          value={<Money amountMinor={profile.metrics.custody_outstanding_minor} currency={profile.statement.currency} />}
          hint={`${profile.metrics.open_custodies_count} / ${profile.metrics.custodies_count}`}
          icon="wallet"
        />
        <ProfileStat label={t('transactions')} value={profile.metrics.transactions_count} icon="activity" />
      </div>

      <div className="flex flex-col gap-4">
        <ProfileTabs tabs={tabs} active={tab} onChange={setTab} label={t('employeeProfile')} />

        {tab === 'overview' ? (
          <div className="flex flex-col gap-4">
            <Card>
              <CardHeader title={t('employee')} icon="userCheck" />
              <DetailGrid>
                <DetailItem label={t('code')}>{employee.internal_code}</DetailItem>
                <DetailItem label={t('nameAr')}>{employee.name_ar}</DetailItem>
                <DetailItem label={t('nameEn')}>{employee.name_en}</DetailItem>
                <DetailItem label={t('employeeCategory')}>{employeeCategoryLabel(employee.category, locale)}</DetailItem>
                <DetailItem label={t('phone')} dir="ltr">{employee.phone || '—'}</DetailItem>
                <DetailItem label={t('email')} dir="ltr">{employee.email || '—'}</DetailItem>
                <DetailItem label={t('linkedUser')}>{profile.user?.name ?? t('notLinked')}</DetailItem>
              </DetailGrid>
            </Card>
          </div>
        ) : null}

        {tab === 'advances' ? (
          <Card>
            <CardHeader title={t('advances')} icon="banknote" actions={<Badge tone="neutral">{profile.advances.length}</Badge>} />
            {profile.advances.length === 0 ? (
              <EmptyState icon="banknote" title={t('noAdvances')} />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{t('date')}</Th>
                    <Th>{t('amount')}</Th>
                    <Th>{t('outstanding')}</Th>
                    <Th>{t('status')}</Th>
                    <Th>{t('memo')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {profile.advances.map((advance) => (
                    <tr key={advance.id}>
                      <Td className="font-medium">{formatDate(advance.request_date, locale)}</Td>
                      <Td><Money amountMinor={advance.amount_minor} currency={profile.statement.currency} /></Td>
                      <Td><Money amountMinor={advance.outstanding_minor} currency={profile.statement.currency} /></Td>
                      <Td><Badge tone={statusTone(advance.status)}>{statusLabel(advance.status, locale)}</Badge></Td>
                      <Td className="max-w-64 truncate">{advance.memo || '—'}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        ) : null}

        {tab === 'custodies' ? (
          <Card>
            <CardHeader title={t('custodies')} icon="wallet" actions={<Badge tone="neutral">{profile.custodies.length}</Badge>} />
            {profile.custodies.length === 0 ? (
              <EmptyState icon="wallet" title={t('noCustodies')} />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{t('date')}</Th>
                    <Th>{t('description')}</Th>
                    <Th>{t('amount')}</Th>
                    <Th>{t('outstanding')}</Th>
                    <Th>{t('status')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {profile.custodies.map((custody) => (
                    <tr key={custody.id}>
                      <Td className="font-medium">{formatDate(custody.issue_date, locale)}</Td>
                      <Td>{custody.item_description || '—'}</Td>
                      <Td><Money amountMinor={custody.amount_minor} currency={profile.statement.currency} /></Td>
                      <Td><Money amountMinor={custody.outstanding_minor} currency={profile.statement.currency} /></Td>
                      <Td><Badge tone={statusTone(custody.status)}>{statusLabel(custody.status, locale)}</Badge></Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        ) : null}

        {tab === 'transactions' ? (
          <Card>
            <CardHeader title={t('financialActivity')} icon="activity" actions={<Badge tone="neutral">{profile.transactions.length}</Badge>} />
            {profile.transactions.length === 0 ? (
              <EmptyState icon="activity" title={t('noTransactions')} />
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>{t('date')}</Th>
                    <Th>{t('type')}</Th>
                    <Th>{t('description')}</Th>
                    <Th className="text-end">{t('amount')}</Th>
                    <Th className="text-end">{t('balance')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {profile.transactions.map((transaction) => (
                    <tr key={transaction.id}>
                      <Td className="font-medium">{formatDate(transaction.transaction_date, locale)}</Td>
                      <Td><Badge tone="brand">{employeeTransactionLabel(transaction.type, locale)}</Badge></Td>
                      <Td className="max-w-64 truncate">{transaction.memo || transaction.reference || '—'}</Td>
                      <Td className="text-end"><Money amountMinor={transaction.amount_minor} currency={transaction.currency} /></Td>
                      <Td className="text-end font-medium"><Money amountMinor={transaction.balance_minor} currency={transaction.currency} /></Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        ) : null}

        {tab === 'statement' ? (
          <StatementView
            statement={profile.statement}
            from={range.from}
            to={range.to}
            onRangeChange={(from, to) => setRange({ from, to })}
          />
        ) : null}
      </div>
    </div>
  )
}
