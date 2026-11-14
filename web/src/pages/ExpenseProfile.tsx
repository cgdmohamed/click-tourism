import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AiBadge } from '../components/AiBadge'
import AttachmentsCard from '../components/AttachmentsCard'
import { DetailGrid, DetailItem, ProfileHeader, ProfileStat } from '../components/profile'
import { Badge, Card, CardHeader, ErrorState, Loading, Money, statusTone } from '../components/ui'
import { RecordActions } from '../components/RecordActions'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'
import { statusLabel } from '../lib/labels'
import { localizedName } from '../lib/names'

type ExpenseRecord = {
  id: string
  created_via?: string | null
  number: string
  expense_date: string
  category: { name_ar: string; name_en: string } | null
  payee_type: string | null
  payee_name: string | null
  currency: string
  fx_rate: string
  amount_minor: number
  tax_minor: number
  net_minor: number
  description: string
  reference: string | null
  status: string
  rejection_reason: string | null
  approved_at: string | null
  journal_entry_id: string | null
}

/** One expense on its own page (GAP-CORE: expenses had only a list). Read-only; actions stay on the list. */
export default function ExpenseProfile() {
  const { id = '' } = useParams()
  const { t, locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const query = useQuery({
    queryKey: ['expense', id],
    queryFn: async () => (await api.get<{ data: ExpenseRecord }>(`/expenses/${encodeURIComponent(id)}`)).data.data,
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} />
  if (!query.data) return <ErrorState message={t('noData')} onRetry={() => void query.refetch()} />
  const expense = query.data

  return (
    <div>
      <ProfileHeader
        title={expense.number}
        subtitle={expense.description}
        icon="receipt"
        backTo="/expenses"
        backLabel={t('expenses')}
        actions={<RecordActions apiBase="/expenses" page="/expenses" id={expense.id} status={expense.status} submitPermission="expenses.create" approvePermission="expenses.approve" detailKey={['expense', id]} />}
        badge={
          <>
            <Badge tone={statusTone(expense.status)}>{statusLabel(expense.status, locale)}</Badge>
            <AiBadge via={expense.created_via} />
          </>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <ProfileStat label={L('الإجمالي', 'Total')} value={<Money amountMinor={expense.amount_minor} currency={expense.currency} />} icon="receipt" />
        <ProfileStat label={t('tax')} value={<Money amountMinor={expense.tax_minor} currency={expense.currency} />} icon="percent" />
        <ProfileStat label={L('الصافي', 'Net')} value={<Money amountMinor={expense.net_minor} currency={expense.currency} />} icon="banknote" />
      </div>

      <div className="flex flex-col gap-4">
        <Card>
          <CardHeader title={L('بيانات المصروف', 'Expense details')} icon="receipt" />
          <DetailGrid>
            <DetailItem label={L('التاريخ', 'Date')}>{formatDate(expense.expense_date, locale)}</DetailItem>
            <DetailItem label={L('الفئة', 'Category')}>{expense.category ? localizedName(expense.category, locale) : '—'}</DetailItem>
            <DetailItem label={L('المستفيد', 'Payee')}>{expense.payee_name || '—'}</DetailItem>
            <DetailItem label={t('currency')}>{expense.currency}</DetailItem>
            <DetailItem label={L('سعر الصرف', 'FX rate')} dir="ltr">{expense.fx_rate}</DetailItem>
            <DetailItem label={L('المرجع', 'Reference')}>{expense.reference || '—'}</DetailItem>
            <DetailItem label={L('تاريخ الاعتماد', 'Approved at')}>{expense.approved_at ? formatDateTime(expense.approved_at, locale) : '—'}</DetailItem>
            <DetailItem label={t('journalEntry')}>
              {expense.journal_entry_id ? (
                <Link to={`/journal?entry=${encodeURIComponent(expense.journal_entry_id)}`} className="text-brand-1-accent underline">
                  {expense.journal_entry_id}
                </Link>
              ) : (
                '—'
              )}
            </DetailItem>
            {expense.rejection_reason ? <DetailItem label={L('سبب الرفض', 'Rejection reason')}>{expense.rejection_reason}</DetailItem> : null}
          </DetailGrid>
        </Card>

        <AttachmentsCard type="expense" recordId={expense.id} />
      </div>
    </div>
  )
}
