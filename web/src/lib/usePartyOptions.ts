import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { SelectOption } from '../components/ui'
import { api } from './api'

export type PartyKind = 'customer' | 'supplier' | 'employee'

type PartyRow = {
  id: string
  name_ar: string
  name_en: string
  phone?: string | null
  internal_code?: string | null
}

const ENDPOINTS: Record<PartyKind, string> = {
  customer: '/customers',
  supplier: '/suppliers',
  employee: '/employees',
}

/**
 * Server-side search for a party picker.
 *
 * The first page of 100 rows is only a starting point: typing calls the API
 * with `search`, so a customer beyond the first page can still be found. The
 * currently selected party is cached so its label stays visible while the
 * list shows a different search result.
 */
export function usePartyOptions(
  kind: PartyKind,
  { enabled = true, value = '', locale }: { enabled?: boolean; value?: string; locale: 'ar' | 'en' },
): { options: SelectOption[]; setSearch: (query: string) => void; loading: boolean } {
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<SelectOption[]>([])

  const query = useQuery({
    queryKey: ['party-options', kind, search],
    enabled,
    queryFn: async () => {
      const { data } = await api.get<{ data: PartyRow[] }>(ENDPOINTS[kind], {
        params: { per_page: 100, search: search.trim() || undefined },
      })
      return data.data
    },
  })

  const fetched = useMemo<SelectOption[]>(
    () =>
      (query.data ?? []).map((party) => ({
        value: party.id,
        label: locale === 'ar' ? party.name_ar : party.name_en,
        hint: party.phone ?? party.internal_code ?? undefined,
      })),
    [query.data, locale],
  )

  // Keep the selected label after the user searches for something else: the
  // fetched page may no longer contain the chosen party.
  useEffect(() => {
    if (!value) return
    const option = fetched.find((item) => item.value === value)
    if (!option) return
    setPicked((current) => (current.some((item) => item.value === value) ? current : [...current, option]))
  }, [value, fetched])

  const options = useMemo(() => {
    const seen = new Set(fetched.map((option) => option.value))
    return [...fetched, ...picked.filter((option) => !seen.has(option.value))]
  }, [fetched, picked])

  return { options, setSearch, loading: query.isLoading }
}
