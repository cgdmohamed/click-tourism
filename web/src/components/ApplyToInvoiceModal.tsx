import { useCallback, useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toDecimal, toMinor, formatMoney } from '../lib/format'
import { useRemoteOptions } from '../lib/useRemoteOptions'
import { Button, Field, Input, Modal, SearchableSelect } from './ui'

type OpenInvoice = {
  id: string
  number: string
  issue_date: string
  due_date?: string | null
  currency: string
  total_minor: number
  outstanding_minor: number
}

export type ApplyTarget = {
  kind: 'customer' | 'supplier'
  paymentId: string
  paymentLabel: string
  partyId: string
  currency: string
  availableMinor: number
}

/**
 * Settles an open invoice with part of a receipt (customer) or of a supplier
 * advance (supplier). The server posts the entry and lowers the invoice's
 * outstanding amount.
 */
export function ApplyToInvoiceModal({ target, onClose }: { target: ApplyTarget | null; onClose: () => void }) {
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const queryClient = useQueryClient()
  const [invoiceId, setInvoiceId] = useState('')
  const [amount, setAmount] = useState('')
  const [error, setError] = useState('')

  // Open invoices of the party in the payment's currency, searchable by number on
  // the server; a party with more than one page of open invoices stays reachable.
  const params = useMemo(
    () => ({
      party_type: target?.kind,
      party_id: target?.partyId,
      type: target?.kind === 'customer' ? 'customer_invoice' : 'supplier_invoice',
      currency: target?.currency,
      open: 1,
    }),
    [target?.kind, target?.partyId, target?.currency],
  )
  const toOption = useCallback(
    (invoice: OpenInvoice) => ({
      value: invoice.id,
      label: `${invoice.number} · ${formatDate(invoice.issue_date, locale)} · ${L('المستحق', 'Due')} ${toDecimal(invoice.outstanding_minor, invoice.currency)}`,
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [locale],
  )
  const picker = useRemoteOptions<OpenInvoice>({
    endpoint: '/invoices',
    keyPrefix: 'open-invoices',
    params,
    toOption,
    value: invoiceId,
    enabled: target !== null,
  })

  const invoices = picker.rows
  const selected = invoices.find((invoice) => invoice.id === invoiceId)

  useEffect(() => {
    setInvoiceId('')
    setAmount('')
    setError('')
  }, [target?.paymentId])

  const choose = (id: string) => {
    setInvoiceId(id)
    const invoice = invoices.find((item) => item.id === id)
    if (invoice && target) {
      setAmount(toDecimal(Math.min(invoice.outstanding_minor, target.availableMinor), target.currency))
    }
  }

  const apply = useMutation({
    mutationFn: async () => {
      if (!target) return
      const base = target.kind === 'customer' ? 'payments' : 'supplier-payments'
      await api.post(
        `/${base}/${target.paymentId}/invoice-applications`,
        { invoice_id: invoiceId, amount_minor: toMinor(amount, target.currency) },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      await Promise.all(
        ['payments', 'supplier-payments', 'invoices', 'open-invoices', 'dashboard'].map((key) =>
          queryClient.invalidateQueries({ queryKey: [key] }),
        ),
      )
      onClose()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const minor = target ? toMinor(amount || '0', target.currency) : 0
  const valid =
    !!selected && !!target && minor > 0 && minor <= selected.outstanding_minor && minor <= target.availableMinor

  return (
    <Modal
      open={target !== null}
      title={L('تطبيق على فاتورة', 'Apply to an invoice')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {L('إلغاء', 'Cancel')}
          </Button>
          <Button disabled={!valid || apply.isPending} onClick={() => apply.mutate()}>
            {apply.isPending ? L('جارٍ الحفظ…', 'Saving…') : L('تطبيق', 'Apply')}
          </Button>
        </>
      }
    >
      {target ? (
        <div className="grid gap-4">
          <p className="text-medium text-text-subtle">
            {target.paymentLabel} · {L('المتاح للتطبيق', 'Available')}:{' '}
            <span className="tabular font-medium text-text-default">
              {formatMoney(target.availableMinor, target.currency, locale)}
            </span>
          </p>
          <Field label={L('الفاتورة', 'Invoice')} required>
            <SearchableSelect
              value={invoiceId}
              onChange={choose}
              options={picker.options}
              onSearch={picker.setSearch}
              hasMore={picker.hasMore}
              placeholder={picker.loading ? L('جارٍ التحميل…', 'Loading…') : L('اختر فاتورة أو ابحث بالرقم', 'Choose an invoice or search by number')}
            />
          </Field>
          {!picker.loading && invoices.length === 0 ? (
            <p className="text-small text-text-subtle">
              {L(`لا توجد فواتير مفتوحة بعملة ${target.currency} لهذا الطرف.`, `No open ${target.currency} invoices for this party.`)}
            </p>
          ) : null}
          <Field
            label={`${L('المبلغ', 'Amount')} (${target.currency})`}
            required
            error={
              selected && minor > selected.outstanding_minor
                ? L('أكبر من المستحق على الفاتورة', 'More than the invoice outstanding')
                : minor > target.availableMinor
                  ? L('أكبر من المتاح', 'More than available')
                  : undefined
            }
          >
            <Input value={amount} onChange={(event) => setAmount(event.target.value)} inputMode="decimal" dir="ltr" />
          </Field>
          {error ? (
            <p className="rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
          ) : null}
        </div>
      ) : null}
    </Modal>
  )
}
