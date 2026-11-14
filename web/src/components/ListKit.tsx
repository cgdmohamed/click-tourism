import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useI18n } from '../i18n'
import type { ActionItem } from './ActionsMenu'
import { Icon } from './icons'
import { EmptyState, IconButton, Skeleton, Spinner, Th, cx } from './ui'

/**
 * Building blocks shared by the list pages (customers, invoices, suppliers, payments):
 *  - `ListTable` / `ListTh`: the table scrolls inside its card and the header row stays visible;
 *  - `RowActions`: the one fixed-width actions cell (icon-only, opens a menu for several actions);
 *  - `ListSkeleton`: skeleton rows (table) and cards (phone) shaped like the loaded list;
 *  - `ListEmpty`: the empty state, which names an active filter and offers "clear filters".
 */

/** Sticky-header table. Separate borders so the header's bottom border stays attached while it sticks. */
export function ListTable({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div className="max-h-[70vh] overflow-auto" tabIndex={0} role="region" aria-label={label}>
      <table className="text-medium w-full border-separate border-spacing-0">{children}</table>
    </div>
  )
}

export function ListTh({ children, className }: { children?: ReactNode; className?: string }) {
  return <Th className={cx('sticky top-0 z-10 whitespace-nowrap', className)}>{children}</Th>
}

/** Width of the actions column, identical on every list. */
export const ACTIONS_COL = 'w-14 text-end'

/**
 * A row's actions in the fixed column (a single action is a plain `IconButton`, several are this menu). It is
 * portaled and positioned from the trigger so the table's own scroll container never clips it.
 */
export function RowActions({ items, label }: { items: readonly ActionItem[]; label?: string }) {
  const { locale } = useI18n()
  const text = label ?? (locale === 'ar' ? 'إجراءات' : 'Actions')
  const menuId = useId()
  const triggerRef = useRef<HTMLSpanElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [place, setPlace] = useState<{ top?: number; bottom?: number; left: number } | null>(null)

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) triggerRef.current?.querySelector('button')?.focus()
  }, [])

  useLayoutEffect(() => {
    if (!open) {
      setPlace(null)
      return
    }
    const trigger = triggerRef.current
    if (!trigger) return
    const rect = trigger.getBoundingClientRect()
    const width = 208
    const margin = 8
    const rtl = getComputedStyle(trigger).direction === 'rtl'
    // Open toward the page's inline-start side, so it never leaves the screen at the inline-end edge.
    let left = rtl ? rect.left : rect.right - width
    left = Math.max(margin, Math.min(left, window.innerWidth - margin - width))
    const height = (menuRef.current?.scrollHeight ?? 200) + 8
    if (window.innerHeight - rect.bottom >= height || rect.top < height) setPlace({ top: rect.bottom + 4, left })
    else setPlace({ bottom: window.innerHeight - rect.top + 4, left })
  }, [open])

  useEffect(() => {
    if (!open) return
    menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus({ preventScroll: true })
    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (menuRef.current?.contains(target) || triggerRef.current?.contains(target)) return
      setOpen(false)
    }
    const dismiss = () => setOpen(false)
    // A scroll that does not move the trigger (focus or click scrolling its container) must not close the menu.
    const startTop = triggerRef.current?.getBoundingClientRect().top ?? 0
    const onScroll = () => {
      const top = triggerRef.current?.getBoundingClientRect().top ?? 0
      if (Math.abs(top - startTop) > 1) setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', dismiss)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', dismiss)
    }
  }, [open])

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      close(true)
      return
    }
    if (event.key === 'Tab') {
      setOpen(false)
      return
    }
    const list = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [])
    const index = list.indexOf(document.activeElement as HTMLElement)
    let next: number | null = null
    if (event.key === 'ArrowDown') next = (index + 1) % list.length
    else if (event.key === 'ArrowUp') next = (index - 1 + list.length) % list.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = list.length - 1
    if (next !== null && list.length > 0) {
      event.preventDefault()
      list[next]?.focus()
    }
  }

  if (items.length === 0) return null

  return (
    <>
      <span ref={triggerRef} className="inline-flex">
        <IconButton
          icon="more"
          label={text}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-controls={open ? menuId : undefined}
          onClick={() => setOpen((value) => !value)}
        />
      </span>
      {open
        ? createPortal(
            <div
              ref={menuRef}
              id={menuId}
              role="menu"
              aria-label={text}
              onKeyDown={onKeyDown}
              className={cx('fixed z-[70] flex w-52 flex-col gap-0.5 rounded-16 border border-border-subtle bg-surface-default p-2 shadow-4', place ? null : 'invisible')}
              style={{ top: place?.top, bottom: place?.bottom, left: place?.left ?? 0 }}
            >
              {items.map((item, index) =>
                item === 'separator' ? (
                  <div key={`sep-${index}`} role="separator" className="my-1 border-t border-border-subtle" />
                ) : (
                  <button
                    key={item.key}
                    type="button"
                    role="menuitem"
                    tabIndex={-1}
                    disabled={item.disabled || item.busy}
                    className="text-medium flex w-full items-center gap-3 rounded-12 px-3 py-2 text-start text-text-default outline-none transition hover:bg-surface-hovered focus-visible:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus disabled:cursor-not-allowed disabled:text-text-disabled disabled:hover:bg-transparent"
                    onClick={() => {
                      close(false)
                      item.onSelect()
                    }}
                  >
                    {item.busy ? <Spinner className="h-4 w-4" /> : item.icon ? <Icon name={item.icon} className="h-4 w-4 shrink-0 text-text-subtle" /> : <span className="h-4 w-4 shrink-0" />}
                    <span className="min-w-0 flex-1">{item.label}</span>
                  </button>
                ),
              )}
            </div>,
            document.body,
          )
        : null}
    </>
  )
}

/** Skeleton shaped like the loaded list: rows from `md` up, cards below. Announced once as "loading". */
export function ListSkeleton({ columns = 5 }: { columns?: number }) {
  const { t } = useI18n()
  return (
    <div role="status" aria-live="polite" aria-busy="true">
      <span className="sr-only">{t('loading')}</span>
      <div aria-hidden="true" className="hidden md:block">
        {Array.from({ length: 6 }, (_, row) => (
          <div key={row} className="flex items-center gap-6 border-b border-border-subtle px-4 py-4">
            {Array.from({ length: columns }, (_, col) => (
              <Skeleton key={col} className={cx('h-3', col === 0 ? 'w-24' : col === columns - 1 ? 'ms-auto w-20' : 'flex-1')} />
            ))}
          </div>
        ))}
      </div>
      <div aria-hidden="true" className="flex flex-col gap-3 p-3 md:hidden">
        {Array.from({ length: 4 }, (_, card) => (
          <div key={card} className="flex flex-col gap-3 rounded-12 border border-border-subtle p-4">
            <Skeleton className="h-3 w-28" />
            <Skeleton className="h-3 w-2/3" />
            <div className="grid grid-cols-2 gap-3">
              <Skeleton className="h-3" />
              <Skeleton className="h-3" />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

/** Empty list: names an active search/filter and offers "clear filters" (shared EmptyState), else plain "no data". */
export function ListEmpty({ filtered, onClear }: { filtered: boolean; onClear: () => void }) {
  return filtered ? <EmptyState variant="filtered" onClear={onClear} /> : <EmptyState />
}
