import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { api } from './api'

type Setting = { group: string; key: string; value: string | null; is_secret: boolean }

/**
 * Read one installation setting. Shares the ['settings'] query with the
 * Settings page, so it costs a single GET /settings per session.
 * `GET /settings` needs `settings.view`; without it (or while loading) the
 * fallback is returned and the API remains the authority on enforcement.
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

/** Whether tax may be entered. Optimistic (true) until the setting is known. */
export function useTaxEnabled(): { enabled: boolean; loaded: boolean } {
  const { value, loaded } = useSetting('tax', 'enabled', 'true')
  return { enabled: !loaded || value === 'true', loaded }
}
