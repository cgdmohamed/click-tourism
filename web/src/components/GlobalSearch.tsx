import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { SearchGroup, SearchResults } from '../lib/types'
import { Icon } from './icons'
import type { IconName } from './icons'
import { Spinner } from './ui'

const routes: Record<SearchGroup['type'], string> = {
  customers: '/customers',
  suppliers: '/suppliers',
  bookings: '/bookings',
  payments: '/payments',
  leads: '/leads',
}

const groupIcons: Record<SearchGroup['type'], IconName> = {
  customers: 'users',
  suppliers: 'truck',
  bookings: 'plane',
  payments: 'creditCard',
  leads: 'target',
}

export function GlobalSearch({ autoFocus = false, fullWidth = false }: { autoFocus?: boolean; fullWidth?: boolean }) {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const navigate = useNavigate()
  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (autoFocus) inputRef.current?.focus()
  }, [autoFocus])

  useEffect(() => {
    const handle = window.setTimeout(() => setDebounced(term.trim()), 250)
    return () => window.clearTimeout(handle)
  }, [term])

  useEffect(() => {
    const onShortcut = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        inputRef.current?.focus()
        setOpen(true)
      }
    }
    document.addEventListener('keydown', onShortcut)
    return () => document.removeEventListener('keydown', onShortcut)
  }, [])

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
    <div ref={containerRef} className={fullWidth ? 'relative w-full' : 'relative'}>
      <Icon
        name="search"
        className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle"
      />
      <input
        ref={inputRef}
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
        className={`text-medium h-9 ${fullWidth ? 'w-full' : 'w-44 sm:w-64'} rounded-12 border border-border-default bg-surface-default ps-9 pe-3 text-text-default shadow-1 outline-none transition placeholder:text-text-placeholder focus:border-border-focus focus:ring-2 focus:ring-border-focus/25`}
      />
      <kbd className="text-small pointer-events-none absolute end-2 top-1/2 hidden -translate-y-1/2 rounded-6 border border-border-subtle bg-surface-subtle px-1.5 py-0.5 font-medium text-text-subtle sm:block">
        Ctrl K
      </kbd>

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
            <div className="flex flex-col items-center gap-2 px-3 py-6 text-center">
              <Icon name="search" className="h-5 w-5 text-text-subtle" />
              <p className="text-medium text-text-subtle">{t('noResults')}</p>
            </div>
          ) : null}
          {groups.map((group) => (
            <div key={group.type} className="py-1">
              <p className="text-small flex items-center gap-2 px-3 py-1 font-semibold uppercase tracking-wide text-text-subtle">
                <Icon name={groupIcons[group.type]} className="h-3.5 w-3.5" />
                {groupLabels[group.type]}
              </p>
              {group.items.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  className="group flex w-full items-center gap-3 rounded-8 px-3 py-2 text-start transition hover:bg-surface-hovered"
                  onClick={() => go(group.type)}
                >
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-8 bg-surface-subtle text-text-subtle group-hover:text-brand-1-accent">
                    <Icon name={groupIcons[group.type]} className="h-4 w-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="text-medium block truncate font-medium text-text-default">
                      {locale === 'ar' ? item.title_ar : item.title_en}
                    </span>
                    {item.subtitle ? (
                      <span className="text-small block truncate text-text-subtle">{item.subtitle}</span>
                    ) : null}
                  </span>
                  <Icon name="chevronRight" className="h-4 w-4 text-text-subtlest" directional />
                </button>
              ))}
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}
