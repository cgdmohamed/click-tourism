import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { toDecimal, toMinor, todayISO } from '../lib/format'
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
  code: '', name: '', category: 'domestic', execution_type: 'fixed',
  destination: '', description: '', currency: 'EGP', base_price: '', estimated_cost: '',
}
export default function TourPackages() {
  const { locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => locale === 'ar' ? ar : en
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm })
  const [departureFor, setDepartureFor] = useState<TourPackage | null>(null)
  const [departure, setDeparture] = useState({ starts_on: todayISO(), ends_on: '', capacity: '40' })
  const [error, setError] = useState('')

  const packagesQuery = useQuery({
    queryKey: ['tour-packages', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: TourPackage[] }>('/tour-packages')
      return data.data
    },
  })
  const packages = packagesQuery.data ?? []
  const save = useMutation({
    mutationFn: async () => {
      await api.post('/tour-packages', {
        code: form.code.trim(), name: form.name.trim(), category: form.category,
        execution_type: form.execution_type, destination: form.destination || null,
        description: form.description || null, currency: form.currency,
        base_price_minor: toMinor(form.base_price || '0', form.currency),
        estimated_cost_minor: toMinor(form.estimated_cost || '0', form.currency),
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
        {packagesQuery.isLoading ? <Loading /> : null}
        {packagesQuery.isError ? <ErrorState message={errorMessage(packagesQuery.error)} onRetry={() => void packagesQuery.refetch()} /> : null}
        {!packagesQuery.isLoading && packages.length === 0 ? <EmptyState /> : null}
        {packages.length > 0 ? (
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
                  <Td><strong>{item.name}</strong><div className="text-small text-text-subtle">{item.code}</div></Td>
                  <Td>
                    {categories.find((category) => category[0] === item.category)?.[locale === 'ar' ? 1 : 2] ?? item.category}
                    <div className="text-small text-text-subtle">{item.execution_type}</div>
                  </Td>
                  <Td>{item.destination ?? '—'}</Td>
                  <Td className="text-end">{toDecimal(item.base_price_minor, item.currency)} {item.currency}</Td>
                  <Td>
                    {(item.departures ?? []).length === 0 ? <span>—</span> : (
                      <div className="space-y-1">
                        {(item.departures ?? []).map((d) => (
                          <div key={d.id} className="text-small">
                            {d.starts_on} · {d.status === 'open' ? (
                              <Badge tone="positive">{d.available_seats ?? '∞'} {L('متاح', 'left')}</Badge>
                            ) : <Badge tone="neutral">{L('مغلق', 'Closed')}</Badge>}
                          </div>
                        ))}
                      </div>
                    )}
                  </Td>
                  <Td>{item.execution_type === 'fixed' && can('bookings.create') ? (
                    <Button variant="secondary" onClick={() => {
                      setDepartureFor(item)
                      setError('')
                      setDeparture({ starts_on: todayISO(), ends_on: '', capacity: '40' })
                    }}>{L('إضافة موعد', 'Add departure')}</Button>
                  ) : null}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
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
          <Button disabled={save.isPending || !form.code.trim() || !form.name.trim()} onClick={() => save.mutate()}>
            {L('حفظ البرنامج', 'Save program')}
          </Button>
        </>}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={L('كود البرنامج', 'Program code')} required><Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })}/></Field>
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
          <Field label={L('العملة', 'Currency')}><Select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
            <option value="EGP">EGP</option><option value="SAR">SAR</option><option value="USD">USD</option>
          </Select></Field>
          <Field label={L('السعر المبدئي للفرد', 'Base price per person')}><Input value={form.base_price} onChange={(e) => setForm({ ...form, base_price: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <Field label={L('التكلفة التقديرية للفرد', 'Estimated cost per person')}><Input value={form.estimated_cost} onChange={(e) => setForm({ ...form, estimated_cost: e.target.value })} inputMode="decimal" dir="ltr"/></Field>
          <div className="sm:col-span-2"><Field label={L('الوصف', 'Description')}><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}/></Field></div>
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
          <Field label={L('تاريخ البداية', 'Start date')} required><Input type="date" dir="ltr" value={departure.starts_on} onChange={(e) => setDeparture({ ...departure, starts_on: e.target.value })}/></Field>
          <Field label={L('تاريخ النهاية', 'End date')}><Input type="date" dir="ltr" value={departure.ends_on} onChange={(e) => setDeparture({ ...departure, ends_on: e.target.value })}/></Field>
          <Field label={L('عدد المقاعد', 'Seat capacity')} required><Input type="number" min="1" value={departure.capacity} onChange={(e) => setDeparture({ ...departure, capacity: e.target.value })}/></Field>
        </div>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
