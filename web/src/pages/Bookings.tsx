import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import type { Booking, Customer, Paginated, Supplier } from '../lib/types'
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
  PageHeader,
  Pagination,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import { statusLabel } from '../lib/labels'

type DraftItem = {
  service_type: string
  supplier_id: string
  description: string
  starts_on: string
  quantity: string
  unit_cost: string
  unit_sell: string
  cost_currency: string
  sell_currency: string
}

const emptyItem = (currency: string): DraftItem => ({
  service_type: 'flight',
  supplier_id: '',
  description: '',
  starts_on: todayISO(),
  quantity: '1',
  unit_cost: '0',
  unit_sell: '0',
  cost_currency: currency,
  sell_currency: currency,
})

export default function Bookings() {
  const { t, locale } = useI18n()
  const { activeBranch, user } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [form, setForm] = useState({
    customer_id: '',
    start_date: todayISO(),
    end_date: '',
    pax_adults: '1',
    pax_children: '0',
    pax_infants: '0',
    currency: 'EGP',
    fx_rate: '',
    notes: '',
  })
  const [items, setItems] = useState<DraftItem[]>([emptyItem('EGP')])

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const bookingsQuery = useQuery({
    queryKey: ['bookings', statusFilter, search, page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Booking>>('/bookings', {
        params: { status: statusFilter || undefined, search: search || undefined, page, per_page: 20 },
      })
      return data
    },
  })

  const bookings = bookingsQuery.data?.data ?? []

  const customersQuery = useQuery({
    queryKey: ['customers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Customer[] }>('/customers')
      return data.data
    },
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Supplier[] }>('/suppliers')
      return data.data
    },
  })

  const totals = useMemo(() => {
    let sell = 0
    let cost = 0
    for (const item of items) {
      const quantity = Number(item.quantity || '0')
      sell += Math.round(toMinor(item.unit_sell || '0', item.sell_currency) * quantity)
      cost += Math.round(toMinor(item.unit_cost || '0', item.cost_currency) * quantity)
    }
    return { sell, cost, margin: sell - cost }
  }, [items])

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        customer_id: form.customer_id,
        branch_id: activeBranch.id,
        owner_user_id: user?.id,
        start_date: form.start_date,
        end_date: form.end_date || null,
        pax_adults: Number(form.pax_adults),
        pax_children: Number(form.pax_children),
        pax_infants: Number(form.pax_infants),
        currency: form.currency,
        ...(form.fx_rate.trim() ? { fx_rate: form.fx_rate.trim(), fx_rate_date: form.start_date, fx_rate_source: 'manual' } : {}),
        notes: form.notes,
        items: items.map((item) => ({
          service_type: item.service_type,
          supplier_id: item.supplier_id || null,
          description: item.description,
          starts_on: item.starts_on,
          quantity: Number(item.quantity),
          unit_cost_minor: toMinor(item.unit_cost || '0', item.cost_currency),
          unit_sell_minor: toMinor(item.unit_sell || '0', item.sell_currency),
          cost_currency: item.cost_currency,
          sell_currency: item.sell_currency,
        })),
      }
      const { data } = await api.post<{ data: Booking }>('/bookings', payload)
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setItems([emptyItem(form.currency)])
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const transition = useMutation({
    mutationFn: async ({ booking, status }: { booking: Booking; status: string }) => {
      const { data } = await api.post(`/bookings/${booking.id}/transition`, { status })
      return data
    },
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const updateItem = (index: number, patch: Partial<DraftItem>) => {
    setItems((current) => current.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  const serviceLabel = (value: string) => {
    const labels: Record<string, [string, string]> = {
      flight: ['طيران', 'Flight'],
      hotel: ['فندق', 'Hotel'],
      transport: ['نقل', 'Transport'],
      visa: ['تأشيرة', 'Visa'],
      program: ['برنامج سياحي', 'Program'],
      other: ['أخرى', 'Other'],
    }
    const found = labels[value]
    return found ? found[locale === 'ar' ? 0 : 1] : value
  }

  return (
    <div>
      <PageHeader
        title={t('bookings')}
        subtitle={locale === 'ar' ? 'حجوزات متعددة الخدمات مع التكلفة والربحية' : 'Multi-service bookings with cost and profitability'}
        icon="plane"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newBooking')}</Button>}
      />

      {error && !open ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder={t('search')}
            className="max-w-xs"
          />
          <Select
            value={statusFilter}
            onChange={(event) => {
              setStatusFilter(event.target.value)
              setPage(1)
            }}
            className="max-w-[180px]"
          >
            <option value="">{locale === 'ar' ? 'كل الحالات' : 'All statuses'}</option>
            {['draft', 'quoted', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'no_show'].map((status) => (
              <option key={status} value={status}>
                {statusLabel(status, locale)}
              </option>
            ))}
          </Select>
        </div>

        {bookingsQuery.isLoading ? <Loading /> : null}
        {bookingsQuery.isError ? (
          <ErrorState message={errorMessage(bookingsQuery.error)} onRetry={() => void bookingsQuery.refetch()} />
        ) : null}
        {bookingsQuery.data && bookings.length === 0 ? <EmptyState /> : null}
        {bookings.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('customer')}</Th>
                <Th>{t('startDate')}</Th>
                <Th>{t('pax')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('total')}</Th>
                <Th className="text-end">{t('margin')}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {bookings.map((booking) => (
                <tr key={booking.id}>
                  <Td className="font-medium">{booking.number}</Td>
                  <Td>{booking.customer?.name_ar ?? booking.customer?.name_en ?? '—'}</Td>
                  <Td>{formatDate(booking.start_date, locale)}</Td>
                  <Td className="tabular">{booking.pax_adults + booking.pax_children + booking.pax_infants}</Td>
                  <Td>
                    <Badge tone={statusTone(booking.status)}>{statusLabel(booking.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(booking.total_sell_minor, booking.currency)} {booking.currency}
                  </Td>
                  <Td className="text-end tabular">
                    {toDecimal(booking.margin_minor, booking.currency)} {booking.currency}
                  </Td>
                  <Td className="text-end">
                    {booking.status === 'draft' || booking.status === 'quoted' ? (
                      <Button variant="ghost" onClick={() => transition.mutate({ booking, status: 'confirmed' })}>
                        {locale === 'ar' ? 'تأكيد' : 'Confirm'}
                      </Button>
                    ) : null}
                    {booking.status === 'confirmed' ? (
                      <Button variant="ghost" onClick={() => transition.mutate({ booking, status: 'in_progress' })}>
                        {locale === 'ar' ? 'بدء' : 'Start'}
                      </Button>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
        <Pagination
          page={bookingsQuery.data?.meta.current_page ?? 1}
          lastPage={bookingsQuery.data?.meta.last_page ?? 1}
          onChange={setPage}
        />
      </Card>

      <Modal
        open={open}
        title={t('newBooking')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <span className="me-auto text-sm text-text-subtle">
              {t('total')}: {toDecimal(totals.sell, form.currency)} · {t('margin')}: {toDecimal(totals.margin, form.currency)}
            </span>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={create.isPending || !form.customer_id} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('customer')} required>
            <SearchableSelect
              value={form.customer_id}
              onChange={(customer_id) => setForm({ ...form, customer_id })}
              placeholder={t('selectCustomer')}
              options={(customersQuery.data ?? []).map((customer) => ({
                value: customer.id,
                label: locale === 'ar' ? customer.name_ar : customer.name_en,
                hint: locale === 'ar' ? customer.name_en : customer.name_ar,
              }))}
            />
          </Field>
          <Field label={t('startDate')} required>
            <Input type="date" value={form.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('endDate')}>
            <Input type="date" value={form.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'بالغون' : 'Adults'}>
            <Input type="number" min="1" value={form.pax_adults} onChange={(event) => setForm({ ...form, pax_adults: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'أطفال' : 'Children'}>
            <Input type="number" min="0" value={form.pax_children} onChange={(event) => setForm({ ...form, pax_children: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'رضع' : 'Infants'}>
            <Input type="number" min="0" value={form.pax_infants} onChange={(event) => setForm({ ...form, pax_infants: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={locale === 'ar' ? 'سعر الصرف (اتركه فارغاً للسعر المسجّل)' : 'FX rate (leave empty for the stored rate)'}>
            <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} placeholder={locale === 'ar' ? 'تلقائي حسب جدول الأسعار' : 'Automatic from the rate table'} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'ملاحظات' : 'Notes'}>
            <Input value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
          </Field>
        </div>

        <h3 className="mt-6 text-sm font-semibold">{t('items')}</h3>
        <div className="mt-3 flex flex-col gap-3">
          {items.map((item, index) => (
            <div key={index} className="grid gap-2 rounded-12 border border-border-default p-3 sm:grid-cols-12">
              <div className="sm:col-span-2">
                <Select value={item.service_type} onChange={(event) => updateItem(index, { service_type: event.target.value })}>
                  {['flight', 'hotel', 'transport', 'visa', 'program', 'other'].map((service) => (
                    <option key={service} value={service}>
                      {serviceLabel(service)}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="sm:col-span-3">
                <SearchableSelect
                  value={item.supplier_id}
                  onChange={(supplier_id) => updateItem(index, { supplier_id })}
                  placeholder={t('supplier')}
                  clearLabel={t('none')}
                  options={(suppliersQuery.data ?? []).map((supplier) => ({
                    value: supplier.id,
                    label: locale === 'ar' ? supplier.name_ar : supplier.name_en,
                    hint: locale === 'ar' ? supplier.name_en : supplier.name_ar,
                  }))}
                />
              </div>
              <div className="sm:col-span-3">
                <Input
                  placeholder={t('description')}
                  value={item.description}
                  onChange={(event) => updateItem(index, { description: event.target.value })}
                />
              </div>
              <div className="sm:col-span-1">
                <Input
                  placeholder={t('quantity')}
                  value={item.quantity}
                  onChange={(event) => updateItem(index, { quantity: event.target.value })}
                  inputMode="numeric"
                  dir="ltr"
                />
              </div>
              <div className="sm:col-span-1">
                <Input
                  placeholder={t('unitCost')}
                  value={item.unit_cost}
                  onChange={(event) => updateItem(index, { unit_cost: event.target.value })}
                  inputMode="decimal"
                  dir="ltr"
                />
              </div>
              <div className="sm:col-span-1">
                <Input
                  placeholder={t('unitPrice')}
                  value={item.unit_sell}
                  onChange={(event) => updateItem(index, { unit_sell: event.target.value })}
                  inputMode="decimal"
                  dir="ltr"
                />
              </div>
              <div className="sm:col-span-1">
                <Button
                  variant="ghost"
                  className="w-full"
                  icon="x"
                  disabled={items.length <= 1}
                  onClick={() => setItems((current) => current.filter((_, i) => i !== index))}
                  aria-label={t('removeLine')}
                />
              </div>
            </div>
          ))}
          <Button variant="secondary" onClick={() => setItems((current) => [...current, emptyItem(form.currency)])}>
            + {t('addLine')}
          </Button>
        </div>

        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
