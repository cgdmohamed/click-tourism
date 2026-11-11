import { useCallback } from 'react'
import type { SelectOption } from '../components/ui'
import { useRemoteOptions } from './useRemoteOptions'

export type PartyKind = 'customer' | 'supplier' | 'employee'

export type PartyRow = {
  id: string
  name_ar: string
  name_en: string
  phone?: string | null
  internal_code?: string | null
  settlement_currency?: string | null
}

const ENDPOINTS: Record<PartyKind, string> = {
  customer: '/customers',
  supplier: '/suppliers',
  employee: '/employees',
}

/**
 * Server-side search for a party picker (see `useRemoteOptions`).
 *
 * The first page of 100 rows is only a starting point: typing calls the API
 * with `search`, so a customer beyond the first page can still be found. The
 * currently selected party is cached so its label stays visible while the
 * list shows a different search result.
 */
export function usePartyOptions(
  kind: PartyKind,
  { enabled = true, value = '', locale }: { enabled?: boolean; value?: string; locale: 'ar' | 'en' },
): { options: SelectOption[]; rows: PartyRow[]; setSearch: (query: string) => void; loading: boolean; hasMore: boolean } {
  const toOption = useCallback(
    (party: PartyRow): SelectOption => ({
      value: party.id,
      label: locale === 'ar' ? party.name_ar : party.name_en,
      hint: party.phone ?? party.internal_code ?? undefined,
    }),
    [locale],
  )

  return useRemoteOptions<PartyRow>({
    endpoint: ENDPOINTS[kind],
    toOption,
    value,
    enabled,
    keyPrefix: 'party-options',
  })
}
