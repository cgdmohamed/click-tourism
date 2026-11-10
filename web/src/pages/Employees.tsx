import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Employee } from '../lib/types'
import { employeeCategoryLabel } from '../lib/labels'
import { Badge, Card, EmptyState, ErrorState, Input, Loading, PageHeader, Table, Td, Th } from '../components/ui'
import { Icon } from '../components/icons'

export default function Employees() {
  const { t, locale } = useI18n()
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

  return (
    <div>
      <PageHeader
        title={t('employees')}
        subtitle={locale === 'ar' ? 'السجلات المالية للموظفين — دون الرواتب' : 'Employee financial records — not payroll'}
        icon="userCheck"
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
                      {locale === 'ar' ? employee.name_ar : employee.name_en}
                    </Link>
                    <p className="text-small text-text-subtlest">{locale === 'ar' ? employee.name_en : employee.name_ar}</p>
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
    </div>
  )
}
