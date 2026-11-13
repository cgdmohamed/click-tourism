import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { enabledPatch } from '../lib/assistantSetup'
import { useFeatureFlags } from '../lib/useSetting'
import { Icon } from './icons'
import { Badge, Card, InlineNotice } from './ui'

const HINT_ID = 'assistant-dashboard-hint'

/**
 * Dashboard master switch for the read-only assistant (ADR-0017). It only writes
 * `assistant.enabled`; the provider, model and data-processing acceptance are
 * configured in Settings > Assistant. Disabled until the key exists and the notice
 * is accepted, so a click can never produce a half-configured assistant.
 */
export function AssistantToggleCard() {
  const { t } = useI18n()
  const { can } = useAuth()
  const { flags, loaded, failed } = useFeatureFlags()
  const queryClient = useQueryClient()
  const [error, setError] = useState('')

  const toggle = useMutation({
    mutationFn: async (next: boolean) => {
      await api.put('/settings', { settings: [enabledPatch(next)] })
    },
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  if (!can('settings.update')) return null

  const on = flags?.assistant_enabled === true
  const configured = flags?.assistant_key_configured === true && flags?.assistant_accepted === true
  const blocked = !configured
  const model = (flags?.assistant_model ?? '').trim()
  const provider = flags?.assistant_provider === 'openai' ? 'OpenAI' : (flags?.assistant_provider ?? '')
  const hint = failed
    ? t('assistantFlagsUnavailable')
    : !loaded
      ? t('loading')
      : blocked
        ? t('assistantNotConfiguredHint')
        : null

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-12 bg-surface-selected text-brand-1-accent">
            <Icon name="sparkles" size={18} />
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="heading-small">{t('assistant')}</h2>
              <Badge tone={on ? 'positive' : 'neutral'}>{on ? t('assistantStateOn') : t('assistantStateOff')}</Badge>
              <Badge tone="brand">{model || t('assistantModelNotSelected')}</Badge>
              {provider ? <Badge tone="neutral">{provider}</Badge> : null}
            </div>
            <p className="text-small mt-1 text-text-subtle">{t('assistantSubtitle')}</p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {can('settings.view') ? (
            <Link to="/settings?tab=assistant" className="text-small font-medium text-brand-1-accent hover:underline">
              {t('assistantConfigure')}
            </Link>
          ) : null}
          <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={t('assistant')}
            aria-describedby={hint ? HINT_ID : undefined}
            aria-busy={toggle.isPending}
            title={blocked && loaded ? t('assistantNotConfiguredHint') : undefined}
            disabled={blocked || toggle.isPending}
            onClick={() => toggle.mutate(!on)}
            className="inline-flex items-center gap-2 rounded-12 outline-none focus-visible:ring-2 focus-visible:ring-border-focus disabled:cursor-not-allowed disabled:opacity-50"
          >
            <span
              className={
                on
                  ? 'flex h-6 w-11 items-center justify-end rounded-full bg-action-primary-background p-0.5 transition'
                  : 'flex h-6 w-11 items-center justify-start rounded-full bg-border-default p-0.5 transition'
              }
            >
              <span className="h-5 w-5 rounded-full bg-white shadow-1" />
            </span>
            <span className="text-small font-medium text-text-default">{on ? t('assistantStateOn') : t('assistantStateOff')}</span>
          </button>
        </div>
      </div>

      {hint ? (
        <p id={HINT_ID} className="text-small px-4 pb-4 text-text-subtle">
          {hint}
        </p>
      ) : null}
      {error ? (
        <div className="px-4 pb-4" role="alert">
          <InlineNotice tone="negative">{error}</InlineNotice>
        </div>
      ) : null}
    </Card>
  )
}
