import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { localizedName } from '../lib/names'
import type { Account, AccountClassification } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  cx,
  EmptyState,
  ErrorState,
  Field,
  InlineNotice,
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
import { createdWithCode } from '../lib/codes'
import { ActionsMenu } from '../components/ActionsMenu'
import { CardField, MobileCards, TableOnly } from '../components/MobileCards'

const accountTypes: Account['type'][] = ['asset', 'liability', 'equity', 'revenue', 'expense']

/** Classifications allowed per account type (mirrors the backend). */
const classificationsByType: Partial<Record<Account['type'], AccountClassification[]>> = {
  asset: ['current_asset', 'non_current_asset', 'fixed_asset', 'prepayment'],
  liability: ['current_liability', 'non_current_liability'],
  equity: ['equity'],
}

const classificationLabels: Record<AccountClassification, { ar: string; en: string }> = {
  current_asset: { ar: 'أصول متداولة', en: 'Current asset' },
  non_current_asset: { ar: 'أصول غير متداولة', en: 'Non-current asset' },
  fixed_asset: { ar: 'أصول ثابتة', en: 'Fixed asset' },
  prepayment: { ar: 'مدفوعات مقدمة', en: 'Prepayment' },
  current_liability: { ar: 'التزامات متداولة', en: 'Current liability' },
  non_current_liability: { ar: 'التزامات غير متداولة', en: 'Non-current liability' },
  equity: { ar: 'حقوق ملكية', en: 'Equity' },
}

type ImportRowError = { row: number; code: string; message: string; params?: Record<string, string> }

const arImportErrors: Record<string, (p: Record<string, string>) => string> = {
  required: () => 'الكود والاسم العربي والاسم الإنجليزي مطلوبة.',
  too_long: () => 'الكود (حتى 20 حرفًا) أو الاسم (حتى 255 حرفًا) أطول من المسموح.',
  duplicate_code: (p) => `الكود '${p.code}' مكرر (ظهر أولًا في الصف ${p.first}).`,
  invalid_type: (p) => `نوع غير صالح '${p.type}'.`,
  invalid_boolean: (p) => `القيمة في العمود ${p.field} يجب أن تكون true أو false.`,
  invalid_classification: (p) => `التصنيف '${p.classification}' غير صالح لنوع الحساب '${p.type}'.`,
  invalid_currency: (p) => `عملة غير معروفة '${p.currency}'.`,
  self_parent: () => 'لا يمكن أن يكون الحساب أبًا لنفسه.',
  parent_not_found: (p) => `الحساب الأب '${p.parent}' غير موجود.`,
  circular_parent: () => 'تم اكتشاف مرجع دائري في الحساب الأب.',
  account_has_history: (p) => `لا يمكن تغيير (${p.field}) للحساب '${p.code}' لأن لديه قيودًا أو تسجيلًا كحساب نقدي/بنكي.`,
}

function importRowErrorText(item: ImportRowError, locale: string): string {
  if (locale === 'ar') {
    const text = arImportErrors[item.code]
    return `الصف ${item.row}: ${text ? text(item.params ?? {}) : item.message}`
  }
  return item.message
}

export default function Accounts() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  // Tree state: per-node overrides on top of the default (groups with party sub-accounts start collapsed).
  const [bulk, setBulk] = useState<'all' | 'none' | null>(null)
  const [overrides, setOverrides] = useState<Record<string, boolean>>({})
  const [open, setOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  // True once the user typed their own code; until then the server's suggestion is shown and the code is left to it.
  const [codeTouched, setCodeTouched] = useState(false)
  const [importError, setImportError] = useState('')
  const [importRowErrors, setImportRowErrors] = useState<ImportRowError[]>([])
  const [importPreview, setImportPreview] = useState<Array<{ row: number; code: string; name_ar: string; name_en: string; type: string; action: string }>>([])
  const [showImport, setShowImport] = useState(false)
  const [form, setForm] = useState({
    code: '',
    name_ar: '',
    name_en: '',
    type: 'asset' as Account['type'],
    classification: '',
    parent_id: '',
    is_postable: true,
    is_active: true,
  })
  const [importFile, setImportFile] = useState<File | null>(null)

  const query = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
  })

  // Suggested code for a new account (next free under the parent, or the next root of the type).
  const suggestionQuery = useQuery({
    queryKey: ['accounts', 'next-code', form.parent_id || null, form.parent_id ? null : form.type],
    enabled: open && !editId,
    queryFn: async () => {
      const { data } = await api.get<{ data: { code: string } }>('/accounts/next-code', {
        params: form.parent_id ? { parent_id: form.parent_id } : { type: form.type },
      })
      return data.data.code
    },
  })
  const suggestedCode = suggestionQuery.data ?? ''
  const shownCode = editId || codeTouched ? form.code : suggestedCode

  const createOrUpdate = useMutation({
    mutationFn: async () => {
      const { code, ...rest } = form
      const payload = {
        ...rest,
        // On create the code is only sent when the user chose their own; otherwise the server generates it.
        ...(editId ? { code } : codeTouched && code.trim() ? { code: code.trim() } : {}),
        parent_id: form.parent_id || null,
        classification: classificationsByType[form.type] ? form.classification || null : null,
      }
      if (editId) {
        const { data } = await api.patch<{ data: Account }>(`/accounts/${editId}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: Account }>('/accounts', payload)
      return data.data
    },
    onSuccess: async (account) => {
      if (!editId) setNotice(createdWithCode(locale === 'ar', 'الحساب', 'Account', account.code))
      setOpen(false)
      setEditId(null)
      setError('')
      setCodeTouched(false)
      setForm({
        code: '',
        name_ar: '',
        name_en: '',
        type: 'asset',
        classification: '',
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
      const { data } = await api.post<{ imported: number; preview: Array<{ row: number; code: string; name_ar: string; name_en: string; type: string; action: string }> }>('/accounts/import', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return data
    },
    onSuccess: async (result) => {
      setImportPreview(result.preview)
      setImportError('')
      setImportRowErrors([])
      await queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
    onError: (cause) => {
      // A failed preview/import must not leave a stale preview that could be applied.
      setImportPreview([])
      const rowErrors = axios.isAxiosError<{ row_errors?: ImportRowError[] }>(cause) ? cause.response?.data?.row_errors : undefined
      if (rowErrors?.length) {
        setImportRowErrors(rowErrors)
        setImportError('')
      } else {
        setImportRowErrors([])
        setImportError(errorMessage(cause))
      }
    },
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

  const ar = locale === 'ar'

  const tree = useMemo(() => {
    const rows = query.data ?? []
    const byId = new Map(rows.map((account) => [account.id, account]))
    const children = new Map<string, Account[]>()
    const roots: Account[] = []
    for (const account of rows) {
      if (account.parent_id && byId.has(account.parent_id)) {
        const list = children.get(account.parent_id) ?? []
        list.push(account)
        children.set(account.parent_id, list)
      } else {
        roots.push(account)
      }
    }
    return { byId, children, roots }
  }, [query.data])

  /** Ids to show while searching (matches plus their ancestors) and the ancestors that must be open. */
  const searchState = useMemo(() => {
    const needle = search.trim().toLowerCase()
    if (!needle) return null
    const visible = new Set<string>()
    const open = new Set<string>()
    let matches = 0
    for (const account of query.data ?? []) {
      const haystack = [account.code, account.name_ar, account.name_en, account.party_name_ar, account.party_name_en]
      if (!haystack.some((value) => value?.toLowerCase().includes(needle))) continue
      matches += 1
      visible.add(account.id)
      let parent = account.parent_id ? tree.byId.get(account.parent_id) : undefined
      while (parent && !open.has(parent.id)) {
        visible.add(parent.id)
        open.add(parent.id)
        parent = parent.parent_id ? tree.byId.get(parent.parent_id) : undefined
      }
    }
    return { visible, open, matches }
  }, [query.data, search, tree])

  const isExpanded = (account: Account) => {
    if (searchState?.open.has(account.id)) return true
    if (account.id in overrides) return overrides[account.id]
    if (bulk) return bulk === 'all'
    return !(tree.children.get(account.id) ?? []).some((child) => child.party_type)
  }

  const rowsToRender = useMemo(() => {
    const out: Array<{ account: Account; depth: number; childCount: number; expanded: boolean }> = []
    const walk = (account: Account, depth: number) => {
      if (searchState && !searchState.visible.has(account.id)) return
      const kids = (tree.children.get(account.id) ?? []).filter((child) => !searchState || searchState.visible.has(child.id))
      const allKids = tree.children.get(account.id) ?? []
      const expanded = isExpanded(account)
      out.push({ account, depth, childCount: allKids.length, expanded })
      if (expanded) kids.forEach((child) => walk(child, depth + 1))
    }
    tree.roots.forEach((account) => walk(account, 0))
    return out
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tree, searchState, overrides, bulk])

  const partyTypeLabel = (type: NonNullable<Account['party_type']>) =>
    type === 'customer' ? (ar ? 'عميل' : 'Customer') : type === 'supplier' ? (ar ? 'مورد' : 'Supplier') : ar ? 'موظف' : 'Employee'
  const partyName = (account: Account) => localizedName({ name_ar: account.party_name_ar, name_en: account.party_name_en }, locale)

  const toggleNode = (account: Account, expanded: boolean) => {
    setOverrides((current) => ({ ...current, [account.id]: !expanded }))
  }
  const expandAll = () => { setBulk('all'); setOverrides({}) }
  const collapseAll = () => { setBulk('none'); setOverrides({}) }
  const hasGroups = tree.children.size > 0
  const shownCount = searchState ? searchState.matches : (query.data ?? []).length

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
      classification: account.classification ?? '',
      parent_id: account.parent_id ?? '',
      is_postable: account.is_postable,
      is_active: account.is_active,
    })
    setEditId(account.id)
    setError('')
    setNotice('')
    setCodeTouched(true)
    setOpen(true)
  }

  const openNew = () => {
    setEditId(null)
    setError('')
    setNotice('')
    setCodeTouched(false)
    setForm({ code: '', name_ar: '', name_en: '', type: 'asset', classification: '', parent_id: '', is_postable: true, is_active: true })
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
            <Button icon="plus" onClick={openNew}>{t('newAccount')}</Button>
            <ActionsMenu
              items={[
                { key: 'export', label: t('exportAccounts'), icon: 'download', onSelect: () => void exportAccounts() },
                { key: 'import', label: t('importAccounts'), icon: 'upload', onSelect: () => setShowImport(true) },
              ]}
            />
          </>
        }
      />

      {notice ? (
        <div className="mb-4" role="status">
          <InlineNotice tone="positive">{notice}</InlineNotice>
        </div>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border-subtle px-5 py-4">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('search')}
            className="max-w-xs"
          />
          <span className="text-xs text-text-subtle">{shownCount}</span>
          {hasGroups ? (
            <div className="ms-auto flex items-center gap-2">
              <Button variant="secondary" icon="chevronDown" onClick={expandAll}>{ar ? 'توسيع الكل' : 'Expand all'}</Button>
              <Button variant="secondary" icon="chevronRight" onClick={collapseAll}>{ar ? 'طي الكل' : 'Collapse all'}</Button>
            </div>
          ) : null}
        </div>

        {query.isLoading ? <Loading /> : null}
        {query.isError ? <ErrorState message={errorMessage(query.error)} onRetry={() => void query.refetch()} /> : null}
        {query.data && rowsToRender.length === 0 ? (search ? <EmptyState variant="filtered" onClear={() => setSearch('')} /> : <EmptyState />) : null}

        {rowsToRender.length > 0 ? (
          <>
          <MobileCards items={rowsToRender} getKey={(row) => row.account.id} label={t('accounts')}>
            {({ account, depth, childCount, expanded }) => (
              <div style={{ marginInlineStart: `${Math.min(depth, 4) * 0.75}rem` }} className="flex flex-col gap-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-start gap-2">
                    {childCount > 0 ? (
                      <button
                        type="button"
                        className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-6 text-text-subtle hover:bg-surface-hovered"
                        aria-expanded={expanded}
                        aria-label={`${expanded ? (ar ? 'طي' : 'Collapse') : ar ? 'توسيع' : 'Expand'} ${account.code}`}
                        onClick={() => toggleNode(account, expanded)}
                      >
                        <Icon name={expanded ? 'chevronDown' : 'chevronRight'} className="h-4 w-4" />
                      </button>
                    ) : null}
                    <div className="min-w-0">
                      <p className="text-medium font-semibold">{localizedName(account, locale)}</p>
                      <p className="text-small tabular text-text-subtle" dir="ltr">{account.code}</p>
                    </div>
                  </div>
                  <Badge tone={account.is_active ? 'positive' : 'neutral'}>
                    {account.is_active ? (locale === 'ar' ? 'نشط' : 'Active') : locale === 'ar' ? 'موقوف' : 'Inactive'}
                  </Badge>
                </div>
                <dl className="grid grid-cols-2 gap-3">
                  <CardField label={t('accountType')}>{typeLabel(account.type)}</CardField>
                  <CardField label={t('postable')}>{account.is_postable ? (locale === 'ar' ? 'نعم' : 'Yes') : '—'}</CardField>
                </dl>
                <div className="flex gap-2">
                  <Button variant="secondary" icon="edit" onClick={() => openEdit(account)}>{t('edit')}</Button>
                </div>
              </div>
            )}
          </MobileCards>
          <TableOnly>
          <Table>
            <thead>
              <tr>
                <Th>{t('code')}</Th>
                <Th className="min-w-[18rem]">{t('name')}</Th>
                <Th>{t('accountType')}</Th>
                <Th>{locale === 'ar' ? 'التصنيف' : 'Classification'}</Th>
                <Th>{t('postable')}</Th>
                <Th>{t('status')}</Th>
                <Th className="text-end">{t('actions')}</Th>
              </tr>
            </thead>
            <tbody>
              {rowsToRender.map(({ account, depth, childCount, expanded }) => (
                <tr key={account.id} className={cx(account.party_type ? 'bg-surface-subtle/40' : childCount > 0 && 'font-medium')}>
                  <Td className="tabular whitespace-nowrap font-mono font-medium" dir="ltr">{account.code}</Td>
                  <Td>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1" style={{ paddingInlineStart: `${depth * 1.75}rem` }}>
                      {childCount > 0 ? (
                        <button
                          type="button"
                          className="-ms-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-6 text-text-subtle hover:bg-surface-hovered"
                          aria-expanded={expanded}
                          aria-label={`${expanded ? (ar ? 'طي' : 'Collapse') : ar ? 'توسيع' : 'Expand'} ${account.code}`}
                          onClick={() => toggleNode(account, expanded)}
                        >
                          <Icon name={expanded ? 'chevronDown' : 'chevronRight'} className="h-4 w-4" />
                        </button>
                      ) : (
                        <span className="inline-block h-6 w-5 shrink-0" aria-hidden="true" />
                      )}
                      <span className={account.party_type ? undefined : 'font-medium'}>{localizedName(account, locale)}</span>
                      {account.party_type ? (
                        <Badge tone="brand">
                          <span className="whitespace-nowrap">
                            {partyTypeLabel(account.party_type)}
                            {partyName(account) ? ` · ${partyName(account)}` : ''}
                          </span>
                        </Badge>
                      ) : null}
                      {childCount > 0 ? (
                        <Badge>
                          <span className="whitespace-nowrap">
                            {ar ? (childCount === 1 ? 'حساب فرعي واحد' : `${childCount} حسابات فرعية`) : `${childCount} sub-account${childCount === 1 ? '' : 's'}`}
                          </span>
                        </Badge>
                      ) : null}
                    </div>
                  </Td>
                  <Td>{typeLabel(account.type)}</Td>
                  <Td>
                    {account.classification ? (
                      classificationLabels[account.classification][locale === 'ar' ? 'ar' : 'en']
                    ) : classificationsByType[account.type] ? (
                      <Badge tone="warning">{locale === 'ar' ? 'غير مصنف' : 'Unclassified'}</Badge>
                    ) : (
                      <span className="text-text-subtlest">—</span>
                    )}
                  </Td>
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
          </TableOnly>
          </>
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
          <Field
            label={t('code')}
            hint={
              editId
                ? undefined
                : locale === 'ar'
                  ? 'مقترح تلقائياً. يمكنك كتابة كود آخر إن رغبت.'
                  : 'Suggested automatically. You may type your own code instead.'
            }
          >
            <Input
              value={shownCode}
              onChange={(event) => {
                setCodeTouched(true)
                setForm({ ...form, code: event.target.value })
              }}
              dir="ltr"
              maxLength={20}
              readOnly={!!editId}
              className="tabular"
            />
          </Field>
          <Field label={t('accountType')} required>
            <Select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value as Account['type'], classification: '' })}>
              {accountTypes.map((type) => (
                <option key={type} value={type}>{typeLabel(type)}</option>
              ))}
            </Select>
          </Field>
          {classificationsByType[form.type] ? (
            <Field label={locale === 'ar' ? 'التصنيف في الميزانية' : 'Balance sheet classification'}>
              <Select
                value={form.classification}
                onChange={(event) => setForm({ ...form, classification: event.target.value })}
              >
                <option value="">{locale === 'ar' ? 'غير مصنف' : 'Unclassified'}</option>
                {(classificationsByType[form.type] ?? []).map((value) => (
                  <option key={value} value={value}>{classificationLabels[value][locale === 'ar' ? 'ar' : 'en']}</option>
                ))}
              </Select>
            </Field>
          ) : null}
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
                label: localizedName(account, locale),
                code: account.code,
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
                if (!importFile) return
                const fd = new FormData()
                fd.append('file', importFile)
                fd.append('preview_only', 'true')
                importAccounts.mutate(fd)
              }}
              disabled={importAccounts.isPending || !importFile}
            >
              {t('previewOnly')}
            </Button>
            <Button
              disabled={importAccounts.isPending || importPreview.length === 0}
              onClick={() => {
                if (!importFile) return
                const fd = new FormData()
                fd.append('file', importFile)
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
            <Input type="file" accept=".csv" onChange={(event) => {
              setImportFile(event.target.files?.[0] ?? null)
              setImportPreview([])
              setImportRowErrors([])
              setImportError('')
            }} />
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
        {importRowErrors.length > 0 ? (
          <div className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text" role="alert">
            <p className="font-semibold">
              {locale === 'ar'
                ? `يوجد ${importRowErrors.length} خطأ في الملف. صحح الأخطاء وأعد المعاينة.`
                : `${importRowErrors.length} error(s) found. Fix the file and preview again.`}
            </p>
            <ul className="mt-2 max-h-60 list-disc space-y-1 overflow-y-auto ps-5">
              {importRowErrors.map((item, index) => (
                <li key={`${item.row}-${index}`}>{importRowErrorText(item, locale)}</li>
              ))}
            </ul>
          </div>
        ) : null}
        {importError ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{importError}</p> : null}
      </Modal>
    </div>
  )
}
