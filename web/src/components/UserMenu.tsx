import { useCallback, useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { useTheme } from '../theme'
import { localizedName } from '../lib/names'
import { Icon } from './icons'
import type { IconName } from './icons'
import { cx } from './ui'
import { roleLabel } from '../lib/permissionLabels'

const itemClass =
  'text-medium flex w-full items-center gap-3 rounded-12 px-3 py-2 text-start text-text-default outline-none transition hover:bg-surface-hovered focus-visible:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus'

function ItemBody({ icon, children, value }: { icon: IconName; children: ReactNode; value?: string }) {
  return (
    <>
      <Icon name={icon} className="h-4 w-4 shrink-0 text-text-subtle" />
      <span className="min-w-0 flex-1 truncate">{children}</span>
      {value ? <span className="text-small shrink-0 text-text-subtle">{value}</span> : null}
    </>
  )
}

/**
 * The single account menu: profile, language, theme and sign out (plus the branch
 * picker on phones, where the header has no room for it). Menu-button semantics:
 * aria-haspopup/aria-expanded, Escape and outside click close it, arrow keys move
 * between items and focus returns to the trigger.
 */
export function UserMenu() {
  const { user, logout, branches, activeBranch, setActiveBranch } = useAuth()
  const { t, locale, toggleLocale } = useI18n()
  const { theme, toggleTheme } = useTheme()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const menuId = useId()

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) triggerRef.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return
    menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
    const onPointerDown = (event: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  if (!user) return null

  const initials =
    user.name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('') || '?'

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
    const list = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
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
        aria-label={user.name}
        title={user.name}
        onClick={() => setOpen((value) => !value)}
        className="text-small flex h-9 items-center gap-1 rounded-full border border-border-subtle bg-surface-default ps-1 pe-2 font-semibold text-text-subtle outline-none transition hover:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus"
      >
        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-surface-subtle text-text-default">{initials}</span>
        <Icon name="chevronDown" className={cx('h-3.5 w-3.5 transition', open && 'rotate-180')} />
      </button>

      {open ? (
        <div className="absolute end-0 top-full z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-16 border border-border-subtle bg-surface-default p-2 shadow-4">
          <div className="border-b border-border-subtle px-3 pb-2 pt-1">
            <p className="text-medium truncate font-semibold text-text-default">{user.name}</p>
            <p className="text-small truncate text-text-subtle">{user.roles[0] ? roleLabel(user.roles[0], locale) : '—'}</p>
          </div>

          {branches.length > 1 ? (
            <label className="text-small flex flex-col gap-1 border-b border-border-subtle px-3 py-2 text-text-subtle sm:hidden">
              <span className="flex items-center gap-2">
                <Icon name="building" className="h-4 w-4" />
                {t('branch')}
              </span>
              <select
                aria-label={t('branch')}
                className="text-medium h-9 rounded-12 border border-border-default bg-surface-default px-2 text-text-default"
                value={activeBranch?.id ?? ''}
                onChange={(event) => setActiveBranch(event.target.value)}
              >
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {localizedName(branch, locale)}
                  </option>
                ))}
              </select>
            </label>
          ) : null}

          <div ref={menuRef} id={menuId} role="menu" aria-label={user.name} className="flex flex-col gap-0.5 pt-2">
            <NavLink to="/profile" role="menuitem" tabIndex={-1} className={itemClass} onClick={() => close(false)}>
              <ItemBody icon="user">{t('profile')}</ItemBody>
            </NavLink>
            <button type="button" role="menuitem" tabIndex={-1} className={itemClass} onClick={toggleLocale}>
              <ItemBody icon="globe" value={locale === 'ar' ? 'English' : 'العربية'}>
                {t('language')}
              </ItemBody>
            </button>
            <button type="button" role="menuitem" tabIndex={-1} className={itemClass} onClick={toggleTheme}>
              <ItemBody icon={theme === 'dark' ? 'sun' : 'moon'}>{theme === 'dark' ? t('switchToLight') : t('switchToDark')}</ItemBody>
            </button>
            <button
              type="button"
              role="menuitem"
              tabIndex={-1}
              className={cx(itemClass, 'text-danger-text')}
              onClick={() => {
                close(false)
                void logout()
              }}
            >
              <ItemBody icon="logOut">{t('logout')}</ItemBody>
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
