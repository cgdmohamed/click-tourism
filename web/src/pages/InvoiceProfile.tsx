import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate } from '../lib/format'
import { statusLabel } from '../lib/labels'
import type { InvoiceRecord } from '../lib/types'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Loading, Money, Table, Td, Th, statusTone } from '../components/ui'
import { DetailGrid, DetailItem, MetaItem, ProfileHeader, ProfileStat } from '../components/profile'

function nameOf(party: { name_ar: string; name_en: string }, locale: 'ar' | 'en') {
  return (locale === 'ar' ? party.name_ar : party.name_en) || party.name_ar || party.name_en
}

const accountTypes: Record<string, { ar: string; en: string }> = {
  asset: { ar: 'أصول', en: 'Asset' },
  liability: { ar: 'التزامات', en: 'Liability' },
  equity: { ar: 'حقوق ملكية', en: 'Equity' },
  revenue: { ar: 'إيرادات', en: 'Revenue' },
  expense: { ar: 'مصروفات', en: 'Expense' },
}

function accountTypeLabel(value: string, locale: 'ar' | 'en') {
  return accountTypes[value]?.[locale] ?? value
}

export default function InvoiceProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()

  const query = useQuery({
    queryKey: ['invoice-profile', id],
    queryFn: async () => {
      const { data } = await api.get<{ data: InvoiceRecord }>(`/invoices/${id}`)
      return data.data
    },
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />

  const invoice = query.data
  const party = invoice.party
  const partyName = party ? nameOf(party, locale) : '—'
  const partyHref = party ? (invoice.party_type === 'customer' ? `/customers/${party.id}` : `/suppliers/${party.id}`) : null
  const typeLabel =
    invoice.type === 'customer_invoice' ? t('customerInvoice') : invoice.type === 'supplier_invoice' ? t('supplierInvoice') : t('creditNote')

  return (
    <div>
      <ProfileHeader
        title={invoice.number}
        subtitle={`${typeLabel} · ${invoice.series}`}
        icon="fileText"
        backTo="/invoices"
        backLabel={t('invoices')}
        badge={<Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge>}
        actions={
          <Button variant="secondary" icon="printer" onClick={() => window.print()}>
            {t('print')}
          </Button>
        }
        meta={
          <>
            <MetaItem icon="calendar">{formatDate(invoice.issue_date, locale)}</MetaItem>
            {invoice.due_date ? <MetaItem icon="clock">{formatDate(invoice.due_date, locale)}</MetaItem> : null}
            <MetaItem icon={invoice.party_type === 'customer' ? 'users' : 'truck'}>
              {partyHref ? (
                <Link to={partyHref} className="text-brand-1-accent hover:underline">
                  {partyName}
                </Link>
              ) : (
                partyName
              )}
            </MetaItem>
          </>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <ProfileStat label={t('subtotal')} value={<Money amountMinor={invoice.subtotal_minor} currency={invoice.currency} />} icon="receipt" />
        <ProfileStat label={t('tax')} value={<Money amountMinor={invoice.tax_minor} currency={invoice.currency} />} icon="percent" />
        <ProfileStat label={t('total')} value={<Money amountMinor={invoice.total_minor} currency={invoice.currency} />} icon="fileText" tone="positive" />
        <ProfileStat
          label={t('outstanding')}
          value={<Money amountMinor={invoice.outstanding_minor} currency={invoice.currency} />}
          hint={invoice.due_date ? formatDate(invoice.due_date, locale) : undefined}
          icon="banknote"
          tone={invoice.outstanding_minor > 0 ? 'warning' : 'positive'}
        />
      </div>

      <div className="flex flex-col gap-4">
        <Card>
          <CardHeader title={t('invoiceDetails')} icon="fileText" />
          <DetailGrid>
            <DetailItem label={t('type')}>{typeLabel}</DetailItem>
            <DetailItem label={t('status')}>
              <Badge tone={statusTone(invoice.status)}>{statusLabel(invoice.status, locale)}</Badge>
            </DetailItem>
            <DetailItem label={t('party')}>{partyName}</DetailItem>
            <DetailItem label={t('issueDate')}>{formatDate(invoice.issue_date, locale)}</DetailItem>
            <DetailItem label={t('dueDate')}>{formatDate(invoice.due_date, locale)}</DetailItem>
            <DetailItem label={t('currency')}>{invoice.currency}</DetailItem>
            <DetailItem label={t('relatedBooking')}>{invoice.booking_id || '—'}</DetailItem>
            <DetailItem label={t('journalEntry')}>{invoice.journal_entry_id || '—'}</DetailItem>
            <DetailItem label={t('sourceDocument')}>{invoice.credit_note_of_id || '—'}</DetailItem>
            <DetailItem label={t('memo')}>{invoice.memo || '—'}</DetailItem>
          </DetailGrid>
        </Card>

        <Card>
          <CardHeader title={t('lineItems')} icon="listChecks" actions={<Badge tone="neutral">{invoice.lines?.length ?? 0}</Badge>} />
          {!invoice.lines || invoice.lines.length === 0 ? (
            <EmptyState icon="listChecks" />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>{t('account')}</Th>
                  <Th>{t('description')}</Th>
                  <Th className="text-end">{t('debit')}</Th>
                  <Th className="text-end">{t('credit')}</Th>
                  <Th className="text-end">{t('tax')}</Th>
                </tr>
              </thead>
              <tbody>
                {invoice.lines.map((line, index) => (
                  <tr key={`${line.account?.id ?? 'line'}-${index}`}>
                    <Td>
                      {line.account ? (
                        <span>
                          <span className="block font-medium" dir="ltr">{line.account.code}</span>
                          <span className="text-small text-text-subtlest">
                            {locale === 'ar' ? line.account.name_ar : line.account.name_en}
                            {line.account.type ? ` · ${accountTypeLabel(line.account.type, locale)}` : ''}
                          </span>
                        </span>
                      ) : (
                        '—'
                      )}
                    </Td>
                    <Td>{line.description || '—'}</Td>
                    <Td className="text-end">{line.debit_minor ? <Money amountMinor={line.debit_minor} currency={invoice.currency} /> : '—'}</Td>
                    <Td className="text-end">{line.credit_minor ? <Money amountMinor={line.credit_minor} currency={invoice.currency} /> : '—'}</Td>
                    <Td className="text-end">{line.tax_minor ? <Money amountMinor={line.tax_minor} currency={invoice.currency} /> : '—'}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>

        {invoice.credit_notes && invoice.credit_notes.length > 0 ? (
          <Card>
            <CardHeader title={t('creditNotes')} icon="receipt" actions={<Badge tone="neutral">{invoice.credit_notes.length}</Badge>} />
            <Table>
              <thead>
                <tr>
                  <Th>{t('invoice')}</Th>
                  <Th>{t('issueDate')}</Th>
                  <Th>{t('status')}</Th>
                  <Th className="text-end">{t('total')}</Th>
                </tr>
              </thead>
              <tbody>
                {invoice.credit_notes.map((note) => (
                  <tr key={note.id}>
                    <Td className="font-medium">
                      <Link to={`/invoices/${note.id}`} className="text-brand-1-accent hover:underline">
                        {note.number}
                      </Link>
                    </Td>
                    <Td>{formatDate(note.issue_date, locale)}</Td>
                    <Td><Badge tone={statusTone(note.status)}>{statusLabel(note.status, locale)}</Badge></Td>
                    <Td className="text-end"><Money amountMinor={note.total_minor} currency={note.currency} /></Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
        ) : null}
      </div>
    </div>
  )
}
