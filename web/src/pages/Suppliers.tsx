import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { supplierCategories, supplierCategoryLabel } from '../lib/labels'
import type { Paginated, Supplier } from '../lib/types'
import { Badge, Button, Card, ErrorState, Field, Input, Modal, PageHeader, Pagination, Select, Td } from '../components/ui'
import { ListEmpty, ListSkeleton, ListTable, ListTh } from '../components/ListKit'
import { FilterControl, useFilters } from '../components/FilterDrawer'
import { useActiveCurrencyCodes } from '../lib/useCurrencies'
import { localizedName } from '../lib/names'
import { AiBadge } from '../components/AiBadge'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'

const categories = supplierCategories

export default function Suppliers() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const urlSearch = searchParams.get('search') ?? ''
  const [search, setSearch] = useState(urlSearch)
  const [page, setPage] = useState(1)
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

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const currencyCodes = useActiveCurrencyCodes()
  const filters = useFilters(
    [
      {
        key: 'is_active',
        label: t('status'),
        type: 'select',
        options: [
          { value: '1', label: locale === 'ar' ? 'نشط' : 'Active' },
          { value: '0', label: locale === 'ar' ? 'موقوف' : 'Inactive' },
        ],
        allLabel: locale === 'ar' ? 'الكل' : 'All',
      },
      {
        key: 'category',
        label: t('category'),
        type: 'select',
        options: categories.map((category) => ({ value: category.value, label: supplierCategoryLabel(category.value, locale) })),
        allLabel: locale === 'ar' ? 'كل الفئات' : 'All categories',
      },
      {
        key: 'settlement_currency',
        label: t('settlementCurrency'),
        type: 'select',
        options: currencyCodes.map((code) => ({ value: code, label: code })),
        allLabel: locale === 'ar' ? 'كل العملات' : 'All currencies',
      },
    ],
    { onApply: () => setPage(1) },
  )

  const query = useQuery({
    queryKey: ['suppliers', search, page, filters.applied, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Supplier>>('/suppliers', {
        params: { search: search || undefined, is_active: filters.applied.is_active || undefined, category: filters.applied.category || undefined, settlement_currency: filters.applied.settlement_currency || undefined, page, per_page: 20 },
      })
      return data
    },
  })

  const suppliers = query.data?.data ?? []

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

  const inactiveLabel = locale === 'ar' ? 'موقوف' : 'Inactive'
  const categoryLabel = (value: string) => supplierCategoryLabel(value, locale)

  return (
    <div>
      <PageHeader
        title={t('suppliers')}
        subtitle={locale === 'ar' ? 'الموردون حسب النشاط وجهات الاتصال' : 'Suppliers by category and contact details'}
        icon="truck"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newSupplier')}</Button>}
      />

      <Card>
        <div className="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
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
        {query.isLoading ? <ListSkeleton columns={4} /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && suppliers.length === 0 ? <ListEmpty filtered={search !== '' || filters.count > 0} onClear={() => { setSearch(''); filters.clearAll(); setPage(1) }} /> : null}
        {suppliers.length > 0 ? (
          <>
            <MobileCards items={suppliers} getKey={(supplier) => supplier.id} label={t('suppliers')}>
              {(supplier) => (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <Link to={`/suppliers/${supplier.id}`} className="text-medium font-semibold text-brand-1-accent underline">
                      {localizedName(supplier, locale)}
                    </Link>
                    <span>
                      {supplier.is_active ? null : <Badge tone="neutral">{inactiveLabel}</Badge>}
                      <AiBadge via={supplier.created_via} />
                    </span>
                  </div>
                  <dl className="grid grid-cols-2 gap-3">
                    <CardField label={t('category')}>{categoryLabel(supplier.category)}</CardField>
                    <CardField label={t('currency')}>{supplier.settlement_currency}</CardField>
                    <CardField label={t('phone')}>
                      <span dir="ltr">{supplier.phone ?? '—'}</span>
                    </CardField>
                  </dl>
                </>
              )}
            </MobileCards>
            <TableOnly>
              <ListTable label={t('suppliers')}>
                <thead>
                  <tr>
                    <ListTh>{t('name')}</ListTh>
                    <ListTh>{t('category')}</ListTh>
                    <ListTh>{t('phone')}</ListTh>
                    <ListTh>{t('currency')}</ListTh>
                  </tr>
                </thead>
                <tbody>
                  {suppliers.map((supplier) => (
                    <tr key={supplier.id}>
                      <Td>
                        <Link to={`/suppliers/${supplier.id}`} className="font-medium text-brand-1-accent hover:underline">
                          {localizedName(supplier, locale)}
                        </Link>
                        {supplier.is_active ? null : <Badge tone="neutral">{inactiveLabel}</Badge>}
                        <AiBadge via={supplier.created_via} />
                      </Td>
                      <Td>{categoryLabel(supplier.category)}</Td>
                      <Td dir="ltr">{supplier.phone ?? '—'}</Td>
                      <Td>{supplier.settlement_currency}</Td>
                    </tr>
                  ))}
                </tbody>
              </ListTable>
            </TableOnly>
          </>
        ) : null}
        <Pagination page={query.data?.meta.current_page ?? 1} lastPage={query.data?.meta.last_page ?? 1} onChange={setPage} total={query.data?.meta.total} perPage={query.data?.meta.per_page} />
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
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
