import { useCallback, useEffect, useState } from 'react'
import { NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { BrandMark, useBrandName } from './Brand'
import { useSyncCurrencyExponents } from '../lib/useCurrencies'
import { AssistantChat } from './AssistantChat'
import { AssistantOrb } from './AssistantOrb'
import { GlobalSearch } from './GlobalSearch'
import { NotificationBell } from './NotificationBell'
import { UserMenu } from './UserMenu'
import { Icon } from './icons'
import type { IconName } from './icons'
import { cx, IconButton, Spinner, Select } from './ui'
import { localizedName } from '../lib/names'
import { showAssistant } from '../lib/assistant'
import { useFeatureFlags } from '../lib/useSetting'
import { useApprovalCount } from '../lib/useApprovalCount'
import { roleLabel } from '../lib/permissionLabels'

type NavItem = { to: string; label: string; icon: IconName; permission?: string; anyPermission?: string[]; badge?: number }

const APPROVAL_PERMISSIONS = ['expenses.approve', 'documents.approve', 'invoices.approve', 'invoices.create', 'commissions.approve', 'refunds.approve']

/** Entities the import page can load; the page is useful to anyone holding one of these. */
const IMPORT_PERMISSIONS = ['customers.create', 'suppliers.create', 'employees.manage', 'bookings.create', 'accounts.update', 'opening_balances.manage']
type NavGroup = { label?: string; items: NavItem[] }

export function Layout() {
  const { user, loading, branches, activeBranch, setActiveBranch, can } = useAuth()
  const approvalCount = useApprovalCount()
  const { t, locale } = useI18n()
  const brand = useBrandName()
  useSyncCurrencyExponents(Boolean(user))
  const [mobileOpen, setMobileOpen] = useState(false)
  const [assistantOpen, setAssistantOpen] = useState(false)
  const { flags } = useFeatureFlags()
  const assistantVisible = showAssistant(can('assistant.use'), flags)
  const closeAssistant = useCallback(() => setAssistantOpen(false), [])
  const location = useLocation()
  const [searchOpen, setSearchOpen] = useState(false)
  useEffect(() => setSearchOpen(false), [location.pathname, location.search])

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

  // The assistant is a mode: while it is on the sidebar and the page are hidden (ADR-0022).
  if (assistantVisible && assistantOpen) {
    return <AssistantChat onExit={closeAssistant} />
  }

  const groups: NavGroup[] = [
    {
      items: [
        { to: '/', label: t('dashboard'), icon: 'dashboard' },
        { to: '/approvals', label: locale === 'ar' ? 'الاعتمادات' : 'Approvals', icon: 'clipboardCheck', anyPermission: APPROVAL_PERMISSIONS, badge: approvalCount },
      ],
    },
    {
      label: locale === 'ar' ? 'المبيعات' : 'Sales',
      items: [
        { to: '/leads', label: t('crm'), icon: 'target' },
        { to: '/customers', label: t('customers'), icon: 'users' },
        { to: '/bookings', label: t('bookings'), icon: 'plane' },
        { to: '/tour-packages', label: locale === 'ar' ? 'البرامج والرحلات' : 'Tours & Packages', icon: 'plane', permission: 'bookings.view' },
      ],
    },
    {
      label: locale === 'ar' ? 'الإيرادات والمدفوعات' : 'Money in & out',
      items: [
        { to: '/invoices', label: t('invoices'), icon: 'fileText', permission: 'invoices.view' },
        { to: '/payments', label: locale === 'ar' ? 'تحصيلات العملاء' : 'Customer collections', icon: 'creditCard', permission: 'payments.view' },
        { to: '/expenses', label: t('expenses'), icon: 'receipt', permission: 'expenses.view' },
        { to: '/supplier-settlements', label: t('supplierSettlements'), icon: 'handshake' },
        { to: '/commissions', label: locale === 'ar' ? 'العمولات' : 'Commissions', icon: 'coins', permission: 'commissions.view' },
        { to: '/treasury', label: t('treasury'), icon: 'wallet', permission: 'accounts.view' },
      ],
    },
    {
      label: t('parties'),
      items: [
        { to: '/suppliers', label: t('suppliers'), icon: 'truck' },
        { to: '/employees', label: t('employees'), icon: 'userCheck', permission: 'employees.view' },
      ],
    },
    {
      label: t('accounting'),
      items: [
        { to: '/documents', label: t('vouchers'), icon: 'clipboardCheck', permission: 'documents.view' },
        { to: '/journal', label: t('journal'), icon: 'scale' },
        { to: '/reports', label: t('reports'), icon: 'chart', permission: 'reports.view' },
        { to: '/reconciliation', label: t('reconciliation'), icon: 'listChecks', permission: 'reconciliation.view' },
        { to: '/periods', label: t('fiscalPeriods'), icon: 'calendar', permission: 'periods.view' },
        { to: '/schedules', label: t('schedules'), icon: 'calendarClock', permission: 'schedules.view' },
        { to: '/fixed-assets', label: t('fixedAssets'), icon: 'package', permission: 'assets.view' },
        { to: '/cost-centers', label: locale === 'ar' ? 'مراكز التكلفة' : 'Cost centers', icon: 'hash', permission: 'cost_centers.view' },
        { to: '/opening-balances', label: t('openingBalances'), icon: 'landmark', permission: 'opening_balances.view' },
        { to: '/accounts', label: t('accounts'), icon: 'ledger' },
      ],
    },
    {
      label: locale === 'ar' ? 'إعدادات التشغيل' : 'Operations setup',
      items: [
        { to: '/payment-methods', label: locale === 'ar' ? 'طرق الدفع' : 'Payment methods', icon: 'wallet', permission: 'payments.view' },
        { to: '/lead-settings', label: locale === 'ar' ? 'مصادر ومراحل العملاء' : 'Lead sources & stages', icon: 'listChecks', permission: 'leads.manage_rules' },
        { to: '/assignment-rules', label: t('assignmentRules'), icon: 'target', permission: 'leads.manage_rules' },
      ],
    },
    {
      label: t('system'),
      items: [
        { to: '/users', label: t('users'), icon: 'users', permission: 'users.view' },
        { to: '/roles', label: t('rolesPermissions'), icon: 'shield', permission: 'roles.view' },
        { to: '/audit-events', label: t('auditLog'), icon: 'shield', permission: 'audit.view' },
        { to: '/imports', label: locale === 'ar' ? 'استيراد البيانات' : 'Data import', icon: 'upload', anyPermission: IMPORT_PERMISSIONS },
        { to: '/exchange-rates', label: locale === 'ar' ? 'أسعار الصرف' : 'Exchange rates', icon: 'refresh', permission: 'settings.view' },
        { to: '/settings', label: t('settings'), icon: 'settings' },
      ],
    },
  ]

  const nav = (
    <nav className="flex flex-1 flex-col gap-4 overflow-y-auto px-3 py-4" aria-label="Main navigation">
      {groups.map((group, index) => {
        const items = group.items.filter(
          (item) => (!item.permission || can(item.permission)) && (!item.anyPermission || item.anyPermission.some((permission) => can(permission))),
        )
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
                    {item.badge ? <span className="text-small ms-auto rounded-full bg-surface-selected px-2 tabular" aria-label={`${item.badge}`}>{item.badge}</span> : null}
                  </>
                )}
              </NavLink>
            ))}
          </div>
        )
      })}
    </nav>
  )

  const brandBlock = (
    <div className="flex items-center gap-3 border-b border-border-subtle px-4 py-4">
      <BrandMark className="h-10 w-10" />
      <div className="min-w-0">
        <p className="heading-small truncate text-brand-1-accent">{brand}</p>
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
        {brandBlock}
        {nav}
        <div className="flex items-center gap-2 border-t border-border-subtle px-3 py-3">
          <NavLink
            to="/profile"
            title={t('profile')}
            className="flex min-w-0 flex-1 items-center gap-2 rounded-12 p-1 transition hover:bg-surface-hovered"
          >
            <span className="text-small flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-subtle font-semibold text-text-subtle">
              {initials}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-small truncate font-medium text-text-default">{user.name}</p>
              <p className="text-small truncate text-text-subtle">{user.roles[0] ? roleLabel(user.roles[0], locale) : '—'}</p>
            </div>
          </NavLink>
        </div>
      </aside>

      {mobileOpen ? (
        <div className="no-print fixed inset-0 z-40 bg-black/40 lg:hidden" onClick={() => setMobileOpen(false)}>
          <aside className="flex h-full w-72 flex-col bg-surface-default" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-border-subtle px-4 py-4">
              <div className="flex min-w-0 items-center gap-3">
                <BrandMark className="h-9 w-9" iconSize={20} />
                <p className="heading-small truncate text-brand-1-accent">{brand}</p>
              </div>
              <IconButton icon="x" label={t('closeMenu')} onClick={() => setMobileOpen(false)} />
            </div>
            {nav}
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="no-print sticky top-0 z-30 flex items-center justify-between gap-2 border-b border-border-subtle bg-surface-default/95 px-4 py-2.5 backdrop-blur sm:gap-3 sm:py-3 lg:px-6">
          <div className="flex min-w-0 items-center gap-2 sm:gap-3">
            <IconButton icon="menu" label={t('openMenu')} variant="secondary" className="shrink-0 lg:hidden" onClick={() => setMobileOpen(true)} />
            <div className="min-w-0">
              <p className="label truncate text-text-default">{brand}</p>
              <p className="text-small hidden truncate text-text-subtle sm:block">{t('appTagline')}</p>
            </div>
          </div>

          <div className="flex shrink-0 items-center justify-end gap-1.5 sm:gap-2">
            <div className="hidden sm:block">
              <GlobalSearch />
            </div>
            <IconButton icon="search" label={t('globalSearch')} variant="secondary" className="sm:hidden" onClick={() => setSearchOpen(true)} />
            {assistantVisible ? <AssistantOrb onClick={() => setAssistantOpen(true)} /> : null}
            <NotificationBell />
            {branches.length > 1 ? (
              <label className="text-small hidden items-center gap-2 text-text-subtle sm:flex">
                <Icon name="building" className="h-4 w-4" />
                <span className="hidden md:inline">{t('branch')}</span>
                <Select
                  aria-label={t('branch')}
                  className="w-44 max-w-40"
                  value={activeBranch?.id ?? ''}
                  onChange={(event) => setActiveBranch(event.target.value)}
                >
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {localizedName(branch, locale)}
                    </option>
                  ))}
                </Select>
              </label>
            ) : null}
            <UserMenu />
          </div>
        </header>

        {searchOpen ? (
          <div
            className="no-print fixed inset-0 z-40 bg-black/40 sm:hidden"
            onClick={() => setSearchOpen(false)}
            onKeyDown={(event) => {
              if (event.key === 'Escape') setSearchOpen(false)
            }}
          >
            <div className="bg-surface-default p-3 shadow-4" onClick={(event) => event.stopPropagation()}>
              <GlobalSearch autoFocus fullWidth />
            </div>
          </div>
        ) : null}

        <main className="min-w-0 flex-1 px-4 py-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
