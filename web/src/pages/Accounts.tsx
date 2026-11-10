import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Account } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  PageHeader,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
} from '../components/ui'
import { Icon } from '../components/icons'

const accountTypes: Account['type'][] = ['asset', 'liability', 'equity', 'revenue', 'expense']

export default function Accounts() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [importError, setImportError] = useState('')
  const [importPreview, setImportPreview] = useState<any[]>([])
  const [showImport, setShowImport] = useState(false)
  const [form, setForm] = useState({
    code: '',
    name_ar: '',
    name_en: '',
    type: 'asset' as Account['type'],
    parent_id: '',
    is_postable: true,
    is_active: true,
  })

  const query = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
  })

  const createOrUpdate = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        parent_id: form.parent_id || null,
      }
      if (editId) {
        const { data } = await api.patch<{ data: Account }>(`/accounts/${editId}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: Account }>('/accounts', payload)
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setEditId(null)
      setError('')
      setForm({
        code: '',
        name_ar: '',
        name_en: '',
        type: 'asset',
        parent_id: '',
        is_postable: true,
        is_active: true,
      })
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const importAccounts = useMutation({
    mutationFn: async (body: FormData) => {
      const { data } = await api.post<{ imported: number; preview: any[] }>('/accounts/import', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return data
    },
    onSuccess: async (result) => {
      setImportPreview(result.preview)
      setImportError('')
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (cause) => setImportError(errorMessage(cause)),
  })

  const exportAccounts = async () => {
    const res = await api.get('/accounts/export', { responseType: 'blob' })
    const blob = new Blob([res.data as BlobPart], { type: 'text/csv;charset=UTF-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'chart-of-accounts.csv'
    link.click()
    URL.revokeObjectURL(url)
  }

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

  const openEdit = (account: Account) => {
    setForm({
      code: account.code,
      name_ar: account.name_ar,
      name_en: account.name_en,
      type: account.type,
      parent_id: account.parent_id ?? '',
      is_postable: account.is_postable,
      is_active: account.is_active,
    })
    setEditId(account.id)
    setError('')
    setOpen(true)
  }

  return (
    <div>
      <PageHeader
        title={t('accounts')}
        subtitle={locale === 'ar' ? 'دليل الحسابات الرئيسي والفرعي' : 'Nested chart of accounts'}
        icon="ledger"
        actions={
          <>
            <Button icon="plus" onClick={() => setOpen(true)}>{t('newAccount')}</Button>
            <Button variant="secondary" icon="download" onClick={exportAccounts}>
              {t('exportAccounts')}
            </Button>
            <Button variant="secondary" icon="upload" onClick={() => setShowImport(true)}>
              {t('importAccounts')}
            </Button>
          </>
        }
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
                <Th className="text-end">{t('actions')}</Th>
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
                  <Td className="text-end">
                    <Button variant="ghost" icon="edit" onClick={() => openEdit(account)} />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={editId ? t('editAccount') : t('newAccount')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={createOrUpdate.isPending} onClick={() => createOrUpdate.mutate()}>
              {createOrUpdate.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('code')} required>
            <Input
              value={form.code}
              onChange={(event) => setForm({ ...form, code: event.target.value })}
              dir="ltr"
              disabled={!!editId}
            />
          </Field>
          <Field label={t('accountType')} required>
            <Select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value as Account['type'] })}>
              {accountTypes.map((type) => (
                <option key={type} value={type}>{typeLabel(type)}</option>
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
              options={(query.data ?? []).filter((a) => a.id !== editId).map((account) => ({
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
          <Field label={t('active')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
              />
              {locale === 'ar' ? 'الحساب نشط' : 'Account is active'}
            </label>
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <Modal
        open={showImport}
        title={t('importAccounts')}
        onClose={() => setShowImport(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowImport(false)}>{t('cancel')}</Button>
            <Button
              variant="secondary"
              onClick={() => {
                const fd = new FormData()
                fd.append('file', (document.getElementById('import-file') as HTMLInputElement).files![0])
                fd.append('preview_only', 'true')
                importAccounts.mutate(fd)
              }}
              disabled={importAccounts.isPending || !(document.getElementById('import-file') as HTMLInputElement).files?.length}
            >
              {t('previewOnly')}
            </Button>
            <Button
              disabled={importAccounts.isPending || importPreview.length === 0}
              onClick={() => {
                const fd = new FormData()
                fd.append('file', (document.getElementById('import-file') as HTMLInputElement).files![0])
                importAccounts.mutate(fd)
              }}
            >
              {t('update')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('file')} required>
            <Input type="file" id="import-file" accept=".csv" />
          </Field>
          <Field label={t('previewOnly')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={true}
                readOnly
              />
              {locale === 'ar' ? 'مراجعة قبل التحديث' : 'Review before updating'}
            </label>
          </Field>
        </div>
        {importPreview.length > 0 ? (
          <Card>
            <Table>
              <thead>
                <tr>
                  <Th>{t('row')}</Th>
                  <Th>{t('code')}</Th>
                  <Th>{t('nameAr')}</Th>
                  <Th>{t('nameEn')}</Th>
                  <Th>{t('type')}</Th>
                  <Th>{t('action')}</Th>
                </tr>
              </thead>
              <tbody>
                {importPreview.map((item) => (
                  <tr key={item.row}>
                    <Td>{item.row}</Td>
                    <Td>{item.code}</Td>
                    <Td>{item.name_ar}</Td>
                    <Td>{item.name_en}</Td>
                    <Td>{item.type}</Td>
                    <Td>{item.action}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <p className="text-small text-text-subtle">{locale === 'ar' ? `سيتم تحديث ${importPreview.length} حساب` : `Will update ${importPreview.length} accounts`}</p>
          </Card>
        ) : null}
        {importError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{importError}</p> : null}
      </Modal>
    </div>
  )
}
