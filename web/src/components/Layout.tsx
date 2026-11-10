import { useState } from 'react'
import { NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { useTheme } from '../theme'
import { GlobalSearch } from './GlobalSearch'
import { Icon } from './icons'
import type { IconName } from './icons'
import { cx, IconButton, Spinner } from './ui'

type NavItem = { to: string; label: string; icon: IconName; permission?: string }
type NavGroup = { label?: string; items: NavItem[] }

export function Layout() {
  const { user, loading, logout, branches, activeBranch, setActiveBranch, can } = useAuth()
  const { t, locale, toggleLocale } = useI18n()
  const { theme, toggleTheme } = useTheme()
  const [mobileOpen, setMobileOpen] = useState(false)
  const location = useLocation()

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center" role="status" aria-live="polite">
        <span className="sr-only">{t('loading')}</span>
        <Spinner />
      </div>
    )
  }
  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  const groups: NavGroup[] = [
    {
      items: [{ to: '/', label: t('dashboard'), icon: 'dashboard' }],
    },
    {
      label: t('accounting'),
      items: [
        { to: '/accounts', label: t('accounts'), icon: 'ledger' },
        { to: '/journal', label: t('journal'), icon: 'scale' },
        { to: '/opening-balances', label: t('openingBalances'), icon: 'landmark', permission: 'opening_balances.view' },
        { to: '/periods', label: t('fiscalPeriods'), icon: 'calendar', permission: 'periods.view' },
        { to: '/documents', label: t('vouchers'), icon: 'clipboardCheck', permission: 'documents.view' },
        { to: '/fixed-assets', label: t('fixedAssets'), icon: 'package', permission: 'assets.view' },
        { to: '/reports', label: t('reports'), icon: 'chart' },
      ],
    },
    {
      label: t('operations'),
      items: [
        { to: '/treasury', label: t('treasury'), icon: 'wallet', permission: 'accounts.view' },
        { to: '/expenses', label: t('expenses'), icon: 'receipt', permission: 'expenses.view' },
        { to: '/invoices', label: t('invoices'), icon: 'fileText', permission: 'invoices.view' },
        { to: '/payments', label: t('payments'), icon: 'creditCard' },
        { to: '/payment-methods', label: locale === 'ar' ? 'طرق الدفع' : 'Payment methods', icon: 'wallet', permission: 'payments.view' },
        { to: '/supplier-settlements', label: t('supplierSettlements'), icon: 'handshake' },
        { to: '/reconciliation', label: t('reconciliation'), icon: 'listChecks', permission: 'reconciliation.view' },
        { to: '/schedules', label: t('schedules'), icon: 'calendarClock', permission: 'schedules.view' },
      ],
    },
    {
      label: t('parties'),
      items: [
        { to: '/customers', label: t('customers'), icon: 'users' },
        { to: '/suppliers', label: t('suppliers'), icon: 'truck' },
        { to: '/employees', label: t('employees'), icon: 'userCheck', permission: 'employees.view' },
        { to: '/bookings', label: t('bookings'), icon: 'plane' },
        { to: '/leads', label: t('crm'), icon: 'target' },
        { to: '/assignment-rules', label: t('assignmentRules'), icon: 'target', permission: 'leads.manage_rules' },
      ],
    },
    {
      label: t('system'),
      items: [{ to: '/settings', label: t('settings'), icon: 'settings' }],
    },
  ]

  const nav = (
    <nav className="flex flex-1 flex-col gap-4 overflow-y-auto px-3 py-4" aria-label="Main navigation">
      {groups.map((group, index) => {
        const items = group.items.filter((item) => !item.permission || can(item.permission))
        if (items.length === 0) return null
        return (
          <div key={group.label ?? `group-${index}`} className="flex flex-col gap-1">
            {group.label ? (
              <p className="text-small px-3 pb-1 font-semibold uppercase tracking-wide text-text-subtlest">{group.label}</p>
            ) : null}
            {items.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                onClick={() => setMobileOpen(false)}
                className={({ isActive }) =>
                  cx(
                    'label relative flex items-center gap-3 rounded-12 px-3 py-2 transition',
                    isActive
                      ? 'bg-surface-selected text-text-default'
                      : 'text-text-subtle hover:bg-surface-hovered hover:text-text-default',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    {isActive ? (
                      <span className="absolute inset-y-1.5 start-0 w-1 rounded-full bg-brand-1-accent" aria-hidden="true" />
                    ) : null}
                    <Icon name={item.icon} className={cx('h-5 w-5', isActive && 'text-brand-1-accent')} />
                    <span className="truncate">{item.label}</span>
                  </>
                )}
              </NavLink>
            ))}
          </div>
        )
      })}
    </nav>
  )

  const brand = (
    <div className="flex items-center gap-3 border-b border-border-subtle px-4 py-4">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-16 bg-brand-1-background text-brand-1-text shadow-2">
        <Icon name="compass" size={22} />
      </span>
      <div className="min-w-0">
        <p className="heading-small truncate text-brand-1-accent">{t('appName')}</p>
        <p className="text-small truncate text-text-subtle">{t('appTagline')}</p>
      </div>
    </div>
  )

  const initials =
    user.name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('') || '?'

  return (
    <div className="flex min-h-screen bg-surface-canvas">
      <aside className="no-print hidden w-64 shrink-0 flex-col border-e border-border-subtle bg-surface-default lg:flex">
        {brand}
        {nav}
        <div className="flex items-center gap-2 border-t border-border-subtle px-3 py-3">
          <span className="text-small flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-subtle font-semibold text-text-subtle">
            {initials}
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-small truncate font-medium text-text-default">{user.name}</p>
            <p className="text-small truncate text-text-subtle">{user.roles[0] ?? '—'}</p>
          </div>
          <IconButton icon="logOut" label={t('logout')} onClick={() => void logout()} />
        </div>
      </aside>

      {mobileOpen ? (
        <div className="no-print fixed inset-0 z-40 bg-black/40 lg:hidden" onClick={() => setMobileOpen(false)}>
          <aside className="flex h-full w-72 flex-col bg-surface-default" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-border-subtle px-4 py-4">
              <div className="flex min-w-0 items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-12 bg-brand-1-background text-brand-1-text">
                  <Icon name="compass" size={20} />
                </span>
                <p className="heading-small truncate text-brand-1-accent">{t('appName')}</p>
              </div>
              <IconButton icon="x" label={t('closeMenu')} onClick={() => setMobileOpen(false)} />
            </div>
            {nav}
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="no-print sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-border-subtle bg-surface-default/95 px-4 py-3 backdrop-blur lg:px-6">
          <div className="flex items-center gap-3">
            <IconButton icon="menu" label={t('openMenu')} variant="secondary" className="lg:hidden" onClick={() => setMobileOpen(true)} />
            <div className="hidden sm:block">
              <p className="label text-text-default">{t('appName')}</p>
              <p className="text-small text-text-subtle">{t('appTagline')}</p>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-end gap-2">
            <GlobalSearch />
            {branches.length > 1 ? (
              <label className="text-small flex items-center gap-2 text-text-subtle">
                <Icon name="building" className="h-4 w-4" />
                <span className="hidden sm:inline">{t('branch')}</span>
                <select
                  className="text-medium h-9 rounded-12 border border-border-default bg-surface-default px-2 text-text-default"
                  value={activeBranch?.id ?? ''}
                  onChange={(event) => setActiveBranch(event.target.value)}
                >
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {locale === 'ar' ? branch.name_ar : branch.name_en}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <IconButton
              icon={theme === 'dark' ? 'sun' : 'moon'}
              label={theme === 'dark' ? t('switchToLight') : t('switchToDark')}
              onClick={toggleTheme}
            />
            <button
              className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-subtle px-2.5 font-medium text-text-subtle transition hover:bg-surface-hovered hover:text-text-default"
              onClick={toggleLocale}
              title={t('language')}
              aria-label={t('language')}
            >
              <Icon name="globe" className="h-4 w-4" />
              {locale === 'ar' ? 'EN' : 'عربي'}
            </button>
            <IconButton icon="logOut" label={t('logout')} onClick={() => void logout()} />
          </div>
        </header>

        <main className="min-w-0 flex-1 px-4 py-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
