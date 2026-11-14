import { useCallback, useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useI18n } from '../i18n'
import { Icon } from './icons'
import type { IconName } from './icons'
import { Spinner, cx } from './ui'

export type ActionItem =
  | { key: string; label: string; icon?: IconName; onSelect: () => void; disabled?: boolean; busy?: boolean }
  | 'separator'

const itemClass =
  'text-medium flex w-full items-center gap-3 rounded-12 px-3 py-2 text-start text-text-default outline-none transition hover:bg-surface-hovered focus-visible:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus disabled:cursor-not-allowed disabled:text-text-disabled disabled:hover:bg-transparent'

/**
 * One "Actions" button instead of a row of Print / CSV / XLSX / PDF / Download buttons.
 * Menu-button semantics: aria-haspopup/aria-expanded, arrow keys, Home/End, Escape and outside
 * click close it and focus returns to the trigger. A busy item shows a spinner and the menu
 * stays open until the action finishes.
 */
export function ActionsMenu({ items, label, icon = 'more' }: { items: readonly ActionItem[]; label?: string; icon?: IconName }) {
  const { locale } = useI18n()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const menuId = useId()
  const text = label ?? (locale === 'ar' ? 'إجراءات' : 'Actions')

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) triggerRef.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return
    menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus()
    const onPointerDown = (event: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (!open) return
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
    if (list.length === 0 || !menuRef.current?.contains(document.activeElement)) return
    const index = list.indexOf(document.activeElement as HTMLElement)
    let next: number | null = null
    if (event.key === 'ArrowDown') next = (index + 1) % list.length
    else if (event.key === 'ArrowUp') next = (index - 1 + list.length) % list.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = list.length - 1
    if (next !== null) {
      event.preventDefault()
      list[next]?.focus()
    }
  }

  return (
    <div ref={rootRef} className="relative" onKeyDown={onKeyDown}>
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((value) => !value)}
        className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-default bg-surface-default px-3 font-medium text-text-default shadow-1 outline-none transition hover:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus"
      >
        <Icon name={icon} className="h-4 w-4" />
        {text}
        <Icon name="chevronDown" className={cx('h-3.5 w-3.5 transition', open && 'rotate-180')} />
      </button>

      {open ? (
        <div ref={menuRef} id={menuId} role="menu" aria-label={text} className="absolute end-0 top-full z-40 mt-2 flex min-w-52 flex-col gap-0.5 rounded-16 border border-border-subtle bg-surface-default p-2 shadow-4">
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
                className={itemClass}
                onClick={() => {
                  item.onSelect()
                  close(false)
                }}
              >
                {item.busy ? <Spinner className="h-4 w-4" /> : item.icon ? <Icon name={item.icon} className="h-4 w-4 shrink-0 text-text-subtle" /> : <span className="h-4 w-4 shrink-0" />}
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
              </button>
            ),
          )}
        </div>
      ) : null}
    </div>
  )
}
