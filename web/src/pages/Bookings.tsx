import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import type { Booking, Paginated } from '../lib/types'
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
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import PartyPicker from '../components/PartyPicker'
import QuickCreateCustomer from '../components/QuickCreateCustomer'
import { statusLabel } from '../lib/labels'
import { taxMinor } from '../lib/tax'
import { useBaseCurrency, useDefaultTaxRate, useTaxEnabled, useTaxInclusive } from '../lib/useSetting'
import BookingDetails from './BookingDetails'

type DepartureOption = { id: string; starts_on: string; ends_on: string | null; available_seats: number | null; status: string }
type TourOption = { id: string; name: string; currency: string; execution_type: string; base_price_minor: number; estimated_cost_minor: number; departures: DepartureOption[] | null }

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
  const { activeBranch, user, can } = useAuth()
  const { enabled: taxEnabled } = useTaxEnabled()
  const { rate: defaultTaxRate } = useDefaultTaxRate()
  const taxInclusive = useTaxInclusive()
  const baseCurrency = useBaseCurrency()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const requestedCustomerId = searchParams.get('customer_id') ?? ''
  const requestedDepartureId = searchParams.get('departure_id') ?? ''
  const createRequested = searchParams.get('create') === '1'
  const [open, setOpen] = useState(Boolean(requestedDepartureId || createRequested))
  const [quickCustomerOpen, setQuickCustomerOpen] = useState(false)
  const [error, setError] = useState('')
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState('')
  const [viewId, setViewId] = useState<string | null>(searchParams.get('booking_id'))
  const [editTarget, setEditTarget] = useState<Booking | null>(null)
  const [form, setForm] = useState({
    customer_id: requestedCustomerId,
    tour_departure_id: '',
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

  const toursQuery = useQuery({
    queryKey: ['tour-packages', activeBranch?.id],
    enabled: open,
    queryFn: async () => {
      const { data } = await api.get<{ data: TourOption[] }>('/tour-packages')
      return data.data
    },
  })
  const departureOptions = (toursQuery.data ?? [])
    .filter((tour) => tour.execution_type === 'fixed')
    .flatMap((tour) => (tour.departures ?? [])
      .filter((departure) => departure.status === 'open')
      .map((departure) => ({
        id: departure.id,
        label: `${tour.name} · ${departure.starts_on} · ${departure.available_seats ?? '∞'} ${locale === 'ar' ? 'مقعد متاح' : 'seats left'}`,
        start: departure.starts_on,
        end: departure.ends_on,
        currency: tour.currency,
        name: tour.name,
        unitSell: toDecimal(tour.base_price_minor, tour.currency),
        unitCost: toDecimal(tour.estimated_cost_minor, tour.currency),
      })))

  const itemAmounts = useCallback(
    (item: DraftItem) => {
      const quantity = Number.parseInt(item.quantity || '0', 10) || 0
      const gross = toMinor(item.unit_sell || '0', item.sell_currency) * quantity
      const cost = toMinor(item.unit_cost || '0', item.cost_currency) * quantity
      const tax = taxEnabled ? taxMinor(gross, defaultTaxRate, taxInclusive) : 0
      return { cost, tax, sell: gross + (taxInclusive ? 0 : tax) }
    },
    [taxEnabled, defaultTaxRate, taxInclusive],
  )

  const totals = useMemo(() => {
    let sell = 0
    let cost = 0
    let tax = 0
    for (const item of items) {
      const amounts = itemAmounts(item)
      sell += amounts.sell
      cost += amounts.cost
      tax += amounts.tax
    }
    return { sell, cost, tax, margin: sell - cost }
  }, [items, itemAmounts])

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const payload = {
        customer_id: form.customer_id,
        ...(!editTarget && form.tour_departure_id ? { tour_departure_id: form.tour_departure_id } : {}),
        start_date: form.start_date,
        end_date: form.end_date || null,
        pax_adults: Number(form.pax_adults),
        pax_children: Number(form.pax_children),
        pax_infants: Number(form.pax_infants),
        currency: form.currency,
        ...(form.fx_rate.trim() ? { fx_rate: form.fx_rate.trim() } : {}),
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
      if (editTarget) {
        const { data } = await api.patch<{ data: Booking }>(`/bookings/${editTarget.id}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: Booking }>('/bookings', {
        ...payload,
        branch_id: activeBranch.id,
        owner_user_id: user?.id,
      })
      return data.data
    },
    onSuccess: async (booking) => {
      setOpen(false)
      setEditTarget(null)
      setError('')
      setItems([emptyItem(form.currency)])
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
      setViewId(booking.id)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const startCreate = () => {
    setError('')
    setEditTarget(null)
    setForm({
      customer_id: requestedCustomerId,
      tour_departure_id: '',
      start_date: todayISO(),
      end_date: '',
      pax_adults: '1',
      pax_children: '0',
      pax_infants: '0',
      currency: 'EGP',
      fx_rate: '',
      notes: '',
    })
    setItems([emptyItem('EGP')])
    setOpen(true)
  }

  const selectDeparture = (departureId: string) => {
    const selected = departureOptions.find((option) => option.id === departureId)
    setForm((current) => ({
      ...current,
      tour_departure_id: departureId,
      start_date: selected?.start ?? current.start_date,
      end_date: selected?.end ?? current.end_date,
      currency: selected?.currency ?? current.currency,
      fx_rate: '',
    }))
    if (selected) {
      // Prefill a single program item from the catalog price snapshot.
      // Staff can still edit the selling price and cost before saving.
      const quantity = Math.max(1, Number(form.pax_adults || 0) + Number(form.pax_children || 0) + Number(form.pax_infants || 0))
      setItems([{
        ...emptyItem(selected.currency),
        service_type: 'program',
        description: selected.name,
        starts_on: selected.start,
        quantity: String(quantity),
        unit_sell: selected.unitSell,
        unit_cost: selected.unitCost,
      }])
    } else {
      setItems([emptyItem(form.currency)])
    }
  }

  useEffect(() => {
    if (requestedDepartureId && departureOptions.some((option) => option.id === requestedDepartureId)
      && form.tour_departure_id !== requestedDepartureId && !editTarget) {
      selectDeparture(requestedDepartureId)
    }
    // Apply the incoming deep link once the asynchronous departure list is ready.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestedDepartureId, toursQuery.data])

  const startEdit = (booking: Booking) => {
    setError('')
    setEditTarget(booking)
    setForm({
      customer_id: booking.customer_id,
      tour_departure_id: booking.tour_departure_id ?? '',
      start_date: booking.start_date,
      end_date: booking.end_date ?? '',
      pax_adults: String(booking.pax_adults),
      pax_children: String(booking.pax_children),
      pax_infants: String(booking.pax_infants),
      currency: booking.currency,
      fx_rate: '',
      notes: booking.notes ?? '',
    })
    setItems(
      (booking.items ?? []).map((item) => ({
        service_type: item.service_type,
        supplier_id: item.supplier_id ?? '',
        description: item.description,
        starts_on: item.starts_on ?? booking.start_date,
        quantity: String(item.quantity),
        unit_cost: toDecimal(item.unit_cost_minor, item.cost_currency ?? booking.currency),
        unit_sell: toDecimal(item.unit_sell_minor, item.sell_currency ?? booking.currency),
        cost_currency: item.cost_currency ?? booking.currency,
        sell_currency: item.sell_currency ?? booking.currency,
      })),
    )
    setOpen(true)
  }

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
        actions={can('bookings.create') ? <Button icon="plus" onClick={startCreate}>{t('newBooking')}</Button> : null}
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
            aria-label={t('status')}
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
                    <div className="flex items-center justify-end gap-1">
                      {['draft', 'quoted'].includes(booking.status) && can('bookings.update') ? (
                        <Button variant="ghost" onClick={() => startEdit(booking)}>
                          {t('edit')}
                        </Button>
                      ) : null}
                      <Button variant="ghost" onClick={() => setViewId(booking.id)}>
                        {locale === 'ar' ? 'عرض' : 'View'}
                      </Button>
                    </div>
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

      <BookingDetails bookingId={viewId} onClose={() => setViewId(null)} />
      <QuickCreateCustomer open={quickCustomerOpen} onClose={() => setQuickCustomerOpen(false)}
        onCreated={(customer) => { setForm((current) => ({ ...current, customer_id: customer.id })); setQuickCustomerOpen(false) }} />

      <Modal
        open={open}
        title={editTarget ? `${t('edit')} ${editTarget.number}` : t('newBooking')}
        onClose={() => { setOpen(false); setEditTarget(null) }}
        footer={
          <>
            <span className="me-auto text-sm text-text-subtle">
              {t('total')}: {toDecimal(totals.sell, form.currency)} · {t('tax')}: {toDecimal(totals.tax, form.currency)} · {t('margin')}: {toDecimal(totals.margin, form.currency)}
            </span>
            <Button variant="secondary" onClick={() => { setOpen(false); setEditTarget(null) }}>
              {t('cancel')}
            </Button>
            <Button disabled={create.isPending || !form.customer_id} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            <Field label={t('customer')} required>
              <PartyPicker
                kind="customer"
                value={form.customer_id}
                onChange={(customer_id) => setForm({ ...form, customer_id })}
                placeholder={t('selectCustomer')}
              />
            </Field>
            {!editTarget && can('customers.create') ? (
              <Button variant="ghost" icon="plus" onClick={() => setQuickCustomerOpen(true)}>
                {locale === 'ar' ? 'إضافة عميل جديد دون مغادرة الحجز' : 'Add customer without leaving booking'}
              </Button>
            ) : null}
          </div>
          {!editTarget ? (
            <Field label={locale === 'ar' ? 'موعد برنامج سياحي (اختياري)' : 'Tour departure (optional)'}>
              <Select
                value={form.tour_departure_id}
                onChange={(event) => selectDeparture(event.target.value)}
              >
                <option value="">{locale === 'ar' ? 'حجز مستقل / خدمة منفصلة' : 'Independent booking / standalone service'}</option>
                {departureOptions.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
              </Select>
            </Field>
          ) : null}
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
          {form.currency !== baseCurrency ? (
            <Field label={locale === 'ar' ? 'سعر الصرف (اختياري)' : 'Exchange rate (optional)'}>
              <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} placeholder={locale === 'ar' ? 'تلقائي حسب جدول الأسعار' : 'Automatic from the rate table'} inputMode="decimal" dir="ltr" />
            </Field>
          ) : null}
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
                <PartyPicker
                  kind="supplier"
                  value={item.supplier_id}
                  onChange={(supplier_id) => updateItem(index, { supplier_id })}
                  placeholder={t('supplier')}
                  clearLabel={t('none')}
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
              <div className="sm:col-span-4">
                {!taxEnabled ? (
                  <p className="text-small text-text-subtle">{locale === 'ar' ? 'الضريبة معطلة في الإعدادات.' : 'Tax is disabled in settings.'}</p>
                ) : null}
              </div>
              <div className="text-small self-center text-text-subtle sm:col-span-7">
                {taxEnabled ? `${t('tax')}: ${toDecimal(itemAmounts(item).tax, item.sell_currency)} ${item.sell_currency}` : ''}
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
