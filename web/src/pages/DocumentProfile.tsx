import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { DetailGrid, DetailItem, ProfileHeader, ProfileStat } from '../components/profile'
import { Badge, Card, CardHeader, ErrorState, Loading, Money, Table, Td, Th, statusTone } from '../components/ui'
import { RecordActions } from '../components/RecordActions'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'
import { statusLabel } from '../lib/labels'
import { localizedName } from '../lib/names'

type DocumentLine = { id?: string; description?: string | null; debit_minor?: number; credit_minor?: number; account?: { code?: string; name_ar?: string; name_en?: string } | null }
type DocumentRecord = {
  id: string
  type: string
  series: string
  number: string
  party: { name_ar: string; name_en: string } | null
  currency: string
  fx_rate: string
  status: string
  subtotal_minor: number
  tax_minor: number
  total_minor: number
  journal_entry_id: string | null
  reversal_reason: string | null
  memo: string | null
  submitted_at: string | null
  approved_at: string | null
  posted_at: string | null
  lines?: DocumentLine[]
}

const TYPES: Record<string, { ar: string; en: string }> = {
  receipt_voucher: { ar: 'سند قبض', en: 'Receipt voucher' },
  payment_voucher: { ar: 'سند صرف', en: 'Payment voucher' },
  transfer_voucher: { ar: 'سند تحويل', en: 'Transfer voucher' },
  adjustment_voucher: { ar: 'سند تسوية', en: 'Adjustment voucher' },
  refund_voucher: { ar: 'سند رد', en: 'Refund voucher' },
}

/** One accounting voucher on its own page. Read-only; submit/approve/post stay on the vouchers list. */
export default function DocumentProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const query = useQuery({
    queryKey: ['accounting-document', id],
    queryFn: async () => (await api.get<{ data: DocumentRecord }>(`/accounting-documents/${encodeURIComponent(id)}`)).data.data,
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />
  const doc = query.data
  const type = TYPES[doc.type] ? L(TYPES[doc.type].ar, TYPES[doc.type].en) : doc.type

  return (
    <div>
      <ProfileHeader
        title={doc.number}
        subtitle={`${type} · ${doc.series}`}
        icon="clipboardCheck"
        backTo="/documents"
        backLabel={t('vouchers')}
        actions={<RecordActions apiBase="/accounting-documents" page="/documents" id={doc.id} status={doc.status} submitPermission="documents.create" approvePermission="documents.approve" detailKey={['accounting-document', id]} />}
        badge={<Badge tone={statusTone(doc.status)}>{statusLabel(doc.status, locale)}</Badge>}
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <ProfileStat label={t('subtotal')} value={<Money amountMinor={doc.subtotal_minor} currency={doc.currency} />} icon="receipt" />
        <ProfileStat label={t('tax')} value={<Money amountMinor={doc.tax_minor} currency={doc.currency} />} icon="percent" />
        <ProfileStat label={L('الإجمالي', 'Total')} value={<Money amountMinor={doc.total_minor} currency={doc.currency} />} icon="banknote" />
      </div>

      <div className="flex flex-col gap-4">
        <Card>
          <CardHeader title={L('بيانات السند', 'Voucher details')} icon="clipboardCheck" />
          <DetailGrid>
            <DetailItem label={t('type')}>{type}</DetailItem>
            <DetailItem label={t('party')}>{doc.party ? localizedName(doc.party, locale) : '—'}</DetailItem>
            <DetailItem label={t('currency')}>{doc.currency}</DetailItem>
            <DetailItem label={L('سعر الصرف', 'FX rate')} dir="ltr">{doc.fx_rate}</DetailItem>
            <DetailItem label={L('البيان', 'Memo')}>{doc.memo || '—'}</DetailItem>
            <DetailItem label={L('أُرسل', 'Submitted')}>{doc.submitted_at ? formatDateTime(doc.submitted_at, locale) : '—'}</DetailItem>
            <DetailItem label={L('اعتُمد', 'Approved')}>{doc.approved_at ? formatDateTime(doc.approved_at, locale) : '—'}</DetailItem>
            <DetailItem label={L('رُحّل', 'Posted')}>{doc.posted_at ? formatDate(doc.posted_at, locale) : '—'}</DetailItem>
            <DetailItem label={t('journalEntry')}>
              {doc.journal_entry_id ? (
                <Link to={`/journal?entry=${encodeURIComponent(doc.journal_entry_id)}`} className="text-brand-1-accent underline">
                  {doc.journal_entry_id}
                </Link>
              ) : (
                '—'
              )}
            </DetailItem>
            {doc.reversal_reason ? <DetailItem label={L('سبب العكس', 'Reversal reason')}>{doc.reversal_reason}</DetailItem> : null}
          </DetailGrid>
        </Card>

        {doc.lines && doc.lines.length > 0 ? (
          <Card>
            <CardHeader title={L('البنود', 'Lines')} icon="listChecks" />
            <Table>
              <thead>
                <tr>
                  <Th>{L('الحساب', 'Account')}</Th>
                  <Th>{t('description')}</Th>
                  <Th className="text-end">{L('مدين', 'Debit')}</Th>
                  <Th className="text-end">{L('دائن', 'Credit')}</Th>
                </tr>
              </thead>
              <tbody>
                {doc.lines.map((line, index) => (
                  <tr key={line.id ?? index}>
                    <Td>{line.account ? `${line.account.code ?? ''} ${localizedName({ name_ar: line.account.name_ar ?? '', name_en: line.account.name_en ?? '' }, locale)}`.trim() : '—'}</Td>
                    <Td>{line.description || '—'}</Td>
                    <Td className="text-end"><Money amountMinor={line.debit_minor ?? 0} currency={doc.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.credit_minor ?? 0} currency={doc.currency} /></Td>
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
