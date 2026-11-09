import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import type { Account, JournalEntry } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th, statusTone } from '../components/ui'

type DraftLine = { account_id: string; debit: string; credit: string; description: string }

const emptyLine = (): DraftLine => ({ account_id: '', debit: '', credit: '', description: '' })

export default function Journal() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [memo, setMemo] = useState('')
  const [entryDate, setEntryDate] = useState(todayISO())
  const [currency, setCurrency] = useState('EGP')
  const [fxRate, setFxRate] = useState('1')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine(), emptyLine()])

  const accountsQuery = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
  })

  const entriesQuery = useQuery({
    queryKey: ['journal', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: JournalEntry[] }>('/journal-entries')
      return data.data
    },
  })

  const postableAccounts = useMemo(
    () => (accountsQuery.data ?? []).filter((account) => account.is_postable && account.is_active),
    [accountsQuery.data],
  )

  const totals = useMemo(() => {
    const debit = lines.reduce((sum, line) => sum + toMinor(line.debit || '0', currency), 0)
    const credit = lines.reduce((sum, line) => sum + toMinor(line.credit || '0', currency), 0)
    return { debit, credit, balanced: debit === credit && debit > 0 }
  }, [lines, currency])

  const create = useMutation({
    mutationFn: async (post: boolean) => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        entry_date: entryDate,
        branch_id: activeBranch.id,
        currency,
        fx_rate: fxRate,
        fx_rate_date: entryDate,
        fx_rate_source: 'manual',
        memo,
        post,
        lines: lines.map((line) => ({
          account_id: line.account_id,
          debit_minor: toMinor(line.debit || '0', currency),
          credit_minor: toMinor(line.credit || '0', currency),
          description: line.description,
        })),
      }
      const { data } = await api.post<{ data: JournalEntry }>('/journal-entries', payload)
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setMemo('')
      setLines([emptyLine(), emptyLine()])
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const postDraft = useMutation({
    mutationFn: async (entry: JournalEntry) => {
      const { data } = await api.post<{ data: JournalEntry }>(`/journal-entries/${entry.id}/post`)
      return data.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reverse = useMutation({
    mutationFn: async (entry: JournalEntry) => {
      const reason = window.prompt(locale === 'ar' ? 'سبب الإلغاء/العكس' : 'Reversal reason')
      if (!reason) return null
      const { data } = await api.post(`/journal-entries/${entry.id}/reverse`, { reason })
      return data
    },
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['journal'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const updateLine = (index: number, patch: Partial<DraftLine>) => {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  return (
    <div>
      <PageHeader
        title={t('journal')}
        subtitle={locale === 'ar' ? 'قيود اليومية — مدينة = دائنة' : 'Double-entry journal — debits must equal credits'}
        actions={<Button onClick={() => setOpen(true)}>{t('newJournal')}</Button>}
      />

      {error && !open ? <p className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}

      <Card>
        {entriesQuery.isLoading ? <Loading /> : null}
        {entriesQuery.isError ? (
          <ErrorState message={errorMessage(entriesQuery.error)} onRetry={() => void entriesQuery.refetch()} />
        ) : null}
        {entriesQuery.data?.length === 0 ? <EmptyState /> : null}
        {entriesQuery.data && entriesQuery.data.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('date')}</Th>
                <Th>{t('description')}</Th>
                <Th>{t('currency')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('amount')}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {entriesQuery.data.map((entry) => (
                <tr key={entry.id}>
                  <Td className="tabular font-medium">{entry.number}</Td>
                  <Td>{formatDate(entry.entry_date, locale)}</Td>
                  <Td>{entry.memo ?? '—'}</Td>
                  <Td>{entry.currency}</Td>
                  <Td>
                    <Badge tone={statusTone(entry.status)}>{entry.status}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(entry.total_minor, entry.currency)} {entry.currency}
                  </Td>
                  <Td className="text-end">
                    {entry.status === 'posted' ? (
                      <Button variant="ghost" onClick={() => reverse.mutate(entry)}>
                        {locale === 'ar' ? 'عكس' : 'Reverse'}
                      </Button>
                    ) : (
                      <Button variant="ghost" disabled={postDraft.isPending} onClick={() => postDraft.mutate(entry)}>
                        {t('post')}
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={t('newJournal')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <span className={totals.balanced ? 'me-auto text-sm text-positive' : 'me-auto text-sm text-negative'}>
              {t('debit')}: {toDecimal(totals.debit, currency)} · {t('credit')}: {toDecimal(totals.credit, currency)}
            </span>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button
              variant="secondary"
              disabled={create.isPending || !totals.balanced}
              onClick={() => create.mutate(false)}
            >
              {t('saveDraft')}
            </Button>
            <Button disabled={create.isPending || !totals.balanced} onClick={() => create.mutate(true)}>
              {create.isPending ? t('saving') : t('post')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('date')} required>
            <Input type="date" value={entryDate} onChange={(event) => setEntryDate(event.target.value)} dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={currency} onChange={(event) => setCurrency(event.target.value)}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={locale === 'ar' ? 'سعر الصرف' : 'FX rate'} required>
            <Input value={fxRate} onChange={(event) => setFxRate(event.target.value)} dir="ltr" />
          </Field>
        </div>

        <div className="mt-4">
          <Field label={t('description')}>
            <Input value={memo} onChange={(event) => setMemo(event.target.value)} />
          </Field>
        </div>

        <div className="mt-5 flex flex-col gap-3">
          {lines.map((line, index) => (
            <div key={index} className="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-12">
              <div className="sm:col-span-5">
                <Select value={line.account_id} onChange={(event) => updateLine(index, { account_id: event.target.value })}>
                  <option value="">{locale === 'ar' ? 'اختر الحساب' : 'Select account'}</option>
                  {postableAccounts.map((account) => (
                    <option key={account.id} value={account.id}>
                      {account.code} — {locale === 'ar' ? account.name_ar : account.name_en}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-2">
                <Input
                  placeholder={t('debit')}
                  value={line.debit}
                  onChange={(event) => updateLine(index, { debit: event.target.value, credit: '' })}
                  inputMode="decimal"
                  dir="ltr"
                />
              </div>
              <div className="sm:col-span-2">
                <Input
                  placeholder={t('credit')}
                  value={line.credit}
                  onChange={(event) => updateLine(index, { credit: event.target.value, debit: '' })}
                  inputMode="decimal"
                  dir="ltr"
                />
              </div>
              <div className="sm:col-span-2">
                <Input
                  placeholder={t('description')}
                  value={line.description}
                  onChange={(event) => updateLine(index, { description: event.target.value })}
                />
              </div>
              <div className="sm:col-span-1">
                <Button
                  variant="ghost"
                  className="w-full"
                  onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                  disabled={lines.length <= 2}
                  aria-label="Remove line"
                >
                  ✕
                </Button>
              </div>
            </div>
          ))}
          <Button variant="secondary" onClick={() => setLines((current) => [...current, emptyLine()])}>
            + {t('addLine')}
          </Button>
        </div>

        {error ? <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
      </Modal>
    </div>
  )
}
