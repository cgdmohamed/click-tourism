import { CardField, MobileCards, TableOnly } from '../components/MobileCards'
import { DateField } from '../components/DateField'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { FilterControl, useFilters } from '../components/FilterDrawer'
import { useActiveCurrencyCodes } from '../lib/useCurrencies'
import { resolveCurrency } from '../lib/currencies'
import { useBaseCurrency } from '../lib/useSetting'
import { toMinor, toDecimal, todayISO, formatMoney } from '../lib/format'
import {
  Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading,
  Modal, PageHeader, Select, Table, Td, Th,
} from '../components/ui'

type Departure = {
  id: string; starts_on: string; ends_on: string | null
  capacity: number | null; occupied_seats: number; available_seats: number | null; status: string
}
type TourPackage = {
  id: string; code: string; name: string; category: string
  execution_type: 'fixed' | 'private' | 'standalone'
  destination: string | null; description: string | null; currency: string
  base_price_minor: number; estimated_cost_minor: number
  is_active: boolean; departures: Departure[] | null
}
const categories = [
  ['domestic', 'سياحة داخلية', 'Domestic'],
  ['international', 'سياحة خارجية', 'International'],
  ['umrah', 'عمرة', 'Umrah'],
  ['hajj', 'حج', 'Hajj'],
  ['corporate', 'شركات', 'Corporate'],
  ['day_trip', 'رحلة يوم واحد', 'Day trip'],
  ['other', 'أخرى', 'Other'],
]
const emptyForm = {
  name: '', category: 'domestic', execution_type: 'fixed',
  destination: '', description: '', currency: 'EGP', base_price: '', estimated_cost: '',
}
export default function TourPackages() {
  const currencyCodes = useActiveCurrencyCodes()
  const { locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => locale === 'ar' ? ar : en
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm })
  const baseCurrency = useBaseCurrency()
  const draftCurrency = resolveCurrency(form.currency, currencyCodes, baseCurrency)
  useEffect(() => {
    if (open && form.currency !== draftCurrency) setForm((current) => ({ ...current, currency: draftCurrency }))
  }, [open, form.currency, draftCurrency])
  const [departureFor, setDepartureFor] = useState<TourPackage | null>(null)
  const [departure, setDeparture] = useState({ starts_on: todayISO(), ends_on: '', capacity: '40' })
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<TourPackage | null>(null)
  const [editForm, setEditForm] = useState({ name: '', category: 'domestic', destination: '', description: '', base_price: '', estimated_cost: '' })
  const [editingDeparture, setEditingDeparture] = useState<Departure | null>(null)
  const [departureEdit, setDepartureEdit] = useState({ starts_on: '', ends_on: '', capacity: '' })

  const [search, setSearch] = useState('')
  const filters = useFilters([
    {
      key: 'category',
      label: L('التصنيف', 'Category'),
      type: 'select',
      options: categories.map((category) => ({ value: category[0], label: locale === 'ar' ? category[1] : category[2] })),
      allLabel: L('كل التصنيفات', 'All categories'),
    },
    {
      key: 'execution_type',
      label: L('طريقة التنفيذ', 'Execution model'),
      type: 'select',
      options: [
        { value: 'fixed', label: L('جماعية بمواعيد وسعة', 'Fixed group departures') },
        { value: 'private', label: L('خاصة حسب الطلب', 'Private / custom') },
        { value: 'standalone', label: L('خدمة منفصلة', 'Standalone service') },
      ],
      allLabel: L('كل الطرق', 'All models'),
    },
    {
      key: 'status',
      label: L('الحالة', 'Status'),
      type: 'select',
      options: [
        { value: 'active', label: L('نشط', 'Active') },
        { value: 'inactive', label: L('موقوف', 'Inactive') },
      ],
      allLabel: L('الكل', 'All'),
    },
  ])
  const packagesQuery = useQuery({
    queryKey: ['tour-packages', activeBranch?.id, search, filters.applied],
    queryFn: async () => {
      const { data } = await api.get<{ data: TourPackage[] }>('/tour-packages', {
        params: {
          search: search || undefined,
          category: filters.applied.category || undefined,
          execution_type: filters.applied.execution_type || undefined,
          status: filters.applied.status || undefined,
        },
      })
      return data.data
    },
  })
  const packages = packagesQuery.data ?? []
  const departuresList = (item: TourPackage) => (
    <>
      {(item.departures ?? []).length === 0 ? <span>—</span> : (
                      <div className="space-y-1">
                        {(item.departures ?? []).map((d) => (
                          <div key={d.id} className="text-small">
                            {d.starts_on} · {d.status === 'open' ? (
                              <>
                                <Badge tone="positive">{d.available_seats ?? '∞'} {L('متاح', 'left')}</Badge>
                                {can('bookings.create') && (d.available_seats === null || d.available_seats > 0) ? (
                                  <Link className="ms-2 font-semibold text-brand-1-accent underline" to={`/bookings?departure_id=${d.id}`}>
                                    {L('احجز هذا الموعد', 'Book this departure')}
                                  </Link>
                                ) : null}
                              </>
                            ) : <Badge tone="neutral">{L('مغلق', 'Closed')}</Badge>}
                            {can('bookings.update') ? (
                              <>
                                <button type="button" className="ms-2 font-semibold text-brand-1-accent underline" onClick={() => openDepartureEdit(d)}>{L('تعديل', 'Edit')}</button>
                                <button type="button" className="ms-2 font-semibold text-brand-1-accent underline" onClick={() => toggleDeparture.mutate(d)}>
                                  {d.status === 'open' ? L('إغلاق', 'Close') : L('إعادة فتح', 'Reopen')}
                                </button>
                              </>
                            ) : null}
                          </div>
                        ))}
                      </div>
                    )}
    </>
  )

  const editButtons = (item: TourPackage) => (
    can('bookings.update') ? (
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => openEdit(item)}>{L('تعديل البرنامج', 'Edit program')}</Button>
        <Button variant="secondary" onClick={() => setActive.mutate(item)}>{item.is_active ? L('إيقاف', 'Deactivate') : L('تفعيل', 'Activate')}</Button>
      </div>
    ) : null
  )

  const addDepartureButton = (item: TourPackage) => (
    <>
      {item.execution_type === 'fixed' && can('bookings.create') ? (
                    <Button variant="secondary" onClick={() => {
                      setDepartureFor(item)
                      setError('')
                      setDeparture({ starts_on: todayISO(), ends_on: '', capacity: '40' })
                    }}>{L('إضافة موعد', 'Add departure')}</Button>
                  ) : null}
    </>
  )

  const save = useMutation({
    mutationFn: async () => {
      await api.post('/tour-packages', {
        name: form.name.trim(), category: form.category,
        execution_type: form.execution_type, destination: form.destination || null,
        description: form.description || null, currency: draftCurrency,
        base_price_minor: toMinor(form.base_price || '0', draftCurrency),
        estimated_cost_minor: toMinor(form.estimated_cost || '0', draftCurrency),
      })
    },
    onSuccess: async () => {
      setOpen(false)
      setForm({ ...emptyForm })
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['tour-packages'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['tour-packages'] })
  const openEdit = (item: TourPackage) => {
    setError('')
    setEditing(item)
    setEditForm({
      name: item.name, category: item.category, destination: item.destination ?? '', description: item.description ?? '',
      base_price: toDecimal(item.base_price_minor, item.currency), estimated_cost: toDecimal(item.estimated_cost_minor, item.currency),
    })
  }
  const openDepartureEdit = (d: Departure) => {
    setError('')
    setEditingDeparture(d)
    setDepartureEdit({ starts_on: d.starts_on, ends_on: d.ends_on ?? '', capacity: String(d.capacity ?? '') })
  }
  const saveEdit = useMutation({
    mutationFn: async () => {
      if (!editing) return
      await api.patch(`/tour-packages/${editing.id}`, {
        name: editForm.name.trim(), category: editForm.category,
        destination: editForm.destination || null, description: editForm.description || null,
        base_price_minor: toMinor(editForm.base_price || '0', editing.currency),
        estimated_cost_minor: toMinor(editForm.estimated_cost || '0', editing.currency),
      })
    },
    onSuccess: async () => { setEditing(null); setError(''); await refresh() },
    onError: (cause) => setError(errorMessage(cause)),
  })
  const setActive = useMutation({
    mutationFn: async (item: TourPackage) => { await api.patch(`/tour-packages/${item.id}`, { is_active: !item.is_active }) },
    onSuccess: refresh,
    onError: (cause) => setError(errorMessage(cause)),
  })
  const saveDepartureEdit = useMutation({
    mutationFn: async () => {
      if (!editingDeparture) return
      await api.patch(`/tour-departures/${editingDeparture.id}`, {
        starts_on: departureEdit.starts_on, ends_on: departureEdit.ends_on || null, capacity: Number(departureEdit.capacity),
      })
    },
    onSuccess: async () => { setEditingDeparture(null); setError(''); await refresh() },
    onError: (cause) => setError(errorMessage(cause)),
  })
  const toggleDeparture = useMutation({
    mutationFn: async (d: Departure) => { await api.post(`/tour-departures/${d.id}/${d.status === 'open' ? 'close' : 'reopen'}`) },
    onSuccess: refresh,
    onError: (cause) => setError(errorMessage(cause)),
  })
  const addDeparture = useMutation({
    mutationFn: async () => {
      if (!departureFor) return
      await api.post(`/tour-packages/${departureFor.id}/departures`, {
        starts_on: departure.starts_on,
        ends_on: departure.ends_on || null,
        capacity: Number(departure.capacity),
      })
    },
    onSuccess: async () => {
      setDepartureFor(null)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['tour-packages'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <div>
      <PageHeader
        title={L('البرامج والرحلات السياحية', 'Tours & Packages')}
        subtitle={L('برامج داخلية وخارجية وعمرة وحج ورحلات خاصة وجماعية وخدمات منفصلة', 'Domestic, international, private, group, pilgrimage and standalone programs')}
        icon="plane"
        actions={can('bookings.create') ? (
          <Button icon="plus" onClick={() => { setError(''); setOpen(true) }}>{L('برنامج جديد', 'New program')}</Button>
        ) : null}
      />
      <Card>
        <div className="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={L('بحث بالكود أو الاسم أو الوجهة', 'Search code, name or destination')} aria-label={L('بحث', 'Search')} className="max-w-xs" />
          <FilterControl state={filters} />
        </div>
        {packagesQuery.isLoading ? <Loading /> : null}
        {packagesQuery.isError ? <ErrorState message={errorMessage(packagesQuery.error)} onRetry={() => void packagesQuery.refetch()} /> : null}
        {!packagesQuery.isLoading && !packagesQuery.isError && packages.length === 0 ? (search !== '' || filters.count > 0 ? <EmptyState variant="filtered" onClear={() => { setSearch(''); filters.clearAll() }} /> : <EmptyState />) : null}
        {packages.length > 0 ? (
          <>
          <MobileCards items={packages} getKey={(item) => item.id} label={L('البرامج', 'Programs')}>
            {(item) => (
              <>
                <div>
                  <p className="text-medium font-semibold">{item.name}</p>
                  <p className="text-small text-text-subtle">{item.code}</p>
                </div>
                <dl className="grid grid-cols-2 gap-3">
                  <CardField label={L('النوع', 'Type')}>{categories.find((category) => category[0] === item.category)?.[locale === 'ar' ? 1 : 2] ?? item.category}</CardField>
                  <CardField label={L('الوجهة', 'Destination')}>{item.destination ?? '—'}</CardField>
                  <CardField label={L('السعر المبدئي', 'Base price')}>{formatMoney(item.base_price_minor, item.currency, locale)}</CardField>
                </dl>
                <div>
                  <p className="text-small text-text-subtle">{L('المواعيد والسعة', 'Departures / seats')}</p>
                  {departuresList(item)}
                </div>
                {addDepartureButton(item)}
                {editButtons(item)}
              </>
            )}
          </MobileCards>
          <TableOnly>
          <Table>
            <thead>
              <tr>
                <Th>{L('البرنامج', 'Program')}</Th>
                <Th>{L('النوع', 'Type')}</Th>
                <Th>{L('الوجهة', 'Destination')}</Th>
                <Th className="text-end">{L('السعر المبدئي', 'Base price')}</Th>
                <Th>{L('المواعيد والسعة', 'Departures / seats')}</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {packages.map((item) => (
                <tr key={item.id}>
                  <Td><strong>{item.name}</strong>{item.is_active ? null : <> <Badge tone="neutral">{L('موقوف', 'Inactive')}</Badge></>}<div className="text-small text-text-subtle">{item.code}</div></Td>
                  <Td>
                    {categories.find((category) => category[0] === item.category)?.[locale === 'ar' ? 1 : 2] ?? item.category}
                    <div className="text-small text-text-subtle">{item.execution_type}</div>
                  </Td>
                  <Td>{item.destination ?? '—'}</Td>
                  <Td className="whitespace-nowrap text-end">{formatMoney(item.base_price_minor, item.currency, locale)}</Td>
                  <Td>{departuresList(item)}</Td>
                  <Td><div className="space-y-2">{addDepartureButton(item)}{editButtons(item)}</div></Td>
                </tr>
              ))}
            </tbody>
          </Table>
          </TableOnly>
          </>
        ) : null}
      </Card>
      <p className="mt-3 text-small text-text-subtle">
        {L('الحجوزات والفواتير والتحصيلات تظل في وحداتها الحالية، ويمكن ربط الحجوزات بمواعيد البرامج الجماعية.', 'Bookings, invoices and receipts stay in their existing modules; bookings can be linked to scheduled group departures.')}
        {' '}<Link to="/bookings" className="text-brand-1-accent underline">{L('فتح الحجوزات', 'Open bookings')}</Link>
      </p>
      <Modal
        open={open}
        title={L('إنشاء برنامج سياحي', 'Create tour package')}
        onClose={() => setOpen(false)}
        footer={<>
          <Button variant="secondary" onClick={() => setOpen(false)}>{L('إلغاء', 'Cancel')}</Button>
          <Button disabled={save.isPending || !form.name.trim()} onClick={() => save.mutate()}>
            {L('حفظ البرنامج', 'Save program')}
          </Button>
        </>}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="text-small text-text-subtle sm:col-span-2">{L('كود البرنامج يتولد تلقائيًا عند الحفظ ولا يحتاج إدخالًا.', 'The program code is generated automatically when saved.')}</div>
          <Field label={L('اسم البرنامج', 'Program name')} required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}/></Field>
          <Field label={L('التصنيف', 'Category')}><Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
            {categories.map((c) => <option key={c[0]} value={c[0]}>{locale === 'ar' ? c[1] : c[2]}</option>)}
          </Select></Field>
          <Field label={L('طريقة التنفيذ', 'Execution model')}>
            <Select value={form.execution_type} onChange={(e) => setForm({ ...form, execution_type: e.target.value })}>
              <option value="fixed">{L('جماعية بمواعيد وسعة', 'Fixed group departures')}</option>
              <option value="private">{L('خاصة حسب الطلب', 'Private / custom')}</option>
              <option value="standalone">{L('خدمة منفصلة', 'Standalone service')}</option>
            </Select>
          </Field>
          <Field label={L('الوجهة', 'Destination')}><Input value={form.destination} onChange={(e) => setForm({ ...form, destination: e.target.value })}/></Field>
          <Field label={L('العملة', 'Currency')}><Select value={draftCurrency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
            {currencyCodes.map((code) => <option key={code} value={code}>{code}</option>)}
          </Select></Field>
          <Field label={L('السعر المبدئي للفرد', 'Base price per person')}><Input value={form.base_price} onChange={(e) => setForm({ ...form, base_price: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <Field label={L('التكلفة التقديرية للفرد', 'Estimated cost per person')}><Input value={form.estimated_cost} onChange={(e) => setForm({ ...form, estimated_cost: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <div className="sm:col-span-2"><Field label={L('الوصف', 'Description')}><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}/></Field></div>
        </div>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
      <Modal
        open={editing !== null}
        title={L('تعديل البرنامج', 'Edit program')}
        onClose={() => setEditing(null)}
        footer={<>
          <Button variant="secondary" onClick={() => setEditing(null)}>{L('إلغاء', 'Cancel')}</Button>
          <Button disabled={saveEdit.isPending || !editForm.name.trim()} onClick={() => saveEdit.mutate()}>{L('حفظ التعديل', 'Save changes')}</Button>
        </>}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="text-small text-text-subtle sm:col-span-2">{L('السعر الجديد يسري على الحجوزات الجديدة فقط، والحجوزات القائمة تحتفظ بسعرها.', 'A new price applies to new bookings only; existing bookings keep theirs.')}</div>
          <Field label={L('اسم البرنامج', 'Program name')} required><Input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}/></Field>
          <Field label={L('التصنيف', 'Category')}><Select value={editForm.category} onChange={(e) => setEditForm({ ...editForm, category: e.target.value })}>
            {categories.map((c) => <option key={c[0]} value={c[0]}>{locale === 'ar' ? c[1] : c[2]}</option>)}
          </Select></Field>
          <Field label={L('الوجهة', 'Destination')}><Input value={editForm.destination} onChange={(e) => setEditForm({ ...editForm, destination: e.target.value })}/></Field>
          <Field label={L('السعر المبدئي للفرد', 'Base price per person')}><Input value={editForm.base_price} onChange={(e) => setEditForm({ ...editForm, base_price: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <Field label={L('التكلفة التقديرية للفرد', 'Estimated cost per person')}><Input value={editForm.estimated_cost} onChange={(e) => setEditForm({ ...editForm, estimated_cost: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <div className="sm:col-span-2"><Field label={L('الوصف', 'Description')}><Input value={editForm.description} onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}/></Field></div>
        </div>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
      <Modal
        open={editingDeparture !== null}
        title={L('تعديل الموعد', 'Edit departure')}
        onClose={() => setEditingDeparture(null)}
        footer={<>
          <Button variant="secondary" onClick={() => setEditingDeparture(null)}>{L('إلغاء', 'Cancel')}</Button>
          <Button disabled={saveDepartureEdit.isPending || Number(departureEdit.capacity) < 1} onClick={() => saveDepartureEdit.mutate()}>{L('حفظ التعديل', 'Save changes')}</Button>
        </>}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="text-small text-text-subtle sm:col-span-2">{L('لا يمكن تقليل المقاعد عن المحجوز، ولا تغيير تاريخ البداية بعد وجود حجوزات.', 'Seats cannot drop below those taken, and the start date is locked once bookings exist.')}</div>
          <Field label={L('تاريخ البداية', 'Start date')} required><DateField dir="ltr" value={departureEdit.starts_on} onChange={(e) => setDepartureEdit({ ...departureEdit, starts_on: e.target.value })}/></Field>
          <Field label={L('تاريخ النهاية', 'End date')}><DateField dir="ltr" value={departureEdit.ends_on} onChange={(e) => setDepartureEdit({ ...departureEdit, ends_on: e.target.value })}/></Field>
          <Field label={L('عدد المقاعد', 'Seat capacity')} required><Input type="number" min="1" value={departureEdit.capacity} onChange={(e) => setDepartureEdit({ ...departureEdit, capacity: e.target.value })}/></Field>
        </div>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
      <Modal
        open={departureFor !== null}
        title={L('موعد تنفيذ البرنامج', 'Scheduled departure')}
        onClose={() => setDepartureFor(null)}
        footer={<>
          <Button variant="secondary" onClick={() => setDepartureFor(null)}>{L('إلغاء', 'Cancel')}</Button>
          <Button disabled={addDeparture.isPending || Number(departure.capacity) < 1} onClick={() => addDeparture.mutate()}>{L('حفظ الموعد', 'Save departure')}</Button>
        </>}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={L('تاريخ البداية', 'Start date')} required><DateField dir="ltr" value={departure.starts_on} onChange={(e) => setDeparture({ ...departure, starts_on: e.target.value })}/></Field>
          <Field label={L('تاريخ النهاية', 'End date')}><DateField dir="ltr" value={departure.ends_on} onChange={(e) => setDeparture({ ...departure, ends_on: e.target.value })}/></Field>
          <Field label={L('عدد المقاعد', 'Seat capacity')} required><Input type="number" min="1" value={departure.capacity} onChange={(e) => setDeparture({ ...departure, capacity: e.target.value })}/></Field>
        </div>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
