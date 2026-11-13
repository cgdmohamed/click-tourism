import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useAuth } from '../../auth/AuthProvider'
import { useI18n } from '../../i18n'
import type { MessageKey } from '../../i18n'
import { api, errorMessage } from '../../lib/api'
import {
  acceptancePatch,
  assistantChanges,
  canEnableAssistant,
  isAccepted,
  modelsErrorState,
  readAssistantSettings,
  recommendedModel,
  resolveModel,
} from '../../lib/assistantSetup'
import type { AssistantModels, SettingRow } from '../../lib/assistantSetup'
import { formatDateTime } from '../../lib/format'
import { useFeatureFlags } from '../../lib/useSetting'
import { Badge, Button, Card, CardHeader, ErrorState, Field, InlineNotice, Input, Loading, Select } from '../../components/ui'

/**
 * Settings > Assistant (ADR-0017). Saves itself instead of joining the page-level draft:
 * the provider key is read from the server environment only, and the data-processing notice
 * must be accepted before the assistant can be switched on.
 */
export function AssistantTab() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const { flags } = useFeatureFlags()
  const queryClient = useQueryClient()
  const readOnly = !can('settings.update')

  const [enabled, setEnabled] = useState(false)
  // null means "nothing picked yet": the model then follows the stored value or the recommendation,
  // so settings and the model list can finish loading in any order without overwriting each other.
  const [modelDraft, setModelDraft] = useState<string | null>(null)
  const [acceptedAt, setAcceptedAt] = useState('')
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: SettingRow[] }>('/settings')
      return data.data
    },
    staleTime: 60_000,
  })

  const current = useMemo(() => readAssistantSettings(settingsQuery.data ?? []), [settingsQuery.data])

  // The stored settings are the draft until the user edits it; a refetch (after saving) resets it.
  useEffect(() => {
    if (!settingsQuery.isSuccess) return
    setEnabled(current.enabled === 'true')
    setAcceptedAt(current.acceptedAt)
    setModelDraft(null)
  }, [settingsQuery.isSuccess, current])

  // Called automatically on mount: reports whether the provider key exists and which models it offers.
  const modelsQuery = useQuery({
    queryKey: ['assistant-models'],
    queryFn: async () => {
      const { data } = await api.get<{ data: AssistantModels }>('/assistant/models')
      return data.data
    },
    retry: false,
  })

  const listing = modelsQuery.data
  const models = listing?.models ?? []
  const failure = modelsQuery.isError
    ? modelsErrorState(
        axios.isAxiosError(modelsQuery.error) ? modelsQuery.error.response?.status : undefined,
        axios.isAxiosError<{ code?: string }>(modelsQuery.error) ? modelsQuery.error.response?.data?.code : undefined,
      )
    : null
  const keyConfigured: boolean | undefined = listing
    ? listing.key_configured
    : failure === 'not-configured'
      ? false
      : flags?.assistant_key_configured

  // Never copied into state: the stored model, the recommendation and the user's pick resolve here,
  // whichever of /settings and /assistant/models arrives first.
  const model = resolveModel(modelDraft, current.model, models)
  const listed = models.some((entry) => entry.id === model)

  const provider = listing?.provider || flags?.assistant_provider || 'none'
  const providerName = provider === 'openai' || provider === 'none' ? 'OpenAI' : provider
  const recommended = listing ? (listing.recommended ?? recommendedModel(listing.models)) : null
  const noticeKey: MessageKey = flags?.assistant_redact_pii === false ? 'assistantNoticeRaw' : 'assistantNoticeMasked'
  const privacyUrl = flags?.assistant_privacy_url ?? ''

  const accepted = isAccepted(acceptedAt)
  const enableReady = canEnableAssistant({ model, accepted, keyConfigured: keyConfigured === true })
  const switchLocked = readOnly || (!enabled && !enableReady)
  const changes = assistantChanges(current, { enabled, model, acceptedAt })

  const save = useMutation({
    mutationFn: async () => {
      const { data } = await api.put('/settings', { settings: changes })
      return data
    },
    onSuccess: async () => {
      setSaved(true)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
      await queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
      window.setTimeout(() => setSaved(false), 2500)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const fill = (key: MessageKey, values: Record<string, string>) =>
    t(key).replace(/\{(\w+)\}/g, (_, name: string) => values[name] ?? '')

  const keyLabel =
    keyConfigured === true ? t('assistantKeyConfigured') : keyConfigured === false ? t('assistantKeyMissing') : t('assistantKeyChecking')
  const keyTone = keyConfigured === true ? 'positive' : keyConfigured === false ? 'warning' : 'neutral'
  const switchHint = readOnly
    ? t('permissionDenied')
    : !enabled && !enableReady
      ? t('assistantEnableHint')
      : enabled
        ? t('assistantStateOn')
        : t('assistantStateOff')

  return (
    <Card>
      <CardHeader
        title={t('assistantSetupTitle')}
        subtitle={t('assistantSetupSubtitle')}
        icon="sparkles"
        actions={
          <Button icon="save" disabled={readOnly || save.isPending || changes.length === 0} onClick={() => save.mutate()}>
            {save.isPending ? t('saving') : saved ? t('saved') : t('save')}
          </Button>
        }
      />

      {settingsQuery.isLoading ? <Loading /> : null}
      {settingsQuery.isError ? (
        <ErrorState message={errorMessage(settingsQuery.error)} onRetry={() => void settingsQuery.refetch()} />
      ) : null}
      {error ? (
        <div className="px-5 pt-4" role="alert">
          <InlineNotice tone="negative">{error}</InlineNotice>
        </div>
      ) : null}

      {settingsQuery.isSuccess ? (
        <div className="flex flex-col gap-6 px-5 py-5">
          <section className="flex flex-col gap-3" aria-label={t('assistantKeyStatus')}>
            <div className="flex flex-wrap items-center gap-2">
              <span className="label">{t('assistantKeyStatus')}</span>
              <Badge tone={keyTone}>{keyLabel}</Badge>
              <Badge tone="brand">{providerName}</Badge>
            </div>
            <p className="text-small text-text-subtle">{t('assistantKeyEnvNote')}</p>

            {keyConfigured === false ? (
              <InlineNotice tone="warning">
                <p className="font-semibold">{t('assistantKeyMissingTitle')}</p>
                <p>{t('assistantKeyMissingSteps')}</p>
                <p className="text-small mt-1 font-mono" dir="ltr">
                  {t('assistantDocsLabel')}
                </p>
                <div className="mt-2">
                  <Button variant="secondary" icon="refresh" disabled={modelsQuery.isFetching} onClick={() => void modelsQuery.refetch()}>
                    {t('retry')}
                  </Button>
                </div>
              </InlineNotice>
            ) : null}

            {failure === 'provider-error' || failure === 'forbidden' || failure === 'unavailable' ? (
              <InlineNotice tone={failure === 'forbidden' ? 'negative' : 'warning'}>
                {failure === 'forbidden'
                  ? t('permissionDenied')
                  : failure === 'provider-error'
                    ? t('assistantModelsError')
                    : t('assistantModelsUnavailable')}
              </InlineNotice>
            ) : null}
          </section>

          <section className="flex flex-col gap-3 border-t border-border-subtle pt-5" aria-label={t('assistantModel')}>
            <div className="grid items-start gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
              <Field label={listing ? t('assistantModel') : t('assistantModelManual')} hint={t('assistantModelHint')}>
                {listing ? (
                  <Select
                    value={model}
                    dir="ltr"
                    disabled={readOnly || models.length === 0}
                    onChange={(event) => setModelDraft(event.target.value)}
                  >
                    {models.length === 0 ? <option value="">{t('assistantNoModels')}</option> : null}
                    {/* A stored or typed model the account does not list stays selectable (never silently dropped). */}
                    {model !== '' && !listed ? <option value={model}>{model}</option> : null}
                    {models.map((entry) => (
                      <option key={entry.id} value={entry.id}>
                        {entry.id}
                        {entry.recommended ? ` — ${t('assistantRecommended')}` : ''}
                      </option>
                    ))}
                  </Select>
                ) : (
                  <Input
                    value={model}
                    dir="ltr"
                    autoComplete="off"
                    placeholder="gpt-4.1-mini"
                    disabled={readOnly}
                    onChange={(event) => setModelDraft(event.target.value)}
                  />
                )}
              </Field>
              <div className="sm:pt-9">
                <Button
                  type="button"
                  variant="secondary"
                  icon="refresh"
                  disabled={modelsQuery.isFetching}
                  onClick={() => void modelsQuery.refetch()}
                >
                  {modelsQuery.isFetching ? t('loading') : t('assistantReloadModels')}
                </Button>
              </div>
            </div>
            {recommended ? (
              <p className="text-medium text-text-subtle" dir="ltr">
                {fill('assistantRecommendedModel', { model: recommended })}
              </p>
            ) : null}
          </section>

          <section className="flex flex-col gap-2 border-t border-border-subtle pt-5" aria-label={t('assistantNoticeTitle')}>
            <h3 className="label">{t('assistantNoticeTitle')}</h3>
            <p className="text-medium text-text-subtle">
              {fill(noticeKey, { provider: providerName })}{' '}
              {privacyUrl ? (
                <a href={privacyUrl} target="_blank" rel="noopener noreferrer" className="underline">
                  {t('assistantPrivacyLink')}
                </a>
              ) : null}
            </p>
            <label className="text-medium flex items-start gap-2 text-text-default">
              <input
                id="assistant-accept"
                type="checkbox"
                className="mt-1"
                checked={accepted}
                disabled={readOnly}
                aria-describedby="assistant-accept-hint"
                onChange={(event) => setAcceptedAt(acceptancePatch(event.target.checked).value)}
              />
              <span>{t('assistantAccept')}</span>
            </label>
            <p id="assistant-accept-hint" className="text-small text-text-subtle">
              {accepted ? fill('assistantAcceptedAt', { date: formatDateTime(acceptedAt, locale) }) : t('assistantAcceptHint')}
            </p>
          </section>

          <section className="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-5">
            <div className="min-w-0">
              <p className="label">{t('assistantEnable')}</p>
              <p id="assistant-enable-hint" className="text-small text-text-subtle">
                {switchHint}
              </p>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={enabled}
              aria-label={t('assistantEnable')}
              aria-describedby={!readOnly && !enabled && !enableReady ? 'assistant-enable-hint' : undefined}
              title={!readOnly && !enabled && !enableReady ? t('assistantEnableHint') : undefined}
              disabled={switchLocked}
              onClick={() => setEnabled((value) => !value)}
              className="inline-flex items-center gap-2 rounded-12 outline-none focus-visible:ring-2 focus-visible:ring-border-focus disabled:cursor-not-allowed"
            >
              <span
                className={
                  enabled
                    ? 'flex h-6 w-11 items-center justify-end rounded-full bg-action-primary-background p-0.5 transition'
                    : 'flex h-6 w-11 items-center justify-start rounded-full bg-border-default p-0.5 transition'
                }
              >
                <span className="h-5 w-5 rounded-full bg-white shadow-1" />
              </span>
              <span className="text-small font-medium text-text-default">{enabled ? t('assistantStateOn') : t('assistantStateOff')}</span>
            </button>
          </section>

          <p className="text-small border-t border-border-subtle pt-5 text-text-subtle">{t('assistantLimitsNote')}</p>
        </div>
      ) : null}
    </Card>
  )
}
