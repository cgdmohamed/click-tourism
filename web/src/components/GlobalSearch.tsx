import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { SearchGroup, SearchResults } from '../lib/types'
import { Spinner } from './ui'

const routes: Record<SearchGroup['type'], string> = {
  customers: '/customers',
  suppliers: '/suppliers',
  bookings: '/bookings',
  payments: '/payments',
  leads: '/leads',
}

export function GlobalSearch() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const navigate = useNavigate()
  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handle = window.setTimeout(() => setDebounced(term.trim()), 250)
    return () => window.clearTimeout(handle)
  }, [term])

  useEffect(() => {
    const onPointerDown = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [])

  const enabled = debounced.length >= 2

  const query = useQuery({
    queryKey: ['global-search', debounced, activeBranch?.id],
    enabled,
    queryFn: async () => {
      const { data } = await api.get<{ data: SearchResults }>('/search', { params: { q: debounced } })
      return data.data
    },
  })

  const groups = query.data?.groups ?? []
  const total = query.data?.total ?? 0

  const groupLabels: Record<SearchGroup['type'], string> = {
    customers: t('customers'),
    suppliers: t('suppliers'),
    bookings: t('bookings'),
    payments: t('payments'),
    leads: t('crm'),
  }

  const go = (type: SearchGroup['type']) => {
    const params = new URLSearchParams({ search: debounced })
    navigate(`${routes[type]}?${params.toString()}`)
    setOpen(false)
    setTerm('')
    setDebounced('')
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Escape') {
      setOpen(false)
      return
    }
    if (event.key === 'Enter' && groups[0]?.items[0]) {
      event.preventDefault()
      go(groups[0].type)
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <svg
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle"
        aria-hidden="true"
      >
        <circle cx="8.5" cy="8.5" r="5.5" />
        <path d="m13 13 4 4" strokeLinecap="round" />
      </svg>
      <input
        type="search"
        value={term}
        onChange={(event) => {
          setTerm(event.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder={t('searchPlaceholder')}
        aria-label={t('globalSearch')}
        className="text-medium h-9 w-44 rounded-12 border border-border-default bg-surface-default ps-9 pe-3 text-text-default shadow-1 outline-none transition placeholder:text-text-placeholder focus:border-border-focus focus:ring-2 focus:ring-border-focus/25 sm:w-64"
      />

      {open && enabled ? (
        <div className="absolute end-0 z-50 mt-2 max-h-[70vh] w-[22rem] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-16 border border-border-subtle bg-surface-default p-2 shadow-4">
          {query.isLoading ? (
            <div className="flex justify-center py-4">
              <Spinner className="h-5 w-5" />
            </div>
          ) : null}
          {query.isError ? (
            <p className="text-medium px-3 py-2 text-danger-text">{errorMessage(query.error)}</p>
          ) : null}
          {!query.isLoading && !query.isError && total === 0 ? (
            <p className="text-medium px-3 py-3 text-text-subtle">{t('noResults')}</p>
          ) : null}
          {groups.map((group) => (
            <div key={group.type} className="py-1">
              <p className="text-small px-3 py-1 font-semibold uppercase tracking-wide text-text-subtle">
                {groupLabels[group.type]}
              </p>
              {group.items.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  className="flex w-full flex-col rounded-8 px-3 py-2 text-start transition hover:bg-surface-hovered"
                  onClick={() => go(group.type)}
                >
                  <span className="text-medium truncate font-medium text-text-default">
                    {locale === 'ar' ? item.title_ar : item.title_en}
                  </span>
                  {item.subtitle ? <span className="text-small truncate text-text-subtle">{item.subtitle}</span> : null}
                </button>
              ))}
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}
