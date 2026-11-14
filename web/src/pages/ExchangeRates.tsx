import { DateField } from '../components/DateField'
import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, todayISO } from '../lib/format'
import { useBaseCurrency } from '../lib/useSetting'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'

type FxRate = {
  id: string
  currency: string
  rate: string
  rate_date: string
  source: string | null
}

type Currency = { code: string; name: string; is_active: boolean }

const emptyForm = { currency: '', rate: '', rate_date: todayISO(), source: 'manual' }

/**
 * FX rate history and manual entry (GET/POST /fx-rates). Rates are the
 * document snapshot source for foreign-currency postings, so entries are
 * never edited in place: the same currency/date pair is upserted.
 *
 * Direction (FxService / ADR-0003): 1 unit of the stored currency equals
 * `rate` units of the installation base currency. The labels below must keep
 * saying that: inverting them makes an operator enter 1/rate.
 */
export default function ExchangeRates() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const baseCurrency = useBaseCurrency()
  const [currencyFilter, setCurrencyFilter] = useState('')
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState(emptyForm)
  const ar = locale === 'ar'

  const ratesQuery = useQuery({
    queryKey: ['fx-rates', currencyFilter],
    queryFn: async () => {
      const { data } = await api.get<{ data: FxRate[] }>('/fx-rates', {
        params: currencyFilter ? { currency: currencyFilter } : undefined,
      })
      return data.data
    },
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Currency[] }>('/currencies')
      return data.data
    },
  })

  const foreignCurrencies = useMemo(
    () => (currenciesQuery.data ?? []).filter((currency) => currency.is_active && currency.code !== baseCurrency),
    [currenciesQuery.data, baseCurrency],
  )

  const rates = ratesQuery.data ?? []
  const readOnly = !can('settings.update')

  const create = useMutation({
    mutationFn: async () => {
      if (!form.currency) throw new Error(ar ? 'اختر العملة' : 'Select a currency')
      if (!form.rate.trim()) throw new Error(ar ? 'أدخل سعر الصرف' : 'Enter a rate')
      await api.post('/fx-rates', {
        currency: form.currency,
        rate: form.rate.trim(),
        rate_date: form.rate_date,
        source: form.source.trim() || 'manual',
      })
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey: ['fx-rates'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <div>
      <PageHeader
        title={ar ? 'أسعار الصرف' : 'Exchange rates'}
        subtitle={ar ? 'سجل الأسعار المستخدمة في لقطات المستندات بالعملة الأجنبية' : 'Rate history used as the FX snapshot for foreign-currency documents'}
        icon="refresh"
        actions={
          !readOnly ? (
            <Button icon="plus" onClick={() => { setError(''); setForm(emptyForm); setOpen(true) }}>
              {ar ? 'سعر جديد' : 'New rate'}
            </Button>
          ) : null
        }
      />

      {error && !open ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}
      <p className="mb-4 text-small text-text-subtle">
        {ar
          ? `العملة الأساسية: ${baseCurrency}. يُدخل السعر كمقدار العملة الأساسية مقابل وحدة واحدة من العملة الأجنبية: فإذا كانت 1 USD = 50 ${baseCurrency} يُدخل 50.`
          : `Base currency: ${baseCurrency}. A rate is how much one unit of the foreign currency is worth in ${baseCurrency}: if 1 USD = 50 ${baseCurrency}, enter 50.`}
      </p>

      <Card>
        <CardHeader
          title={ar ? 'الأسعار المسجلة' : 'Recorded rates'}
          actions={
            <div className="w-48">
              <Select value={currencyFilter} onChange={(event) => setCurrencyFilter(event.target.value)} aria-label={ar ? 'تصفية بالعملة' : 'Filter by currency'}>
                <option value="">{ar ? 'كل العملات' : 'All currencies'}</option>
                {foreignCurrencies.map((currency) => (
                  <option key={currency.code} value={currency.code}>{currency.code}</option>
                ))}
              </Select>
            </div>
          }
        />
        {ratesQuery.isLoading ? <Loading /> : null}
        {ratesQuery.isError ? (
          <ErrorState message={errorMessage(ratesQuery.error)} onRetry={() => void ratesQuery.refetch()} />
        ) : null}
        {!ratesQuery.isLoading && !ratesQuery.isError && rates.length === 0 ? <EmptyState /> : null}
        {rates.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('date')}</Th>
                <Th>{t('currency')}</Th>
                <Th className="text-end">{ar ? `القيمة بـ ${baseCurrency} لوحدة أجنبية واحدة` : `Value of 1 foreign unit in ${baseCurrency}`}</Th>
                <Th>{ar ? 'المصدر' : 'Source'}</Th>
              </tr>
            </thead>
            <tbody>
              {rates.map((rate) => (
                <tr key={rate.id}>
                  <Td className="tabular">{formatDate(rate.rate_date, locale)}</Td>
                  <Td className="font-medium">{rate.currency}</Td>
                  <Td className="text-end tabular" dir="ltr">{rate.rate}</Td>
                  <Td><Badge tone="neutral">{rate.source ?? 'manual'}</Badge></Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={ar ? 'سعر صرف جديد' : 'New exchange rate'}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>{t('cancel')}</Button>
            <Button disabled={create.isPending} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
              <option value="">—</option>
              {foreignCurrencies.map((currency) => (
                <option key={currency.code} value={currency.code}>{currency.code} — {currency.name}</option>
              ))}
            </Select>
          </Field>
          <Field
            label={ar ? `القيمة بـ ${baseCurrency} لوحدة أجنبية واحدة` : `Value of 1 foreign unit in ${baseCurrency}`}
            hint={ar
              ? `مقدار العملة الأساسية مقابل وحدة واحدة من العملة المختارة`
              : `Amount of ${baseCurrency} for one unit of the selected currency`}
            required
          >
            <Input value={form.rate} onChange={(event) => setForm({ ...form, rate: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={t('date')} required>
            <DateField value={form.rate_date} onChange={(event) => setForm({ ...form, rate_date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'المصدر' : 'Source'} hint={ar ? 'مثال: manual أو central_bank' : 'For example manual or central_bank'}>
            <Input value={form.source} maxLength={50} onChange={(event) => setForm({ ...form, source: event.target.value })} dir="ltr" />
          </Field>
        </div>
        <p className="mt-3 text-small text-text-subtle">
          {ar
            ? 'إدخال سعر لنفس العملة والتاريخ يستبدل السعر السابق. تغيير سعر قديم لا يعيد تقييم القيود المرحّلة.'
            : 'Entering the same currency and date replaces the earlier rate. Changing a past rate does not revalue posted entries.'}
        </p>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
