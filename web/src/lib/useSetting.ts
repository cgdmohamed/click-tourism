import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { api } from './api'

type Setting = { group: string; key: string; value: string | null; is_secret: boolean }

export type FeatureFlags = {
  tax_enabled: boolean
  tax_default_rate: string
  credit_limit_policy: 'off' | 'warn' | 'block'
  base_currency: string
  refund_segregation: boolean
}

/**
 * Read one installation setting. Shares the ['settings'] query with the
 * Settings page, so it costs a single GET /settings per session.
 * `GET /settings` needs `settings.view`; without it (or while loading) the
 * fallback is returned and the API remains the authority on enforcement.
 * Prefer `useFeatureFlags` for the flags it exposes: it works for every user.
 */
export function useSetting(group: string, key: string, fallback: string): { value: string; loaded: boolean } {
  const { can } = useAuth()
  const query = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Setting[] }>('/settings')
      return data.data
    },
    enabled: can('settings.view'),
    staleTime: 60_000,
  })
  const found = query.data?.find((setting) => setting.group === group && setting.key === key)
  return { value: found?.value ?? fallback, loaded: query.isSuccess }
}

/**
 * Non-sensitive flags from `GET /feature-flags`, readable by any signed-in
 * user (no `settings.view`). `flags` is undefined until loaded or on error.
 */
export function useFeatureFlags(): { flags: FeatureFlags | undefined; loaded: boolean; failed: boolean } {
  const query = useQuery({
    queryKey: ['feature-flags'],
    queryFn: async () => {
      const { data } = await api.get<{ data: FeatureFlags }>('/feature-flags')
      return data.data
    },
    staleTime: 60_000,
    retry: false,
  })
  return { flags: query.data, loaded: query.isSuccess, failed: query.isError }
}

/**
 * Pure resolution of "may tax be entered": the feature flag when known,
 * otherwise the legacy settings value, otherwise optimistic (true).
 */
export function resolveTaxEnabled(
  flags: Pick<FeatureFlags, 'tax_enabled'> | undefined,
  legacy: { value: string; loaded: boolean },
): { enabled: boolean; loaded: boolean } {
  if (flags) return { enabled: flags.tax_enabled, loaded: true }
  return { enabled: !legacy.loaded || legacy.value === 'true', loaded: legacy.loaded }
}

/** Pure resolution of the base currency: the flag, else the legacy value. */
export function resolveBaseCurrency(flags: Pick<FeatureFlags, 'base_currency'> | undefined, legacy: string): string {
  return flags?.base_currency || legacy
}

/** Whether tax may be entered. Uses /feature-flags; falls back to /settings only on error. */
export function useTaxEnabled(): { enabled: boolean; loaded: boolean } {
  const { flags } = useFeatureFlags()
  const legacy = useSetting('tax', 'enabled', 'true')
  return resolveTaxEnabled(flags, legacy)
}

/** Installation base currency. Uses /feature-flags; falls back to /settings only on error. */
export function useBaseCurrency(): string {
  const { flags } = useFeatureFlags()
  const legacy = useSetting('general', 'base_currency', 'EGP')
  return resolveBaseCurrency(flags, legacy.value)
}

/** Configured VAT percentage, never typed manually on invoice lines. */
export function useDefaultTaxRate(): { rate: string; loaded: boolean } {
  const { flags, loaded } = useFeatureFlags()
  const legacy = useSetting('tax', 'default_rate', '0')
  return { rate: flags?.tax_default_rate ?? (legacy.loaded ? legacy.value : '0'), loaded: loaded || legacy.loaded }
}
