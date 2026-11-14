import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { toMinor } from '../lib/format'
import type { Customer, Paginated } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Pagination, Select, Table, Td, Th } from '../components/ui'
import { localizedName } from '../lib/names'
import { AiBadge } from '../components/AiBadge'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'

export default function Customers() {
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
    type: 'individual' as Customer['type'],
    name_ar: '',
    name_en: '',
    phone: '',
    email: '',
    tax_id: '',
    credit_limit: '0',
  })

  useEffect(() => {
    setSearch(urlSearch)
    setPage(1)
  }, [urlSearch])

  const query = useQuery({
    queryKey: ['customers', search, page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Customer>>('/customers', {
        params: { search: search || undefined, page, per_page: 20 },
      })
      return data
    },
  })

  const customers = query.data?.data ?? []
  const inactiveLabel = locale === 'ar' ? 'موقوف' : 'Inactive'
  const typeLabel = (type: Customer['type']) => (type === 'company' ? (locale === 'ar' ? 'شركة' : 'Company') : locale === 'ar' ? 'فرد' : 'Individual')

  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(t('branchRequired'))
      const { data } = await api.post<{ data: Customer }>('/customers', {
        ...form,
        branch_id: activeBranch.id,
        credit_limit_minor: toMinor(form.credit_limit || '0', 'EGP'),
      })
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm({ type: 'individual', name_ar: '', name_en: '', phone: '', email: '', tax_id: '', credit_limit: '0' })
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <div>
      <PageHeader
        title={t('customers')}
        subtitle={locale === 'ar' ? 'بيانات العملاء وجهات الاتصال' : 'Customer records and contact details'}
        icon="users"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newCustomer')}</Button>}
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
        </div>
        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && customers.length === 0 ? <EmptyState /> : null}
        {customers.length > 0 ? (
          <>
            <MobileCards items={customers} getKey={(customer) => customer.id} label={t('customers')}>
              {(customer) => (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <Link to={`/customers/${customer.id}`} className="text-medium font-semibold text-brand-1-accent underline">
                      {localizedName(customer, locale)}
                    </Link>
                    <span>
                      {customer.is_active ? null : <Badge tone="neutral">{inactiveLabel}</Badge>}
                      <AiBadge via={customer.created_via} />
                    </span>
                  </div>
                  <dl className="grid grid-cols-2 gap-3">
                    <CardField label={t('type')}>{typeLabel(customer.type)}</CardField>
                    <CardField label={t('phone')}>
                      <span dir="ltr">{customer.phone ?? '—'}</span>
                    </CardField>
                    <div className="col-span-2">
                      <CardField label={t('email')}>
                        <span dir="ltr">{customer.email ?? '—'}</span>
                      </CardField>
                    </div>
                  </dl>
                </>
              )}
            </MobileCards>
            <TableOnly>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('name')}</Th>
                    <Th>{t('phone')}</Th>
                    <Th>{t('email')}</Th>
                    <Th>{t('type')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {customers.map((customer) => (
                    <tr key={customer.id}>
                      <Td>
                        <Link to={`/customers/${customer.id}`} className="font-medium text-brand-1-accent hover:underline">
                          {localizedName(customer, locale)}
                        </Link>
                        {customer.is_active ? null : <Badge tone="neutral">{inactiveLabel}</Badge>}
                        <AiBadge via={customer.created_via} />
                      </Td>
                      <Td dir="ltr">{customer.phone ?? '—'}</Td>
                      <Td dir="ltr">{customer.email ?? '—'}</Td>
                      <Td>{typeLabel(customer.type)}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </TableOnly>
          </>
        ) : null}
        <Pagination page={query.data?.meta.current_page ?? 1} lastPage={query.data?.meta.last_page ?? 1} onChange={setPage} total={query.data?.meta.total} perPage={query.data?.meta.per_page} />
      </Card>

      <Modal
        open={open}
        title={t('newCustomer')}
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
          <Field label={t('type')}>
            <Select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value as Customer['type'] })}>
              <option value="individual">{locale === 'ar' ? 'فرد' : 'Individual'}</option>
              <option value="company">{locale === 'ar' ? 'شركة' : 'Company'}</option>
            </Select>
          </Field>
          <Field label={t('phone')}>
            <Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('nameAr')} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} />
          </Field>
          <Field label={t('nameEn')} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('email')}>
            <Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'الرقم الضريبي' : 'Tax ID'}>
            <Input value={form.tax_id} onChange={(event) => setForm({ ...form, tax_id: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'حد الائتمان' : 'Credit limit'}>
            <Input value={form.credit_limit} onChange={(event) => setForm({ ...form, credit_limit: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
