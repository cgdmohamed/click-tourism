import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Account } from '../lib/types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading, Modal, PageHeader, SearchableSelect, Select, Table, Td, Th } from '../components/ui'
import { Icon } from '../components/icons'

const accountTypes: Account['type'][] = ['asset', 'liability', 'equity', 'revenue', 'expense']

export default function Accounts() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({
    code: '',
    name_ar: '',
    name_en: '',
    type: 'asset' as Account['type'],
    parent_id: '',
    is_postable: true,
  })

  const query = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
  })

  const create = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Account }>('/accounts', {
        ...form,
        parent_id: form.parent_id || null,
      })
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setError('')
      setForm({ code: '', name_ar: '', name_en: '', type: 'asset', parent_id: '', is_postable: true })
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const filtered = useMemo(() => {
    const rows = query.data ?? []
    const needle = search.trim().toLowerCase()
    if (!needle) return rows
    return rows.filter((account) =>
      [account.code, account.name_ar, account.name_en].some((value) => value?.toLowerCase().includes(needle)),
    )
  }, [query.data, search])

  const typeLabel = (type: Account['type']) => {
    if (locale === 'en') return type
    return { asset: 'أصول', liability: 'التزامات', equity: 'حقوق ملكية', revenue: 'إيرادات', expense: 'مصروفات' }[type]
  }

  return (
    <div>
      <PageHeader
        title={t('accounts')}
        subtitle={locale === 'ar' ? 'دليل الحسابات الرئيسي والفرعي' : 'Nested chart of accounts'}
        icon="ledger"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{t('newAccount')}</Button>}
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('search')}
            className="max-w-xs"
          />
          <span className="text-xs text-text-subtle">{filtered.length}</span>
        </div>

        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && filtered.length === 0 ? <EmptyState /> : null}

        {filtered.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th>{t('name')}</Th>
                <Th>{t('accountType')}</Th>
                <Th>{t('postable')}</Th>
                <Th>{t('status')}</Th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((account) => (
                <tr key={account.id}>
                  <Td className="tabular font-medium">{account.code}</Td>
                  <Td>
                    <span className="font-medium">{locale === 'ar' ? account.name_ar : account.name_en}</span>
                    <span className="ms-2 text-xs text-text-subtle">{locale === 'ar' ? account.name_en : account.name_ar}</span>
                  </Td>
                  <Td>{typeLabel(account.type)}</Td>
                  <Td>
                    {account.is_postable ? (
                      <>
                        <Icon name="check" className="h-4 w-4 text-success-text" />
                        <span className="sr-only">{locale === 'ar' ? 'قابل للترحيل' : 'Postable'}</span>
                      </>
                    ) : (
                      <span className="text-text-subtlest">—</span>
                    )}
                  </Td>
                  <Td>
                    <Badge tone={account.is_active ? 'positive' : 'neutral'}>
                      {account.is_active ? (locale === 'ar' ? 'نشط' : 'Active') : locale === 'ar' ? 'موقوف' : 'Inactive'}
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
        title={t('newAccount')}
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
          <Field label={t('code')} required>
            <Input value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('accountType')} required>
            <Select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value as Account['type'] })}>
              {accountTypes.map((type) => (
                <option key={type} value={type}>
                  {typeLabel(type)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('nameAr')} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} />
          </Field>
          <Field label={t('nameEn')} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'الحساب الأب' : 'Parent account'}>
            <SearchableSelect
              value={form.parent_id}
              onChange={(parent_id) => setForm({ ...form, parent_id })}
              placeholder="—"
              clearLabel="—"
              options={(query.data ?? []).map((account) => ({
                value: account.id,
                label: `${account.code} — ${locale === 'ar' ? account.name_ar : account.name_en}`,
              }))}
            />
          </Field>
          <Field label={t('postable')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={form.is_postable}
                onChange={(event) => setForm({ ...form, is_postable: event.target.checked })}
              />
              {locale === 'ar' ? 'يسمح بالترحيل المباشر' : 'Allow direct posting'}
            </label>
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
