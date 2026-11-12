import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import { supplierCategories, supplierCategoryLabel, statusLabel } from '../lib/labels'
import type { SupplierProfile as SupplierProfilePayload } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Modal, Money, Select, Table, Td, Th, statusTone } from '../components/ui'
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

export default function SupplierProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState('overview')
  const [range, setRange] = useState({ from: `${new Date().getFullYear()}-01-01`, to: todayISO() })
  const [editing, setEditing] = useState(false)
  const [editError, setEditError] = useState('')
  const [form, setForm] = useState({ category: 'other', name_ar: '', name_en: '', phone: '', email: '', settlement_currency: 'EGP', is_active: true })

  const query = useQuery({
    queryKey: ['supplier-profile', id, range.from, range.to],
    queryFn: async () => {
      const { data } = await api.get<{ data: SupplierProfilePayload }>(`/suppliers/${id}`, {
        params: { from: range.from, to: range.to },
      })
      return data.data
    },
  })

  const update = useMutation({
    mutationFn: async () => {
      const { data } = await api.patch(`/suppliers/${id}`, {
        category: form.category,
        name_ar: form.name_ar.trim(),
        name_en: form.name_en.trim(),
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        settlement_currency: form.settlement_currency,
        is_active: form.is_active,
      })
      return data
    },
    onSuccess: async () => {
      setEditing(false)
      setEditError('')
      await Promise.all([query.refetch(), queryClient.invalidateQueries({ queryKey: ['suppliers'] })])
    },
    onError: (cause) => setEditError(errorMessage(cause)),
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />

  const profile = query.data
  const party = profile.party
  const name = nameOf(party, locale)
  const openEditor = () => {
    setForm({
      category: party.category,
      name_ar: party.name_ar,
      name_en: party.name_en,
      phone: party.phone ?? '',
      email: party.email ?? '',
      settlement_currency: party.settlement_currency,
      is_active: party.is_active,
    })
    setEditError('')
    setEditing(true)
  }

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
          <>
            {can('suppliers.update') ? <Button variant="secondary" icon="edit" onClick={openEditor}>{t('edit')}</Button> : null}
            <Button variant="secondary" icon="printer" onClick={() => window.print()}>{t('print')}</Button>
          </>
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

      <Modal
        open={editing}
        title={t('editSupplier')}
        onClose={() => setEditing(false)}
        footer={<>
          <Button variant="secondary" onClick={() => setEditing(false)}>{t('cancel')}</Button>
          <Button disabled={update.isPending || !form.name_ar.trim() || !form.name_en.trim()} onClick={() => update.mutate()}>
            {update.isPending ? t('saving') : t('save')}
          </Button>
        </>}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('category')}><Select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
            {supplierCategories.map((category) => <option key={category.value} value={category.value}>{locale === 'ar' ? category.ar : category.en}</option>)}
          </Select></Field>
          <Field label={t('currency')}><Select value={form.settlement_currency} onChange={(event) => setForm({ ...form, settlement_currency: event.target.value })}>
            <option value="EGP">EGP</option><option value="SAR">SAR</option><option value="USD">USD</option>
          </Select></Field>
          <Field label={t('nameAr')} required><Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} /></Field>
          <Field label={t('nameEn')} required><Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" /></Field>
          <Field label={t('phone')}><Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} dir="ltr" /></Field>
          <Field label={t('email')}><Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} dir="ltr" /></Field>
          <label className="flex items-center gap-2 self-end pb-2 text-sm text-text-subtle"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />{t('active')}</label>
        </div>
        {editError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{editError}</p> : null}
      </Modal>

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
