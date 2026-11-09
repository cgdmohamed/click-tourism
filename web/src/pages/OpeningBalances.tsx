import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import type { Account, Customer, Paginated, Supplier } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  Money,
  PageHeader,
  Pagination,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'

type Batch = {
  id: string
  as_of_date: string
  memo: string | null
  status: 'draft' | 'posted' | 'reversed'
  reversal_reason?: string | null
  lines?: Array<{
    account: Account | null
    currency: string
    debit_minor: number
    credit_minor: number
    party_type: string | null
    description: string | null
  }>
}

type Reconciliation = {
  reconciled: boolean
  currency_totals: Array<{ currency: string; debit_minor: number; credit_minor: number; balanced: boolean }>
  accounts: Array<{ account_code: string; currency: string; batch_debit_minor: number; batch_credit_minor: number; matches_ledger: boolean | null }>
  control_account_detail: Array<{ account_code: string; party_type: string; control_minor: number; party_detail_minor: number; matches: boolean }>
}

type CurrencyRow = { code: string; is_active: boolean }
type DraftLine = { account_id: string; currency: string; debit: string; credit: string; party_type: '' | 'customer' | 'supplier'; party_id: string }

const emptyLine = (): DraftLine => ({ account_id: '', currency: 'EGP', debit: '', credit: '', party_type: '', party_id: '' })

export default function OpeningBalances() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [page, setPage] = useState(1)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [asOf, setAsOf] = useState(todayISO())
  const [memo, setMemo] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine(), emptyLine()])
  const [detailId, setDetailId] = useState<string | null>(null)
  const [reversing, setReversing] = useState<Batch | null>(null)
  const [reason, setReason] = useState('')

  const batchesQuery = useQuery({
    queryKey: ['opening-balances', page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Batch>>('/opening-balances', { params: { page, per_page: 20 } })
      return data
    },
  })

  const accountsQuery = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
    enabled: open,
  })

  const customersQuery = useQuery({
    queryKey: ['customers', 'picker'],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Customer>>('/customers', { params: { per_page: 100 } })
      return data.data
    },
    enabled: open,
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Supplier[] }>('/suppliers')
      return data.data
    },
    enabled: open,
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: open,
  })

  const detailQuery = useQuery({
    queryKey: ['opening-balance', detailId],
    queryFn: async () => {
      const [batch, reconciliation] = await Promise.all([
        api.get<{ data: Batch }>(`/opening-balances/${detailId}`),
        api.get<{ data: Reconciliation }>(`/opening-balances/${detailId}/reconciliation`),
      ])
      return { batch: batch.data.data, reconciliation: reconciliation.data.data }
    },
    enabled: detailId !== null,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['opening-balances'] })
    await queryClient.invalidateQueries({ queryKey: ['opening-balance'] })
  }

  const accountOptions = useMemo(
    () =>
      (accountsQuery.data ?? [])
        .filter((account) => account.is_postable && account.is_active)
        .map((account) => ({ value: account.id, label: `${account.code} — ${locale === 'ar' ? account.name_ar : account.name_en}` })),
    [accountsQuery.data, locale],
  )

  const partyOptions = (type: DraftLine['party_type']) =>
    type === 'customer'
      ? (customersQuery.data ?? []).map((party) => ({ value: party.id, label: locale === 'ar' ? party.name_ar : party.name_en }))
      : (suppliersQuery.data ?? []).map((party) => ({ value: party.id, label: locale === 'ar' ? party.name_ar : party.name_en }))

  const totals = useMemo(() => {
    const map = new Map<string, { debit: number; credit: number }>()
    for (const line of lines) {
      const current = map.get(line.currency) ?? { debit: 0, credit: 0 }
      current.debit += toMinor(line.debit || '0', line.currency)
      current.credit += toMinor(line.credit || '0', line.currency)
      map.set(line.currency, current)
    }
    return [...map.entries()].map(([currency, sum]) => ({ currency, ...sum, balanced: sum.debit === sum.credit && sum.debit > 0 }))
  }, [lines])

  const update = (index: number, patch: Partial<DraftLine>) =>
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/opening-balances', {
        as_of_date: asOf,
        memo: memo || null,
        lines: lines
          .filter((line) => line.account_id)
          .map((line) => ({
            account_id: line.account_id,
            currency: line.currency,
            debit_minor: toMinor(line.debit || '0', line.currency),
            credit_minor: toMinor(line.credit || '0', line.currency),
            party_type: line.party_type || null,
            party_id: line.party_type ? line.party_id : null,
          })),
      })
    },
    onSuccess: async () => {
      setOpen(false)
      setLines([emptyLine(), emptyLine()])
      setMemo('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const post = useMutation({
    mutationFn: async (batch: Batch) => {
      await api.post(`/opening-balances/${batch.id}/post`, undefined, { headers: { 'Idempotency-Key': idempotencyKey() } })
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const discard = useMutation({
    mutationFn: async (batch: Batch) => {
      await api.delete(`/opening-balances/${batch.id}`)
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reverse = useMutation({
    mutationFn: async () => {
      if (!reversing) return
      await api.post(`/opening-balances/${reversing.id}/reverse`, { reason })
    },
    onSuccess: async () => {
      setReversing(null)
      setReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const data = batchesQuery.data
  const canSave = totals.length > 0 && totals.every((row) => row.balanced) && lines.filter((line) => line.account_id).length >= 2

  return (
    <div>
      <PageHeader
        title={L('الأرصدة الافتتاحية', 'Opening balances')}
        subtitle={L('ترحيل أرصدة بداية التشغيل: كل عملة يجب أن تتوازن بذاتها', 'Cutover balances: each currency must balance on its own')}
        actions={
          can('opening_balances.manage') ? (
            <Button onClick={() => { setError(''); setOpen(true) }}>{L('دفعة جديدة', 'New batch')}</Button>
          ) : null
        }
      />

      {error && !open && !reversing ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <Card>
        {batchesQuery.isLoading ? <Loading /> : null}
        {batchesQuery.isError ? <ErrorState message={errorMessage(batchesQuery.error)} onRetry={() => void batchesQuery.refetch()} /> : null}
        {data && data.data.length === 0 ? <EmptyState /> : null}
        {data && data.data.length > 0 ? (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>{L('تاريخ الترحيل', 'As of')}</Th>
                  <Th>{t('description')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((batch) => (
                  <tr key={batch.id}>
                    <Td className="tabular">{formatDate(batch.as_of_date, locale)}</Td>
                    <Td>
                      {batch.memo || '—'}
                      {batch.reversal_reason ? <div className="text-small text-text-subtle">{batch.reversal_reason}</div> : null}
                    </Td>
                    <Td>
                      <Badge tone={statusTone(batch.status)}>{batch.status}</Badge>
                    </Td>
                    <Td className="text-end">
                      <div className="flex flex-wrap justify-end gap-1">
                        <Button variant="ghost" onClick={() => setDetailId(batch.id)}>{L('تفاصيل', 'Details')}</Button>
                        {batch.status === 'draft' && can('opening_balances.post') ? (
                          <Button variant="ghost" onClick={() => post.mutate(batch)}>{L('ترحيل', 'Post')}</Button>
                        ) : null}
                        {batch.status === 'draft' && can('opening_balances.manage') ? (
                          <Button variant="ghost" onClick={() => discard.mutate(batch)}>{L('حذف المسودة', 'Discard')}</Button>
                        ) : null}
                        {batch.status === 'posted' && can('opening_balances.reverse') ? (
                          <Button variant="ghost" onClick={() => { setError(''); setReversing(batch) }}>{L('عكس', 'Reverse')}</Button>
                        ) : null}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onChange={setPage} />
          </>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={L('دفعة أرصدة افتتاحية', 'Opening balance batch')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>{t('cancel')}</Button>
            <Button onClick={() => create.mutate()} disabled={create.isPending || !canSave}>{L('حفظ كمسودة', 'Save draft')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('تاريخ الترحيل', 'As-of date')} required>
            <Input type="date" value={asOf} onChange={(event) => setAsOf(event.target.value)} dir="ltr" />
          </Field>
          <Field label={t('description')}>
            <Input value={memo} onChange={(event) => setMemo(event.target.value)} />
          </Field>
        </div>

        <div className="mt-5 flex flex-col gap-3">
          {lines.map((line, index) => (
            <div key={index} className="grid gap-2 rounded-12 border border-border-default p-3 sm:grid-cols-12">
              <div className="sm:col-span-5">
                <SearchableSelect
                  value={line.account_id}
                  onChange={(value) => update(index, { account_id: value })}
                  options={accountOptions}
                  placeholder={t('selectAccount')}
                />
              </div>
              <div className="sm:col-span-2">
                <Select value={line.currency} onChange={(event) => update(index, { currency: event.target.value })}>
                  {(currenciesQuery.data ?? [{ code: 'EGP', is_active: true }]).filter((row) => row.is_active).map((row) => (
                    <option key={row.code} value={row.code}>{row.code}</option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Input placeholder={t('debit')} value={line.debit} onChange={(event) => update(index, { debit: event.target.value, credit: '' })} inputMode="decimal" dir="ltr" />
              </div>
              <div className="sm:col-span-2">
                <Input placeholder={t('credit')} value={line.credit} onChange={(event) => update(index, { credit: event.target.value, debit: '' })} inputMode="decimal" dir="ltr" />
              </div>
              <div className="flex justify-end sm:col-span-1">
                <Button variant="ghost" onClick={() => setLines((current) => (current.length > 2 ? current.filter((_, i) => i !== index) : current))} aria-label="Remove">✕</Button>
              </div>
              <div className="sm:col-span-5">
                <Select value={line.party_type} onChange={(event) => update(index, { party_type: event.target.value as DraftLine['party_type'], party_id: '' })}>
                  <option value="">{L('بدون طرف', 'No party')}</option>
                  <option value="customer">{t('customer')}</option>
                  <option value="supplier">{t('supplier')}</option>
                </Select>
              </div>
              {line.party_type ? (
                <div className="sm:col-span-7">
                  <SearchableSelect value={line.party_id} onChange={(value) => update(index, { party_id: value })} options={partyOptions(line.party_type)} placeholder={L('اختر الطرف', 'Select party')} />
                </div>
              ) : null}
            </div>
          ))}
        </div>

        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
          <Button variant="secondary" onClick={() => setLines((current) => [...current, emptyLine()])}>{L('إضافة سطر', 'Add line')}</Button>
          <div className="flex flex-wrap gap-3 text-medium">
            {totals.map((row) => (
              <span key={row.currency} className={row.balanced ? 'text-success-text' : 'text-danger-text'}>
                {row.currency}: <Money amountMinor={row.debit} currency={row.currency} /> / <Money amountMinor={row.credit} currency={row.currency} /> {row.balanced ? '✓' : '⚠'}
              </span>
            ))}
          </div>
        </div>
      </Modal>

      <Modal
        open={detailId !== null}
        title={L('تفاصيل الدفعة', 'Batch details')}
        onClose={() => setDetailId(null)}
        footer={<Button variant="secondary" onClick={() => setDetailId(null)}>{L('إغلاق', 'Close')}</Button>}
      >
        {detailQuery.isLoading ? <Loading /> : null}
        {detailQuery.isError ? <ErrorState message={errorMessage(detailQuery.error)} /> : null}
        {detailQuery.data ? (
          <div>
            <p className="mb-3 text-medium">
              {L('التسوية', 'Reconciliation')}:{' '}
              <Badge tone={detailQuery.data.reconciliation.reconciled ? 'positive' : 'negative'}>
                {detailQuery.data.reconciliation.reconciled ? L('مطابق', 'Reconciled') : L('غير مطابق', 'Mismatch')}
              </Badge>
            </p>
            <Table>
              <thead>
                <tr>
                  <Th>{t('account')}</Th>
                  <Th>{t('currency')}</Th>
                  <Th className="text-end">{t('debit')}</Th>
                  <Th className="text-end">{t('credit')}</Th>
                </tr>
              </thead>
              <tbody>
                {(detailQuery.data.batch.lines ?? []).map((line, index) => (
                  <tr key={index}>
                    <Td>
                      <span className="tabular">{line.account?.code}</span> {locale === 'ar' ? line.account?.name_ar : line.account?.name_en}
                      {line.party_type ? <div className="text-small text-text-subtle">{line.party_type}</div> : null}
                    </Td>
                    <Td>{line.currency}</Td>
                    <Td className="text-end"><Money amountMinor={line.debit_minor} currency={line.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={line.credit_minor} currency={line.currency} /></Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            {detailQuery.data.reconciliation.control_account_detail.map((row) => (
              <p key={row.account_code} className="mt-2 text-small text-text-subtle">
                {row.account_code} ({row.party_type}): {row.matches ? '✓' : '⚠'}
              </p>
            ))}
          </div>
        ) : null}
      </Modal>

      <Modal
        open={reversing !== null}
        title={L('عكس الدفعة', 'Reverse batch')}
        onClose={() => setReversing(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReversing(null)}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => reverse.mutate()} disabled={reverse.isPending || reason.trim() === ''}>{L('عكس', 'Reverse')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <Field label={L('السبب', 'Reason')} required>
          <Input value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
    </div>
  )
}
