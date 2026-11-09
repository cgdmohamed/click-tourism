import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Supplier } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'

const categories = [
  { value: 'airline', ar: 'شركة طيران', en: 'Airline' },
  { value: 'hotel_eg', ar: 'فندق داخل مصر', en: 'Hotel (Egypt)' },
  { value: 'hotel_sa', ar: 'فندق داخل السعودية', en: 'Hotel (Saudi)' },
  { value: 'transport', ar: 'شركة نقل', en: 'Transport' },
  { value: 'visa', ar: 'شركة تأشيرات', en: 'Visa' },
  { value: 'agent', ar: 'وكيل', en: 'Agent' },
  { value: 'other', ar: 'أخرى', en: 'Other' },
]

export default function Suppliers() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({
    category: 'other',
    name_ar: '',
    name_en: '',
    phone: '',
    email: '',
    settlement_currency: 'EGP',
  })

  const query = useQuery({
    queryKey: ['suppliers', search, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Supplier[] }>('/suppliers', { params: { search: search || undefined } })
      return data.data
    },
  })

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const { data } = await api.post<{ data: Supplier }>('/suppliers', { ...form, branch_id: activeBranch.id })
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm({ category: 'other', name_ar: '', name_en: '', phone: '', email: '', settlement_currency: 'EGP' })
      await queryClient.invalidateQueries({ queryKey: ['suppliers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const categoryLabel = (value: string) => {
    const found = categories.find((category) => category.value === value)
    return found ? (locale === 'ar' ? found.ar : found.en) : value
  }

  return (
    <div>
      <PageHeader
        title={t('suppliers')}
        subtitle={locale === 'ar' ? 'الموردون حسب النشاط وأرصدتهم' : 'Suppliers by category and balances'}
        actions={<Button onClick={() => setOpen(true)}>{t('newSupplier')}</Button>}
      />

      <Card>
        <div className="flex items-center gap-3 border-b border-line px-5 py-4">
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('search')} className="max-w-xs" />
        </div>
        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data?.length === 0 ? <EmptyState /> : null}
        {query.data && query.data.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('name')}</Th>
                <Th>{t('category')}</Th>
                <Th>{t('phone')}</Th>
                <Th>{t('currency')}</Th>
                <Th>{t('status')}</Th>
              </tr>
            </thead>
            <tbody>
              {query.data.map((supplier) => (
                <tr key={supplier.id}>
                  <Td>
                    <p className="font-medium">{locale === 'ar' ? supplier.name_ar : supplier.name_en}</p>
                    <p className="text-xs text-muted">{locale === 'ar' ? supplier.name_en : supplier.name_ar}</p>
                  </Td>
                  <Td>{categoryLabel(supplier.category)}</Td>
                  <Td dir="ltr">{supplier.phone ?? '—'}</Td>
                  <Td>{supplier.settlement_currency}</Td>
                  <Td>
                    <Badge tone={supplier.is_active ? 'positive' : 'neutral'}>
                      {supplier.is_active ? (locale === 'ar' ? 'نشط' : 'Active') : locale === 'ar' ? 'موقوف' : 'Inactive'}
                    </Badge>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={t('newSupplier')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={create.isPending} onClick={() => create.mutate()}>
              {create.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('category')}>
            <Select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
              {categories.map((category) => (
                <option key={category.value} value={category.value}>
                  {locale === 'ar' ? category.ar : category.en}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('currency')}>
            <Select value={form.settlement_currency} onChange={(event) => setForm({ ...form, settlement_currency: event.target.value })}>
              <option value="EGP">EGP</option>
              <option value="SAR">SAR</option>
              <option value="USD">USD</option>
            </Select>
          </Field>
          <Field label={t('nameAr')} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} />
          </Field>
          <Field label={t('nameEn')} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('phone')}>
            <Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('email')}>
            <Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
      </Modal>
    </div>
  )
}
