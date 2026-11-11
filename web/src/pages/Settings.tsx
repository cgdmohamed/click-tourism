import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Branch } from '../lib/types'
import AccountingPolicyPanel from '../components/AccountingPolicyPanel'
import { Badge, Button, Card, CardHeader, ErrorState, Field, Input, Loading, Modal, PageHeader, Select, Table, Td, Th } from '../components/ui'

type Setting = { group: string; key: string; value: string | null; is_secret: boolean }
type Currency = { code: string; name: string; symbol: string; exponent: number; is_active: boolean }
type Decision = { id: string; type: string; title_en: string; title_ar: string; blocks: string; status: string }

const editable: Array<{ group: string; key: string; ar: string; en: string; type?: 'text' | 'select' | 'toggle'; options?: string[] }> = [
  { group: 'general', key: 'company_name_ar', ar: 'اسم الشركة (عربي)', en: 'Company name (Arabic)' },
  { group: 'general', key: 'company_name_en', ar: 'اسم الشركة (إنجليزي)', en: 'Company name (English)' },
  { group: 'general', key: 'base_currency', ar: 'العملة الأساسية', en: 'Base currency', type: 'select', options: ['EGP', 'SAR', 'USD'] },
  { group: 'general', key: 'locale', ar: 'اللغة الافتراضية', en: 'Default language', type: 'select', options: ['ar', 'en'] },
  { group: 'accounting', key: 'fiscal_year_start', ar: 'بداية السنة المالية (MM-DD)', en: 'Fiscal year start (MM-DD)' },
  { group: 'accounting', key: 'rounding_policy', ar: 'سياسة التقريب', en: 'Rounding policy', type: 'select', options: ['half_up', 'bankers'] },
  { group: 'accounting', key: 'credit_limit_policy', ar: 'سياسة حد الائتمان', en: 'Credit limit policy', type: 'select', options: ['off', 'warn', 'block'] },
  { group: 'tax', key: 'enabled', ar: 'تفعيل الضريبة', en: 'Enable tax', type: 'toggle', options: ['true', 'false'] },
  { group: 'crm', key: 'reassign_after_hours', ar: 'إعادة توزيع العميل المحتمل بعد (ساعات)', en: 'Lead reassignment after (hours)' },
]

const keyOf = (group: string, key: string) => `${group}.${key}`

export default function Settings() {
  const { t, locale } = useI18n()
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [branchOpen, setBranchOpen] = useState(false)
  const [branchForm, setBranchForm] = useState({
    code: '',
    name_ar: '',
    name_en: '',
    accounting_mode: 'dimension' as Branch['accounting_mode'],
  })

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Setting[] }>('/settings')
      return data.data
    },
  })

  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Branch[] }>('/branches')
      return data.data
    },
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Currency[] }>('/currencies')
      return data.data
    },
  })

  const decisionsQuery = useQuery({
    queryKey: ['decisions'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Decision[] }>('/decisions')
      return data.data
    },
    enabled: can('settings.view'),
  })

  const values = useMemo(() => {
    const map: Record<string, string> = {}
    for (const setting of settingsQuery.data ?? []) {
      map[keyOf(setting.group, setting.key)] = setting.value ?? ''
    }
    return map
  }, [settingsQuery.data])

  useEffect(() => {
    setDraft(values)
  }, [values])

  const save = useMutation({
    mutationFn: async () => {
      const settings = editable
        .map((field) => ({
          group: field.group,
          key: field.key,
          value: draft[keyOf(field.group, field.key)] ?? '',
        }))
        .filter((setting) => setting.value !== (values[keyOf(setting.group, setting.key)] ?? ''))
      if (settings.length === 0) return null
      const { data } = await api.put('/settings', { settings })
      return data
    },
    onSuccess: async () => {
      setSaved(true)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
      await refresh()
      window.setTimeout(() => setSaved(false), 2500)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createBranch = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Branch }>('/branches', branchForm)
      return data.data
    },
    onSuccess: async () => {
      setBranchOpen(false)
      setError('')
      setBranchForm({ code: '', name_ar: '', name_en: '', accounting_mode: 'dimension' })
      await queryClient.invalidateQueries({ queryKey: ['branches'] })
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const readOnly = !can('settings.update')

  return (
    <div>
      <PageHeader
        title={t('settings')}
        subtitle={locale === 'ar' ? 'إعدادات التثبيت والفروع والعملات' : 'Installation, branch, and currency configuration'}
        icon="settings"
        actions={
          <Button icon="save" onClick={() => save.mutate()} disabled={readOnly || save.isPending}>
            {save.isPending ? t('saving') : saved ? t('saved') : t('save')}
          </Button>
        }
      />

      {error ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      {readOnly ? (
        <p className="mb-4 rounded-12 border border-warning-border bg-warning-background px-3 py-2 text-medium text-warning-text">{t('permissionDenied')}</p>
      ) : null}

      {decisionsQuery.isError ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
          {t('decisionsLoadError')}
        </p>
      ) : null}

      {decisionsQuery.data && decisionsQuery.data.length > 0 ? (
        <Card className="mb-6 border-warning-border">
          <CardHeader title={t('unresolvedDecisions')} subtitle={t('unresolvedDecisionsHint')} />
          <ul>
            {decisionsQuery.data.map((decision) => (
              <li
                key={decision.id}
                className="flex flex-wrap items-start gap-2 border-b border-border-subtle px-5 py-3 last:border-b-0"
              >
                <Badge tone="warning">{decision.id}</Badge>
                <div className="min-w-0 flex-1">
                  <p className="text-medium font-medium text-text-default">
                    {locale === 'ar' ? decision.title_ar : decision.title_en}
                  </p>
                  <p className="text-small text-text-subtle">
                    {t('blocks')}: <span className="tabular">{decision.blocks}</span>
                  </p>
                </div>
                <Badge tone="neutral">{t('decisionOpen')}</Badge>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title={locale === 'ar' ? 'الإعدادات العامة' : 'General settings'} />
          {settingsQuery.isLoading ? <Loading /> : null}
          {settingsQuery.isError ? (
            <ErrorState message={errorMessage(settingsQuery.error)} onRetry={() => void settingsQuery.refetch()} />
          ) : null}
          {settingsQuery.data ? (
            <div className="grid gap-4 px-5 py-5 sm:grid-cols-2">
              {editable.map((field) => {
                const id = keyOf(field.group, field.key)
                const label = locale === 'ar' ? field.ar : field.en
                if (field.type === 'toggle') {
                  const checked = (draft[id] ?? 'false') === 'true'
                  return (
                    <Field key={id} label={label}>
                      <label className="flex items-center gap-2 py-2 text-sm">
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={readOnly}
                          onChange={(event) => setDraft({ ...draft, [id]: event.target.checked ? 'true' : 'false' })}
                        />
                        {checked ? (locale === 'ar' ? 'مفعّل' : 'Enabled') : locale === 'ar' ? 'معطّل' : 'Disabled'}
                      </label>
                    </Field>
                  )
                }
                if (field.type === 'select') {
                  return (
                    <Field key={id} label={label}>
                      <Select
                        value={draft[id] ?? ''}
                        disabled={readOnly}
                        onChange={(event) => setDraft({ ...draft, [id]: event.target.value })}
                      >
                        <option value="">—</option>
                        {(field.options ?? []).map((option) => (
                          <option key={option} value={option}>
                            {option}
                          </option>
                        ))}
                      </Select>
                    </Field>
                  )
                }
                return (
                  <Field key={id} label={label}>
                    <Input
                      value={draft[id] ?? ''}
                      disabled={readOnly}
                      onChange={(event) => setDraft({ ...draft, [id]: event.target.value })}
                      dir={field.key === 'company_name_ar' ? 'rtl' : 'ltr'}
                    />
                  </Field>
                )
              })}
            </div>
          ) : null}
        </Card>

        <Card>
          <CardHeader title={locale === 'ar' ? 'العملات' : 'Currencies'} />
          {currenciesQuery.isLoading ? <Loading /> : null}
          {currenciesQuery.isError ? (
            <ErrorState message={errorMessage(currenciesQuery.error)} onRetry={() => void currenciesQuery.refetch()} />
          ) : null}
          {currenciesQuery.data ? (
            <Table>
              <thead>
                <tr>
                  <Th>{t('code')}</Th>
                  <Th>{t('name')}</Th>
                  <Th>{locale === 'ar' ? 'الخانات العشرية' : 'Decimals'}</Th>
                  <Th>{t('status')}</Th>
                </tr>
              </thead>
              <tbody>
                {currenciesQuery.data.map((currency) => (
                  <tr key={currency.code}>
                    <Td className="tabular font-medium">{currency.code}</Td>
                    <Td>{currency.name}</Td>
                    <Td className="tabular">{currency.exponent}</Td>
                    <Td>
                      <Badge tone={currency.is_active ? 'positive' : 'neutral'}>
                        {currency.is_active ? (locale === 'ar' ? 'نشط' : 'Active') : locale === 'ar' ? 'موقوف' : 'Inactive'}
                      </Badge>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      </div>

      <AccountingPolicyPanel />

      <Card className="mt-6">
        <CardHeader
          title={t('branches')}
          subtitle={locale === 'ar' ? 'كل فرع له نطاق بيانات وصلاحيات مستقل' : 'Each branch has its own data scope and permissions'}
          actions={
            can('branches.create') ? (
              <Button variant="secondary" onClick={() => setBranchOpen(true)}>
                {locale === 'ar' ? 'فرع جديد' : 'New branch'}
              </Button>
            ) : null
          }
        />
        {branchesQuery.isLoading ? <Loading /> : null}
        {branchesQuery.isError ? (
          <ErrorState message={errorMessage(branchesQuery.error)} onRetry={() => void branchesQuery.refetch()} />
        ) : null}
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
                    <p className="font-medium">{locale === 'ar' ? branch.name_ar : branch.name_en}</p>
                    <p className="text-xs text-text-subtle">{locale === 'ar' ? branch.name_en : branch.name_ar}</p>
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
      </Card>

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
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('code')} required>
            <Input value={branchForm.code} onChange={(event) => setBranchForm({ ...branchForm, code: event.target.value })} dir="ltr" />
          </Field>
          <Field label={locale === 'ar' ? 'نمط المحاسبة' : 'Accounting mode'}>
            <Select
              value={branchForm.accounting_mode}
              onChange={(event) =>
                setBranchForm({ ...branchForm, accounting_mode: event.target.value as Branch['accounting_mode'] })
              }
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
    </div>
  )
}
