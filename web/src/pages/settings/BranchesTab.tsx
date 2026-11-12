import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../auth/AuthProvider'
import { useI18n } from '../../i18n'
import { api, errorMessage } from '../../lib/api'
import { localizedName } from '../../lib/names'
import type { Branch } from '../../lib/types'
import { CodeField } from '../../components/CodeField'
import { createdWithCode } from '../../lib/codes'
import { Badge, Button, Card, CardHeader, ErrorState, Field, InlineNotice, Input, Loading, Modal, Select, Table, Td, Th } from '../../components/ui'

const emptyBranch = { name_ar: '', name_en: '', accounting_mode: 'dimension' as Branch['accounting_mode'] }

export function BranchesTab() {
  const { t, locale } = useI18n()
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const [branchOpen, setBranchOpen] = useState(false)
  const [branchForm, setBranchForm] = useState(emptyBranch)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Branch[] }>('/branches')
      return data.data
    },
  })

  const createBranch = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Branch }>('/branches', branchForm)
      return data.data
    },
    onSuccess: async (branch) => {
      setNotice(createdWithCode(locale === 'ar', 'الفرع', 'Branch', branch.code))
      setBranchOpen(false)
      setError('')
      setBranchForm(emptyBranch)
      await queryClient.invalidateQueries({ queryKey: ['branches'] })
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  return (
    <Card>
      <CardHeader
        title={t('branches')}
        subtitle={locale === 'ar' ? 'كل فرع له نطاق بيانات وصلاحيات مستقل' : 'Each branch has its own data scope and permissions'}
        actions={
          can('branches.create') ? (
            <Button
              variant="secondary"
              onClick={() => {
                setNotice('')
                setBranchOpen(true)
              }}
            >
              {locale === 'ar' ? 'فرع جديد' : 'New branch'}
            </Button>
          ) : null
        }
      />
      {notice ? (
        <div className="m-4" role="status">
          <InlineNotice tone="positive">{notice}</InlineNotice>
        </div>
      ) : null}
      {error && !branchOpen ? <p className="m-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      {branchesQuery.isLoading ? <Loading /> : null}
      {branchesQuery.isError ? <ErrorState message={errorMessage(branchesQuery.error)} onRetry={() => void branchesQuery.refetch()} /> : null}
      {branchesQuery.data ? (
        <Table>
          <thead>
            <tr>
              <Th>{t('code')}</Th>
              <Th>{t('name')}</Th>
              <Th>{locale === 'ar' ? 'نمط المحاسبة' : 'Accounting mode'}</Th>
              <Th>{t('status')}</Th>
            </tr>
          </thead>
          <tbody>
            {branchesQuery.data.map((branch) => (
              <tr key={branch.id}>
                <Td className="tabular font-medium">{branch.code}</Td>
                <Td>
                  <p className="font-medium">{localizedName(branch, locale)}</p>
                </Td>
                <Td>{branch.accounting_mode}</Td>
                <Td>
                  <Badge tone={branch.is_active ? 'positive' : 'neutral'}>
                    {branch.is_active ? (locale === 'ar' ? 'نشط' : 'Active') : locale === 'ar' ? 'موقوف' : 'Inactive'}
                  </Badge>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      ) : null}

      <Modal
        open={branchOpen}
        title={locale === 'ar' ? 'فرع جديد' : 'New branch'}
        onClose={() => setBranchOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setBranchOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={createBranch.isPending} onClick={() => createBranch.mutate()}>
              {createBranch.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <CodeField label={t('code')} ar={locale === 'ar'} />
          <Field label={locale === 'ar' ? 'نمط المحاسبة' : 'Accounting mode'}>
            <Select
              value={branchForm.accounting_mode}
              onChange={(event) => setBranchForm({ ...branchForm, accounting_mode: event.target.value as Branch['accounting_mode'] })}
            >
              <option value="dimension">{locale === 'ar' ? 'بُعد محاسبي' : 'Dimension'}</option>
              <option value="customer">{locale === 'ar' ? 'كعميل' : 'As customer'}</option>
              <option value="independent">{locale === 'ar' ? 'مستقل' : 'Independent'}</option>
            </Select>
          </Field>
          <Field label={t('nameAr')} required>
            <Input value={branchForm.name_ar} onChange={(event) => setBranchForm({ ...branchForm, name_ar: event.target.value })} />
          </Field>
          <Field label={t('nameEn')} required>
            <Input value={branchForm.name_en} onChange={(event) => setBranchForm({ ...branchForm, name_en: event.target.value })} dir="ltr" />
          </Field>
        </div>
      </Modal>
    </Card>
  )
}
