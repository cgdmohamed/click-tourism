import { DateField } from '../components/DateField'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { useActiveCurrencyCodes } from '../lib/useCurrencies'
import { resolveCurrency } from '../lib/currencies'
import { formatDate, toDecimal, toMinor, todayISO, formatMoney } from '../lib/format'
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
import { AiBadge } from '../components/AiBadge'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'
import { FilterControl, PartyFilterField, useFilters } from '../components/FilterDrawer'
import { BookingFormShell, FormSection } from '../components/booking/BookingFormShell'
import { BookingSummary } from '../components/booking/BookingSummary'
import { ServiceCard } from '../components/booking/ServiceCard'
import { emptyItem, withUid, type DraftItem } from '../components/booking/types'
import { focusField, validateBooking } from '../components/booking/validation'

type DepartureOption = { id: string; starts_on: string; ends_on: string | null; available_seats: number | null; status: string }
type TourOption = { id: string; name: string; currency: string; execution_type: string; base_price_minor: number; estimated_cost_minor: number; departures: DepartureOption[] | null }

export default function Bookings() {
  const currencyCodes = useActiveCurrencyCodes()
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
  // Inline errors appear after the first save attempt (the "still needed" list is always visible).
  const [showErrors, setShowErrors] = useState(false)
  // A new draft must never keep a currency that is no longer offered (e.g. the hard-coded default
  // after it was deactivated): reconcile the draft and its lines with the active currencies.
  const draftCurrency = editTarget ? form.currency : resolveCurrency(form.currency, currencyCodes, baseCurrency)
  useEffect(() => {
    if (!open || editTarget || form.currency === draftCurrency) return
    const previous = form.currency
    setForm((current) => ({ ...current, currency: draftCurrency, fx_rate: '' }))
    setItems((current) => current.map((item) => ({
      ...item,
      cost_currency: item.cost_currency === previous ? draftCurrency : item.cost_currency,
      sell_currency: item.sell_currency === previous ? draftCurrency : item.sell_currency,
    })))
  }, [open, editTarget, form.currency, draftCurrency])

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const filters = useFilters(
    [
      {
        key: 'status',
        label: t('status'),
        type: 'select',
        options: ['draft', 'quoted', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'no_show'].map((status) => ({ value: status, label: statusLabel(status, locale) })),
        allLabel: locale === 'ar' ? 'كل الحالات' : 'All statuses',
      },
      {
        key: 'customer_id',
        label: t('customer'),
        type: 'custom',
        render: (value, set) => <PartyFilterField kind="customer" value={value} set={set} />,
      },
      {
        key: 'needs_invoice',
        label: locale === 'ar' ? 'الفوترة' : 'Invoicing',
        type: 'select',
        options: [{ value: '1', label: locale === 'ar' ? 'جاهز للفوترة (بلا فاتورة)' : 'Ready to invoice (no invoice yet)' }],
        allLabel: locale === 'ar' ? 'الكل' : 'All',
      },
    ],
    { onApply: () => setPage(1) },
  )

  const bookingsQuery = useQuery({
    queryKey: ['bookings', filters.applied, search, page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Booking>>('/bookings', {
        params: {
          status: filters.applied.status || undefined,
          customer_id: filters.applied.customer_id || undefined,
          needs_invoice: filters.applied.needs_invoice || undefined,
          search: search || undefined,
          page,
          per_page: 20,
        },
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
        currency: draftCurrency,
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
      setShowErrors(false)
      setError('')
      setItems([emptyItem(draftCurrency)])
      await queryClient.invalidateQueries({ queryKey: ['bookings'] })
      setViewId(booking.id)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const startCreate = () => {
    setError('')
    setShowErrors(false)
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
      setItems([emptyItem(draftCurrency)])
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
    setShowErrors(false)
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
      (booking.items ?? []).map((item) => withUid({
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

  const duplicateItem = (index: number) => {
    setItems((current) => {
      const source = current[index]
      if (!source) return current
      return [...current.slice(0, index + 1), { ...source, uid: withUid(source).uid }, ...current.slice(index + 1)]
    })
  }

  const closeForm = () => {
    setOpen(false)
    setEditTarget(null)
    setShowErrors(false)
  }

  const validation = useMemo(() => validateBooking(form, items, locale === 'ar'), [form, items, locale])

  const submit = () => {
    if (create.isPending) return
    if (!validation.valid) {
      setShowErrors(true)
      focusField(validation.missing[0].target)
      return
    }
    create.mutate()
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
          <FilterControl state={filters} />
        </div>

        {bookingsQuery.isLoading ? <Loading /> : null}
        {bookingsQuery.isError ? (
          <ErrorState message={errorMessage(bookingsQuery.error)} onRetry={() => void bookingsQuery.refetch()} />
        ) : null}
        {bookingsQuery.data && bookings.length === 0 ? <EmptyState /> : null}
        {bookings.length > 0 ? (
          <>
          <MobileCards items={bookings} getKey={(booking) => booking.id} label={t('bookings')}>
            {(booking) => (
              <>
                <div className="flex items-start justify-between gap-3">
                  <button type="button" className="text-medium whitespace-nowrap font-semibold text-brand-1-accent underline" onClick={() => setViewId(booking.id)}>
                    {booking.number}
                  </button>
                  <span>
                    <Badge tone={statusTone(booking.status)}>{statusLabel(booking.status, locale)}</Badge>
                    <AiBadge via={booking.created_via} />
                  </span>
                </div>
                <p className="text-medium font-medium">{booking.customer?.name_ar ?? booking.customer?.name_en ?? '—'}</p>
                <dl className="grid grid-cols-2 gap-3">
                  <CardField label={t('startDate')}>{formatDate(booking.start_date, locale)}</CardField>
                  <CardField label={t('pax')}>{booking.pax_adults + booking.pax_children + booking.pax_infants}</CardField>
                  <CardField label={t('total')}>{formatMoney(booking.total_sell_minor, booking.currency, locale)}</CardField>
                  <CardField label={t('margin')}>
                    <span className={booking.margin_minor < 0 ? 'text-warning-text' : undefined}>{formatMoney(booking.margin_minor, booking.currency, locale)}</span>
                  </CardField>
                </dl>
                <div className="flex gap-2">
                  <Button variant="secondary" onClick={() => setViewId(booking.id)}>
                    {locale === 'ar' ? 'عرض' : 'View'}
                  </Button>
                  {['draft', 'quoted'].includes(booking.status) && can('bookings.update') ? (
                    <Button variant="secondary" onClick={() => startEdit(booking)}>
                      {t('edit')}
                    </Button>
                  ) : null}
                </div>
              </>
            )}
          </MobileCards>
          <TableOnly>
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
                <Th className="w-24 text-end">{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {bookings.map((booking) => (
                <tr key={booking.id}>
                  <Td className="whitespace-nowrap font-medium">
                    <button type="button" className="font-medium text-brand-1-accent hover:underline" onClick={() => setViewId(booking.id)}>
                      {booking.number}
                    </button>
                    <AiBadge via={booking.created_via} />
                  </Td>
                  <Td>{booking.customer?.name_ar ?? booking.customer?.name_en ?? '—'}</Td>
                  <Td>{formatDate(booking.start_date, locale)}</Td>
                  <Td className="tabular">{booking.pax_adults + booking.pax_children + booking.pax_infants}</Td>
                  <Td>
                    <Badge tone={statusTone(booking.status)}>{statusLabel(booking.status, locale)}</Badge>
                  </Td>
                  <Td className="text-end tabular">
                    {formatMoney(booking.total_sell_minor, booking.currency, locale)}
                  </Td>
                  <Td className={`text-end tabular${booking.margin_minor < 0 ? ' text-warning-text' : ''}`}>
                    {formatMoney(booking.margin_minor, booking.currency, locale)}
                  </Td>
                  <Td className="w-24 text-end">
                    <Button
                      variant="ghost"
                      disabled={!(['draft', 'quoted'].includes(booking.status) && can('bookings.update'))}
                      onClick={() => startEdit(booking)}
                    >
                      {t('edit')}
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          </TableOnly>
          </>
        ) : null}
        <Pagination
          page={bookingsQuery.data?.meta.current_page ?? 1}
          lastPage={bookingsQuery.data?.meta.last_page ?? 1}
          onChange={setPage}
          total={bookingsQuery.data?.meta.total}
          perPage={bookingsQuery.data?.meta.per_page}
        />
      </Card>

      <BookingDetails bookingId={viewId} onClose={() => setViewId(null)} />

      {open ? (
        <BookingFormShell
          title={editTarget ? `${t('edit')} ${editTarget.number}` : t('newBooking')}
          onClose={closeForm}
          escapeDisabled={quickCustomerOpen}
          footer={
            <BookingSummary
              currency={draftCurrency}
              sell={totals.sell}
              tax={totals.tax}
              margin={totals.margin}
              taxEnabled={taxEnabled}
              missing={validation.missing}
              error={error}
              actions={
                <>
                  <Button variant="secondary" onClick={closeForm}>{t('cancel')}</Button>
                  <Button disabled={create.isPending} onClick={submit}>{create.isPending ? t('saving') : t('save')}</Button>
                </>
              }
            />
          }
        >
          <FormSection id="bf-trip" title={locale === 'ar' ? 'العميل والرحلة' : 'Customer and trip'}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
              <div className="min-w-0 sm:col-span-2" id="bf-customer">
                <Field label={t('customer')} required error={showErrors ? validation.errors.customer : undefined}>
                  <PartyPicker
                    kind="customer"
                    value={form.customer_id}
                    onChange={(customer_id) => setForm({ ...form, customer_id })}
                    placeholder={t('selectCustomer')}
                  />
                </Field>
                {!editTarget && can('customers.create') ? (
                  <Button variant="ghost" icon="plus" className="mt-2 px-2" onClick={() => setQuickCustomerOpen(true)}>
                    {locale === 'ar' ? 'إضافة عميل جديد دون مغادرة الحجز' : 'Add customer without leaving booking'}
                  </Button>
                ) : null}
              </div>
              {!editTarget ? (
                <div className="min-w-0 sm:col-span-2">
                  <Field label={locale === 'ar' ? 'موعد برنامج سياحي (اختياري)' : 'Tour departure (optional)'}>
                    <Select value={form.tour_departure_id} onChange={(event) => selectDeparture(event.target.value)}>
                      <option value="">{locale === 'ar' ? 'حجز مستقل / خدمة منفصلة' : 'Independent booking / standalone service'}</option>
                      {departureOptions.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
                    </Select>
                  </Field>
                </div>
              ) : null}
              <div className="min-w-0">
                <Field label={t('startDate')} required error={showErrors ? validation.errors.start_date : undefined}>
                  <DateField id="bf-start-date" value={form.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value })} dir="ltr" aria-invalid={showErrors && Boolean(validation.errors.start_date)} />
                </Field>
              </div>
              <div className="min-w-0">
                <Field label={t('endDate')} error={showErrors ? validation.errors.end_date : undefined}>
                  <DateField id="bf-end-date" value={form.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} dir="ltr" aria-invalid={showErrors && Boolean(validation.errors.end_date)} />
                </Field>
              </div>
              <div className="min-w-0">
                <Field label={t('currency')} required>
                  <Select value={draftCurrency} onChange={(event) => setForm({ ...form, currency: event.target.value })}>
                    {currencyCodes.map((code) => (
                      <option key={code} value={code}>{code}</option>
                    ))}
                  </Select>
                </Field>
              </div>
              {draftCurrency !== baseCurrency ? (
                <div className="min-w-0">
                  <Field label={locale === 'ar' ? 'سعر الصرف (اختياري)' : 'Exchange rate (optional)'}>
                    <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} placeholder={locale === 'ar' ? 'تلقائي حسب جدول الأسعار' : 'Automatic from the rate table'} inputMode="decimal" dir="ltr" />
                  </Field>
                </div>
              ) : null}
              <div className="min-w-0 sm:col-span-2 md:col-span-4">
                <Field label={locale === 'ar' ? 'ملاحظات' : 'Notes'}>
                  <Input value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
                </Field>
              </div>
            </div>
          </FormSection>

          <FormSection id="bf-travellers" title={locale === 'ar' ? 'المسافرون' : 'Travellers'}>
            <div className="grid max-w-xl grid-cols-3 gap-4">
              <div className="min-w-0">
                <Field label={locale === 'ar' ? 'بالغون' : 'Adults'} required error={showErrors ? validation.errors.pax_adults : undefined}>
                  <Input id="bf-adults" type="number" min="1" value={form.pax_adults} onChange={(event) => setForm({ ...form, pax_adults: event.target.value })} dir="ltr" aria-invalid={showErrors && Boolean(validation.errors.pax_adults)} />
                </Field>
              </div>
              <div className="min-w-0">
                <Field label={locale === 'ar' ? 'أطفال' : 'Children'} error={showErrors ? validation.errors.pax_children : undefined}>
                  <Input id="bf-children" type="number" min="0" value={form.pax_children} onChange={(event) => setForm({ ...form, pax_children: event.target.value })} dir="ltr" aria-invalid={showErrors && Boolean(validation.errors.pax_children)} />
                </Field>
              </div>
              <div className="min-w-0">
                <Field label={locale === 'ar' ? 'رضع' : 'Infants'} error={showErrors ? validation.errors.pax_infants : undefined}>
                  <Input id="bf-infants" type="number" min="0" value={form.pax_infants} onChange={(event) => setForm({ ...form, pax_infants: event.target.value })} dir="ltr" aria-invalid={showErrors && Boolean(validation.errors.pax_infants)} />
                </Field>
              </div>
            </div>
          </FormSection>

          <FormSection id="bf-services" title={t('items')} hint={locale === 'ar' ? 'كل خدمة بطاقة مستقلة بتكلفتها وسعرها وهامشها.' : 'Each service is its own card with its cost, price and margin.'}>
            <ul className="flex list-none flex-col gap-3 p-0" aria-label={t('items')}>
              {items.map((item, index) => (
                <ServiceCard
                  key={item.uid}
                  item={item}
                  index={index}
                  amounts={itemAmounts(item)}
                  errors={validation.errors.lines[index] ?? {}}
                  showErrors={showErrors}
                  taxEnabled={taxEnabled}
                  canRemove={items.length > 1}
                  onChange={(patch) => updateItem(index, patch)}
                  onDuplicate={() => duplicateItem(index)}
                  onRemove={() => setItems((current) => current.filter((_, i) => i !== index))}
                />
              ))}
            </ul>
            {showErrors && validation.errors.items ? <p className="text-small mt-2 text-danger-text">{validation.errors.items}</p> : null}
            <Button id="bf-add-line" variant="secondary" icon="plus" className="mt-3 w-full sm:w-auto" onClick={() => setItems((current) => [...current, emptyItem(draftCurrency)])}>
              {t('addLine')}
            </Button>
          </FormSection>
        </BookingFormShell>
      ) : null}
      <QuickCreateCustomer open={quickCustomerOpen} onClose={() => setQuickCustomerOpen(false)}
        onCreated={(customer) => { setForm((current) => ({ ...current, customer_id: customer.id })); setQuickCustomerOpen(false) }} />
    </div>
  )
}
