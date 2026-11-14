import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { activeCurrencies, type CurrencyRow } from '../lib/currencies'
import { resolveTab } from '../lib/tabs'
import { DecisionsPanel } from '../components/DecisionsPanel'
import AccountingPolicyPanel from '../components/AccountingPolicyPanel'
import IntegrationsPanel from '../components/IntegrationsPanel'
import ExtractionSwitchCard from '../components/ExtractionSwitchCard'
import { McpTab } from './settings/McpTab'
import { Button, Card, CardHeader, ErrorState, Loading, PageHeader, TabPanel, Tabs, type TabItem } from '../components/ui'
import { AssistantTab } from './settings/AssistantTab'
import { BranchesTab } from './settings/BranchesTab'
import { CurrenciesTab } from './settings/CurrenciesTab'
import { FieldsGrid } from './settings/FieldsGrid'
import { LogoCard } from './settings/LogoCard'
import { editable, FORM_TABS, keyOf, pendingChanges, type Setting, type SettingsTab } from './settings/fields'

const TAB_PREFIX = 'settings'

export default function Settings() {
  const { t, locale } = useI18n()
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const ar = locale === 'ar'
  const L = (a: string, e: string) => (ar ? a : e)

  const tabs: Array<TabItem & { id: SettingsTab }> = [
    { id: 'general', label: L('عام', 'General') },
    { id: 'currencies', label: L('العملات', 'Currencies') },
    { id: 'accounting', label: L('المحاسبة', 'Accounting') },
    { id: 'tax', label: L('الضريبة', 'Tax') },
    { id: 'crm', label: L('العملاء المحتملون', 'CRM') },
    { id: 'branches', label: t('branches') },
    ...(can('settings.view')
      ? [
          { id: 'integrations' as const, label: L('التكاملات', 'Integrations') },
          { id: 'assistant' as const, label: t('assistant') },
          { id: 'mcp' as const, label: 'MCP' },
        ]
      : []),
    { id: 'decisions', label: L('القرارات', 'Decisions') },
  ]
  const active = resolveTab(searchParams.get('tab'), tabs.map((tab) => tab.id), 'general') as SettingsTab
  const isFormTab = FORM_TABS.includes(active)

  const selectTab = (id: string) => {
    setError('')
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (id === 'general') next.delete('tab')
        else next.set('tab', id)
        return next
      },
      { replace: true },
    )
  }

  // Only the tabs that edit key/value settings (and the Accounting policy panel) need GET /settings.
  const settingsQuery = useQuery({
    queryKey: ['settings'],
    enabled: isFormTab,
    queryFn: async () => {
      const { data } = await api.get<{ data: Setting[] }>('/settings')
      return data.data
    },
  })

  // The base currency picker lists active currencies; only the General tab asks for them.
  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    enabled: active === 'general',
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
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

  const changes = useMemo(() => pendingChanges(draft, values), [draft, values])
  const readOnly = !can('settings.update')

  const save = useMutation({
    mutationFn: async () => {
      if (changes.length === 0) return null
      const { data } = await api.put('/settings', { settings: changes })
      return data
    },
    onSuccess: async () => {
      setSaved(true)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
      // The saved company name, tax and base-currency flags are read elsewhere in the app.
      await queryClient.invalidateQueries({ queryKey: ['branding'] })
      await queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
      await refresh()
      window.setTimeout(() => setSaved(false), 2500)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const onChange = (id: string, value: string) => setDraft((current) => ({ ...current, [id]: value }))
  const fieldsOf = (group: string) => editable.filter((field) => field.group === group)
  const baseOptions = useMemo(() => {
    const codes = activeCurrencies(currenciesQuery.data).map((currency) => currency.code)
    const current = values['general.base_currency']
    return current && !codes.includes(current) ? [current, ...codes] : codes
  }, [currenciesQuery.data, values])

  const formBody = (children: ReactNode) => (
    <>
      {settingsQuery.isLoading ? <Loading /> : null}
      {settingsQuery.isError ? <ErrorState message={errorMessage(settingsQuery.error)} onRetry={() => void settingsQuery.refetch()} /> : null}
      {settingsQuery.data ? children : null}
    </>
  )

  return (
    <div>
      <PageHeader
        title={t('settings')}
        subtitle={L('إعدادات التثبيت والفروع والعملات', 'Installation, branch, and currency configuration')}
        icon="settings"
      />

      {error ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text" role="alert">
          {error}
        </p>
      ) : null}
      {readOnly ? (
        <p className="mb-4 rounded-12 border border-warning-border bg-warning-background px-3 py-2 text-medium text-warning-text">{t('permissionDenied')}</p>
      ) : null}

      <Tabs tabs={tabs} active={active} onChange={selectTab} label={t('settings')} idPrefix={TAB_PREFIX} />

      <TabPanel idPrefix={TAB_PREFIX} id={active}>
        {active === 'general'
          ? formBody(
              <>
                <Card>
                  <CardHeader title={L('الشركة واللغة والعملة', 'Company, language and currency')} />
                  <FieldsGrid
                    fields={fieldsOf('general')}
                    draft={draft}
                    values={values}
                    onChange={onChange}
                    readOnly={readOnly}
                    selectOptions={{ 'general.base_currency': baseOptions }}
                  />
                </Card>
                <LogoCard />
              </>,
            )
          : null}

        {active === 'currencies' ? <CurrenciesTab /> : null}

        {active === 'accounting'
          ? formBody(
              <>
                <Card>
                  <CardHeader title={L('السياسة المحاسبية', 'Accounting settings')} />
                  <FieldsGrid fields={fieldsOf('accounting')} draft={draft} values={values} onChange={onChange} readOnly={readOnly} />
                </Card>
                <AccountingPolicyPanel />
              </>,
            )
          : null}

        {active === 'tax'
          ? formBody(
              <Card>
                <CardHeader title={L('الضريبة', 'Tax')} />
                <FieldsGrid fields={fieldsOf('tax')} draft={draft} values={values} onChange={onChange} readOnly={readOnly} />
              </Card>,
            )
          : null}

        {active === 'crm'
          ? formBody(
              <Card>
                <CardHeader title={L('العملاء المحتملون', 'CRM')} />
                <FieldsGrid fields={fieldsOf('crm')} draft={draft} values={values} onChange={onChange} readOnly={readOnly} />
              </Card>,
            )
          : null}

        {active === 'branches' ? <BranchesTab /> : null}

        {active === 'integrations' && can('settings.view') ? (
          <>
            <IntegrationsPanel />
          </>
        ) : null}

        {active === 'mcp' && can('settings.view') ? <McpTab /> : null}

        {active === 'assistant' && can('settings.view') ? (
          <>
            <AssistantTab />
            <ExtractionSwitchCard />
          </>
        ) : null}

        {active === 'decisions' ? <DecisionsPanel /> : null}
      </TabPanel>

      {isFormTab && !readOnly && (changes.length > 0 || save.isPending || saved) ? (
        <div
          className="no-print sticky bottom-0 z-20 -mx-4 mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle bg-surface-default/95 px-4 py-3 shadow-3 backdrop-blur lg:-mx-8 lg:px-8"
          role="region"
          aria-label={L('التغييرات غير المحفوظة', 'Unsaved changes')}
        >
          <p className="text-medium text-text-default" role="status">
            {changes.length > 0
              ? L(`تغييرات غير محفوظة (${changes.length})`, `Unsaved changes (${changes.length})`)
              : saved
                ? t('saved')
                : ''}
          </p>
          <div className="flex items-center gap-2">
            <Button variant="secondary" onClick={() => setDraft(values)} disabled={save.isPending || changes.length === 0}>
              {L('تجاهل', 'Discard')}
            </Button>
            <Button icon="save" onClick={() => save.mutate()} disabled={save.isPending || changes.length === 0}>
              {save.isPending ? t('saving') : t('save')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
