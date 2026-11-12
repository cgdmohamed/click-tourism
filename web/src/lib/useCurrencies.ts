import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import { activeCurrencies, type CurrencyRow } from './currencies'
import { registerCurrencyExponents } from './format'

const FALLBACK = ['EGP', 'SAR', 'USD']

/**
 * Currency codes offered for NEW documents: active currencies from
 * GET /currencies (managed in Settings > Currencies). While loading, or if the
 * request fails, the three built-in currencies are offered and the API stays
 * the authority.
 */
export function useActiveCurrencyCodes(): string[] {
  const query = useQuery({
    queryKey: ['currencies', 'active-codes'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      registerCurrencyExponents(data.data)
      return activeCurrencies(data.data).map((row) => row.code)
    },
    staleTime: 60_000,
  })
  return query.data && query.data.length > 0 ? query.data : FALLBACK
}

/**
 * Keeps the money formatter aware of the decimals of currencies added in
 * Settings (built-in ones are fixed). Mounted once in the app shell.
 */
export function useSyncCurrencyExponents(enabled: boolean): void {
  useQuery({
    queryKey: ['currencies', 'exponents'],
    enabled,
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      registerCurrencyExponents(data.data)
      return data.data.length
    },
    staleTime: 5 * 60_000,
    retry: false,
  })
}
