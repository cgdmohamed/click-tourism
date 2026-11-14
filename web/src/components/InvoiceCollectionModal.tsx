import { DateField } from './DateField'
import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { toDecimal, toMinor, todayISO } from '../lib/format'
import { useBaseCurrency } from '../lib/useSetting'
import type { PaymentMethod } from '../lib/types'
import { Button, Field, Input, Modal, Money, SearchableSelect } from './ui'
import { localizedName } from '../lib/names'

type CashAccount = { id: string; name_ar: string; name_en: string; currency: string }
type ReceiptRow = { amount: string; methodId: string; accountId: string; paidAt: string; fxRate: string }
type CollectionInvoice = { id: string; number: string; currency: string; outstanding_minor: number; fx_rate?: string }

const newRow = (amount = ''): ReceiptRow => ({
  amount, methodId: '', accountId: '', paidAt: todayISO(), fxRate: '',
})

export function InvoiceCollectionModal({
  invoice, onClose, onCollected,
}: {
  invoice: CollectionInvoice | null
  onClose: () => void
  onCollected?: () => void
}) {
  const { locale } = useI18n()
  const L = (ar: string, en: string) => locale === 'ar' ? ar : en
  const queryClient = useQueryClient()
  const baseCurrency = useBaseCurrency()
  const [mode, setMode] = useState<'full' | 'partial'>('full')
  const [rows, setRows] = useState<ReceiptRow[]>([newRow()])
  const [error, setError] = useState('')

  useEffect(() => {
    setMode('full')
    setRows([newRow(invoice ? toDecimal(invoice.outstanding_minor, invoice.currency) : '')])
    setError('')
  }, [invoice?.id, invoice?.outstanding_minor, invoice?.currency])

  const methodsQuery = useQuery({
    queryKey: ['payment-methods', 'collection'],
    queryFn: async () => {
      const { data } = await api.get<{ data: PaymentMethod[] }>('/payment-methods')
      return data.data
    },
    enabled: !!invoice,
  })
  const accountsQuery = useQuery({
    queryKey: ['cash-accounts', 'collection'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
    enabled: !!invoice,
  })

  const methods = methodsQuery.data ?? []
  const accounts = (accountsQuery.data ?? []).filter((account) => account.currency === invoice?.currency)
  const amounts = rows.map((row) => {
    try { return toMinor(row.amount || '0', invoice?.currency || 'EGP') }
    catch { return -1 }
  })
  const total = amounts.reduce((sum, amount) => sum + amount, 0)
  const remaining = (invoice?.outstanding_minor ?? 0) - total
  const validRows = rows.length > 0 && amounts.every((amount) => amount > 0)
    && rows.every((row) => {
      const method = methods.find((m) => m.id === row.methodId)
      const account = accounts.find((a) => a.id === row.accountId)
      return !!method && !!account && (!method.default_cash_account_id || method.default_cash_account_id === account.id)
        && (invoice?.currency === baseCurrency || (row.fxRate.trim() !== '' && Number(row.fxRate) > 0))
    })
  const valid = !!invoice && validRows && (mode === 'full' ? remaining === 0 : total > 0 && remaining >= 0)

  const updateRow = (index: number, patch: Partial<ReceiptRow>) =>
    setRows((current) => current.map((row, i) => i === index ? { ...row, ...patch } : row))

  const collect = useMutation({
    mutationFn: async () => {
      if (!invoice || !valid) throw new Error(L('راجع المبالغ وطرق التحصيل', 'Check amounts and collection methods'))
      await api.post(`/invoices/${invoice.id}/collections`, {
        receipts: rows.map((row, index) => ({
          amount_minor: amounts[index],
          payment_method_id: row.methodId,
          cash_account_id: row.accountId,
          paid_at: row.paidAt,
          ...(invoice.currency !== baseCurrency ? { fx_rate: row.fxRate } : {}),
        })),
      }, { headers: { 'Idempotency-Key': idempotencyKey() } })
    },
    onSuccess: async () => {
      await Promise.all(
        ['invoices', 'invoice', 'invoice-profile', 'payments', 'dashboard', 'journal', 'open-invoices']
          .map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
      )
      onCollected?.()
      onClose()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <Modal
      open={!!invoice}
      title={L('تحصيل الفاتورة', 'Collect invoice') + (invoice ? ` · ${invoice.number}` : '')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{L('إلغاء', 'Cancel')}</Button>
          <Button onClick={() => collect.mutate()} disabled={!valid || collect.isPending}>
            {collect.isPending ? L('جارِ التحصيل…', 'Collecting…') : L('تأكيد التحصيل', 'Confirm collection')}
          </Button>
        </>
      }
    >
      {invoice && (
        <div className="space-y-4">
          <div className="rounded-12 border border-border-subtle p-3">
            <p className="mb-2 text-small text-text-subtle">{L('المبلغ المستحق', 'Amount due')}</p>
            <Money amountMinor={invoice.outstanding_minor} currency={invoice.currency} />
            <div className="mt-3 flex gap-2">
              <Button variant={mode === 'full' ? 'primary' : 'secondary'} onClick={() => setMode('full')}>
                {L('تحصيل كلي', 'Full payment')}
              </Button>
              <Button variant={mode === 'partial' ? 'primary' : 'secondary'} onClick={() => setMode('partial')}>
                {L('تحصيل جزئي', 'Partial payment')}
              </Button>
            </div>
          </div>
          <p className="text-small text-text-subtle">{L('يمكن تقسيم التحصيل على أكثر من طريقة، وكل طريقة تُسجّل كسند قبض منفصل مرتبط بنفس الفاتورة.', 'Split collections across multiple methods. Each method creates a separate receipt linked to the invoice.')}</p>
          {rows.map((row, index) => {
            const method = methods.find((m) => m.id === row.methodId)
            const availableAccounts = method?.default_cash_account_id
              ? accounts.filter((account) => account.id === method.default_cash_account_id)
              : accounts
            return (
              <div key={index} className="rounded-12 border border-border-subtle p-3">
                <div className="mb-3 flex items-center justify-between">
                  <strong className="text-small">{L('دفعة', 'Payment')} {index + 1}</strong>
                  {rows.length > 1 ? <Button variant="ghost" onClick={() => setRows((current) => current.filter((_, i) => i !== index))}>{L('إزالة', 'Remove')}</Button> : null}
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={L('طريقة التحصيل', 'Collection method')} required>
                    <SearchableSelect
                      value={row.methodId}
                      onChange={(methodId) => {
                        const selected = methods.find((m) => m.id === methodId)
                        updateRow(index, { methodId, accountId: selected?.default_cash_account_id ?? '' })
                      }}
                      options={methods.map((m) => ({ value: m.id, label: localizedName(m, locale) }))}
                      placeholder={L('اختر الطريقة', 'Select method')}
                    />
                  </Field>
                  <Field label={L('الخزينة / البنك', 'Cash / bank account')} required>
                    <SearchableSelect
                      value={row.accountId}
                      onChange={(accountId) => updateRow(index, { accountId })}
                      options={availableAccounts.map((a) => ({ value: a.id, label: localizedName(a, locale) }))}
                      placeholder={L('اختر الحساب', 'Select account')}
                    />
                  </Field>
                  <Field label={L('المبلغ', 'Amount') + ` (${invoice.currency})`} required>
                    <Input value={row.amount} inputMode="decimal" dir="ltr" onChange={(e) => updateRow(index, { amount: e.target.value })} />
                  </Field>
                  <Field label={L('تاريخ التحصيل', 'Collection date')} required>
                    <DateField value={row.paidAt} onChange={(e) => updateRow(index, { paidAt: e.target.value })} />
                  </Field>
                  {invoice.currency !== baseCurrency ? (
                    <Field label={L('سعر الصرف وقت التحصيل', 'Collection FX rate')} required>
                      <Input value={row.fxRate} inputMode="decimal" dir="ltr" onChange={(e) => updateRow(index, { fxRate: e.target.value })} />
                    </Field>
                  ) : null}
                </div>
              </div>
            )
          })}
          <Button variant="secondary" onClick={() => setRows((current) => [...current, newRow(remaining > 0 ? toDecimal(remaining, invoice.currency) : '')])}>
            {L('إضافة طريقة تحصيل أخرى', 'Add another payment method')}
          </Button>
          <div className="flex flex-wrap justify-between gap-3 border-t border-border-subtle pt-3 text-small">
            <span>{L('إجمالي التحصيل', 'Collection total')}: <Money amountMinor={Math.max(0, total)} currency={invoice.currency} /></span>
            <span>{L('المتبقي', 'Remaining')}: <Money amountMinor={Math.max(0, remaining)} currency={invoice.currency} /></span>
          </div>
          {mode === 'full' && remaining !== 0 ? <p className="text-small text-text-subtle">{L('التحصيل الكلي يتطلب أن يساوي مجموع الدفعات المبلغ المستحق.', 'For full payment, the total must equal the invoice outstanding.')}</p> : null}
          {error ? <p className="text-small text-danger-text">{error}</p> : null}
        </div>
      )}
    </Modal>
  )
}
