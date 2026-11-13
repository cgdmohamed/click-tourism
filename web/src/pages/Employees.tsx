import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Employee } from '../lib/types'
import { employeeCategoryLabel } from '../lib/labels'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'
import { Icon } from '../components/icons'
import { localizedName } from '../lib/names'

const CATEGORIES = ['staff', 'executive', 'driver', 'other'] as const
const emptyForm = { internal_code: '', name_ar: '', name_en: '', category: 'staff', phone: '', email: '' }

export default function Employees() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const ar = locale === 'ar'
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm })
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [includeInactive, setIncludeInactive] = useState(false)

  const query = useQuery({
    queryKey: ['employees', search, includeInactive],
    queryFn: async () => {
      const { data } = await api.get<{ data: Employee[] }>('/employees', {
        params: { search: search || undefined, include_inactive: includeInactive ? 1 : 0 },
      })
      return data.data
    },
  })

  const employees = query.data ?? []

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/employees', {
        internal_code: form.internal_code.trim(),
        name_ar: form.name_ar.trim(),
        name_en: form.name_en.trim(),
        category: form.category,
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
      })
    },
    onSuccess: async () => {
      setOpen(false)
      setForm({ ...emptyForm })
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['employees'] })
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const openCreate = () => {
    setForm({ ...emptyForm })
    setError('')
    setOpen(true)
  }
  const canSave = form.internal_code.trim() !== '' && form.name_ar.trim() !== '' && form.name_en.trim() !== ''

  return (
    <div>
      <PageHeader
        title={t('employees')}
        subtitle={locale === 'ar' ? 'السجلات المالية للموظفين — دون الرواتب' : 'Employee financial records — not payroll'}
        icon="userCheck"
        actions={
          can('employees.manage') ? (
            <Button icon="plus" onClick={openCreate}>
              {ar ? 'موظف جديد' : 'New employee'}
            </Button>
          ) : undefined
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('searchEmployees')}
            className="max-w-xs"
          />
          <label className="text-small inline-flex items-center gap-2 text-text-subtle">
            <input
              type="checkbox"
              checked={includeInactive}
              onChange={(event) => setIncludeInactive(event.target.checked)}
              className="h-4 w-4 rounded-4 border-border-default"
            />
            {t('inactive')}
          </label>
        </div>
        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && employees.length === 0 ? <EmptyState icon="userCheck" title={t('noData')} /> : null}
        {employees.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('name')}</Th>
                <Th>{t('category')}</Th>
                <Th>{t('phone')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {employees.map((employee) => (
                <tr key={employee.id}>
                  <Td className="font-medium" dir="ltr">{employee.internal_code}</Td>
                  <Td>
                    <Link to={`/employees/${employee.id}`} className="font-medium text-brand-1-accent hover:underline">
                      {localizedName(employee, locale)}
                    </Link>
                  </Td>
                  <Td>{employeeCategoryLabel(employee.category, locale)}</Td>
                  <Td dir="ltr">{employee.phone ?? '—'}</Td>
                  <Td>
                    <Badge tone={employee.is_active ? 'positive' : 'neutral'}>
                      {employee.is_active ? t('active') : t('inactive')}
                    </Badge>
                  </Td>
                  <Td className="text-end">
                    <Link
                      to={`/employees/${employee.id}`}
                      className="text-small inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline"
                    >
                      {t('viewProfile')}
                      <Icon name="chevronRight" className="h-3.5 w-3.5" directional />
                    </Link>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={ar ? 'موظف جديد' : 'New employee'}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={!canSave || create.isPending} onClick={() => create.mutate()}>
              {t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={ar ? 'الكود الداخلي' : 'Internal code'} required>
            <Input value={form.internal_code} onChange={(event) => setForm({ ...form, internal_code: event.target.value })} dir="ltr" maxLength={40} />
          </Field>
          <Field label={t('category')}>
            <Select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}>
              {CATEGORIES.map((category) => (
                <option key={category} value={category}>
                  {employeeCategoryLabel(category, locale)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={ar ? 'الاسم (عربي)' : 'Name (Arabic)'} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} dir="rtl" />
          </Field>
          <Field label={ar ? 'الاسم (إنجليزي)' : 'Name (English)'} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('phone')}>
            <Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} dir="ltr" />
          </Field>
          <Field label={ar ? 'البريد الإلكتروني' : 'Email'}>
            <Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} dir="ltr" />
          </Field>
        </div>
        {error ? <p className="text-small mt-3 text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
