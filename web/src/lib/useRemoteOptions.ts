import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { SelectOption } from '../components/ui'
import { api } from './api'
import { hasMoreResults, mergeOptions, pickerParams, rememberOption, type PageMeta } from './remoteOptions'

/**
 * Server-side search for any picker backed by a paginated list endpoint.
 *
 * The first page is only a starting point: `setSearch` (wire it to
 * `SearchableSelect.onSearch`, which debounces) re-queries the API with
 * `search`, so a record beyond the first page can still be found. The selected
 * option is remembered, so its label stays visible while the list shows a
 * different result. `hasMore` is true when the server holds more rows than
 * were loaded, so the picker can say "refine the search". `rows` are the
 * currently loaded rows (after `select`), for reading extra fields of a pick.
 *
 * `toOption` and `params` should be stable (module level or memoised); `params`
 * is part of the query key.
 */
export function useRemoteOptions<Row>({
  endpoint,
  params,
  toOption,
  value = '',
  enabled = true,
  keyPrefix = 'remote-options',
  select,
  known,
}: {
  endpoint: string
  params?: Record<string, unknown>
  toOption: (row: Row) => SelectOption
  value?: string
  enabled?: boolean
  keyPrefix?: string
  /** Client-side filter applied to the rows (e.g. same-currency invoices only). */
  select?: (row: Row) => boolean
  /** Options already known to the page (e.g. the saved value of a record being edited), kept visible while selected. */
  known?: SelectOption[]
}): { options: SelectOption[]; rows: Row[]; setSearch: (query: string) => void; loading: boolean; hasMore: boolean } {
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<SelectOption[]>([])
  const term = search.trim()

  const query = useQuery({
    queryKey: [keyPrefix, endpoint, params ?? null, term],
    enabled,
    queryFn: async () => {
      const { data } = await api.get<{ data: Row[]; meta?: PageMeta }>(endpoint, { params: pickerParams(params, term) })
      return data
    },
  })

  const rows = useMemo<Row[]>(() => {
    const all = query.data?.data ?? []
    return select ? all.filter(select) : all
  }, [query.data, select])

  const fetched = useMemo<SelectOption[]>(() => rows.map(toOption), [rows, toOption])

  useEffect(() => {
    if (!value) return
    const option = fetched.find((item) => item.value === value)
    if (option) setPicked((current) => rememberOption(current, option))
  }, [value, fetched])

  // Only the current selection is kept: a pick made under other params must not leak in.
  const options = useMemo(() => mergeOptions(fetched, [...picked, ...(known ?? [])].filter((option) => option.value === value)), [fetched, picked, known, value])

  return {
    options,
    rows,
    setSearch,
    loading: query.isLoading,
    hasMore: hasMoreResults(query.data?.meta, query.data?.data.length ?? 0),
  }
}
