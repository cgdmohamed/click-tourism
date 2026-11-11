import type { SelectOption } from '../components/ui'

export type PageMeta = { current_page?: number; last_page?: number; total?: number; per_page?: number }

/** Page size used by every server-side picker: large enough to browse, small enough to stay fast. */
export const PICKER_PAGE_SIZE = 100

/** Query params for a picker request. Empty/blank search is omitted so cached "first page" requests are shared. */
export function pickerParams(
  base: Record<string, unknown> | undefined,
  search: string,
  perPage: number = PICKER_PAGE_SIZE,
): Record<string, unknown> {
  const params: Record<string, unknown> = { ...base, per_page: perPage }
  const term = search.trim()
  if (term) params.search = term
  return params
}

/** True when the server holds more rows than the page that was loaded. */
export function hasMoreResults(meta: PageMeta | undefined, loaded: number): boolean {
  if (!meta) return false
  if (typeof meta.last_page === 'number' && typeof meta.current_page === 'number') {
    return meta.last_page > meta.current_page
  }
  if (typeof meta.total === 'number') return meta.total > loaded
  return false
}

/** Fetched options first, then remembered selections that the current result no longer contains. */
export function mergeOptions(fetched: SelectOption[], picked: SelectOption[]): SelectOption[] {
  const seen = new Set(fetched.map((option) => option.value))
  return [...fetched, ...picked.filter((option) => !seen.has(option.value))]
}

/** Adds `option` to the remembered selections once. */
export function rememberOption(current: SelectOption[], option: SelectOption): SelectOption[] {
  return current.some((item) => item.value === option.value) ? current : [...current, option]
}
