import type { ReactNode } from 'react'
import { useI18n } from '../i18n'
import type { FilterState } from './FilterDrawer'
import { Button, EmptyState } from './ui'

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
  const { locale } = useI18n()
  const ar = locale === 'ar'
  const filtered = (filters?.count ?? 0) > 0 || Boolean(search && search.trim() !== '')
  if (!filtered) return <EmptyState title={title} hint={hint} action={action} />
  return (
    <EmptyState
      title={ar ? 'لا توجد نتائج مطابقة' : 'No matching results'}
      hint={ar ? 'لا توجد سجلات تطابق البحث أو الفلاتر المفعّلة.' : 'No records match the search or the active filters.'}
      icon="search"
      action={
        <Button
          variant="secondary"
          onClick={() => {
            filters?.clearAll()
            onClearSearch?.()
          }}
        >
          {ar ? 'مسح الفلاتر' : 'Clear filters'}
        </Button>
      }
    />
  )
}
