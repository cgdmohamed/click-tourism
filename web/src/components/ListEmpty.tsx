import type { ReactNode } from 'react'
import type { FilterState } from './FilterDrawer'
import { EmptyState } from './ui'

/**
 * The empty state of a filtered list: when a filter or the search box is active it says so and offers
 * "Clear filters"; otherwise it is the plain empty state (with the page's own title/hint/action).
 */
export function ListEmpty({
  filters,
  search,
  onClearSearch,
  title,
  hint,
  action,
}: {
  filters?: FilterState
  search?: string
  onClearSearch?: () => void
  title?: string
  hint?: string
  action?: ReactNode
}) {
  const filtered = (filters?.count ?? 0) > 0 || Boolean(search && search.trim() !== '')
  if (!filtered) return <EmptyState title={title} hint={hint} action={action} />
  return (
    <EmptyState
      variant="filtered"
      onClear={() => {
        filters?.clearAll()
        onClearSearch?.()
      }}
    />
  )
}
