import { useState } from 'react'
import { NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { GlobalSearch } from './GlobalSearch'
import { cx, Spinner } from './ui'

type NavItem = { to: string; label: string; icon: string; permission?: string }

function Icon({ path }: { path: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5 shrink-0" aria-hidden="true">
      <path d={path} />
    </svg>
  )
}

const icons = {
  dashboard: 'M3 12h7V3H3v9Zm0 9h7v-6H3v6Zm11 0h7V9h-7v12Zm0-15h7V3h-7v3Z',
  accounts: 'M4 5h16M4 12h16M4 19h16M8 5v14M16 5v14',
  journal: 'M6 3h12a2 2 0 0 1 2 2v14l-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2Zm2 5h8M8 12h8',
  customers: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  suppliers: 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4M9 10h.01M15 10h.01M9 14h.01M15 14h.01',
  bookings: 'M3 7h18v13H3V7Zm3-3v6m12-6v6M3 11h18M8 15h3',
  payments: 'M2 7h20v12H2V7Zm0 4h20M6 15h3',
  settlements: 'M4 7h16M4 12h10M4 17h7m6-3 3 3-3 3m3-3h-6',
  reports: 'M4 19V5m0 14h16M8 15V9m4 6V6m4 9v-4',
  leads: 'M12 2a5 5 0 0 1 5 5c0 3-5 9-5 9S7 10 7 7a5 5 0 0 1 5-5Zm0 3.5A1.5 1.5 0 1 0 12 9a1.5 1.5 0 0 0 0-3.5Z',
  settings: 'M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7Zm8-3.5a8 8 0 0 0-.1-1l2-1.5-2-3.5-2.3 1a8 8 0 0 0-1.7-1L15.5 3h-4l-.4 2.5a8 8 0 0 0-1.7 1l-2.3-1-2 3.5L7.1 11a8 8 0 0 0 0 2l-2 1.5 2 3.5 2.3-1a8 8 0 0 0 1.7 1l.4 2.5h4l.4-2.5a8 8 0 0 0 1.7-1l2.3 1 2-3.5-2-1.5a8 8 0 0 0 .1-1Z',
}

export function Layout() {
  const { user, loading, logout, branches, activeBranch, setActiveBranch, can } = useAuth()
  const { t, locale, toggleLocale } = useI18n()
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

  const items: NavItem[] = [
    { to: '/', label: t('dashboard'), icon: icons.dashboard },
    { to: '/accounts', label: t('accounts'), icon: icons.accounts },
    { to: '/journal', label: t('journal'), icon: icons.journal },
    { to: '/treasury', label: t('treasury'), icon: icons.payments, permission: 'accounts.view' },
    { to: '/expenses', label: t('expenses'), icon: icons.settlements, permission: 'expenses.view' },
    { to: '/opening-balances', label: t('openingBalances'), icon: icons.accounts, permission: 'opening_balances.view' },
    { to: '/periods', label: t('fiscalPeriods'), icon: icons.bookings, permission: 'periods.view' },
{ to: '/invoices', label: t('invoices'), icon: icons.payments, permission: 'invoices.view' },
    { to: '/documents', label: t('vouchers'), icon: icons.journal, permission: 'documents.view' },
    { to: '/reconciliation', label: t('reconciliation'), icon: icons.settlements, permission: 'reconciliation.view' },
    { to: '/schedules', label: t('schedules'), icon: icons.bookings, permission: 'schedules.view' },
    { to: '/fixed-assets', label: t('fixedAssets'), icon: icons.accounts, permission: 'assets.view' },
    { to: '/customers', label: t('customers'), icon: icons.customers },
    { to: '/suppliers', label: t('suppliers'), icon: icons.suppliers },
    { to: '/bookings', label: t('bookings'), icon: icons.bookings },
    { to: '/payments', label: t('payments'), icon: icons.payments },
    { to: '/supplier-settlements', label: t('supplierSettlements'), icon: icons.settlements },
    { to: '/reports', label: t('reports'), icon: icons.reports },
    { to: '/leads', label: t('crm'), icon: icons.leads },
    { to: '/settings', label: t('settings'), icon: icons.settings },
  ]

  const nav = (
    <nav className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4" aria-label="Main navigation">
      {items.filter((item) => !item.permission || can(item.permission)).map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.to === '/'}
          onClick={() => setMobileOpen(false)}
          className={({ isActive }) =>
            cx(
              'label flex items-center gap-3 rounded-12 px-3 py-2 transition',
              isActive
                ? 'bg-surface-selected text-text-default'
                : 'text-text-subtle hover:bg-surface-hovered hover:text-text-default',
            )
          }
        >
          <Icon path={item.icon} />
          <span>{item.label}</span>
        </NavLink>
      ))}
    </nav>
  )

  return (
    <div className="flex min-h-screen bg-surface-canvas">
      <aside className="no-print hidden w-64 shrink-0 flex-col border-e border-border-subtle bg-surface-default lg:flex">
        <div className="border-b border-border-subtle px-5 py-5">
          <p className="heading-medium text-brand-1-accent">{t('appName')}</p>
          <p className="text-small mt-0.5 text-text-subtle">{t('appTagline')}</p>
        </div>
        {nav}
        <div className="text-small border-t border-border-subtle px-4 py-3 text-text-subtle">
          {user.name} · {user.roles[0] ?? '—'}
        </div>
      </aside>

      {mobileOpen ? (
        <div className="no-print fixed inset-0 z-40 bg-black/40 lg:hidden" onClick={() => setMobileOpen(false)}>
          <aside className="flex h-full w-72 flex-col bg-surface-default" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-border-subtle px-4 py-4">
              <p className="heading-small text-brand-1-accent">{t('appName')}</p>
              <button className="p-1 text-text-subtle" onClick={() => setMobileOpen(false)} aria-label="Close menu">
                ✕
              </button>
            </div>
            {nav}
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="no-print sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-border-subtle bg-surface-default/95 px-4 py-3 backdrop-blur lg:px-6">
          <div className="flex items-center gap-3">
            <button className="rounded-12 border border-border-subtle p-2 text-text-subtle lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open menu">
              ☰
            </button>
            <div className="hidden sm:block">
              <p className="label text-text-default">{t('appName')}</p>
              <p className="text-small text-text-subtle">{t('appTagline')}</p>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-end gap-2">
            <GlobalSearch />
            {branches.length > 1 ? (
              <label className="text-small flex items-center gap-2 text-text-subtle">
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
            <button
              className="text-small rounded-12 border border-border-subtle px-2.5 py-1.5 font-medium text-text-subtle hover:bg-surface-hovered"
              onClick={toggleLocale}
              title={t('language')}
            >
              {locale === 'ar' ? 'EN' : 'عربي'}
            </button>
            <button className="text-small rounded-12 px-2.5 py-1.5 font-medium text-text-subtle hover:bg-surface-hovered" onClick={() => void logout()}>
              {t('logout')}
            </button>
          </div>
        </header>

        <main className="min-w-0 flex-1 px-4 py-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
