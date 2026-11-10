import { useState } from 'react'
import type { ReactNode } from 'react'
import type { PartyStatement, StatementLine } from '../lib/types'
import { formatDate } from '../lib/format'
import { statementSourceLabel } from '../lib/labels'
import { useI18n } from '../i18n'
import { useAuth } from '../auth/AuthProvider'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, CardHeader, EmptyState, InlineNotice, Money, Table, Td, Th, cx } from './ui'
import { Icon } from './icons'
import type { IconName } from './icons'

/** Shared building blocks for the customer / supplier / employee / invoice 360 views. */

export function ProfileHeader({
  title,
  subtitle,
  icon,
  initials,
  badge,
  backTo,
  backLabel,
  actions,
  meta,
  note,
}: {
  title: string
  subtitle?: string
  icon: IconName
  initials?: string
  badge?: ReactNode
  backTo: string
  backLabel: string
  actions?: ReactNode
  meta?: ReactNode
  note?: ReactNode
}) {
  const { t } = useI18n()

  return (
    <div className="mb-6 flex flex-col gap-4">
      <Link
        to={backTo}
        className="text-small inline-flex w-fit items-center gap-1.5 font-medium text-text-subtle transition hover:text-text-default"
      >
        <Icon name="chevronLeft" className="h-3.5 w-3.5" directional />
        {backLabel}
      </Link>

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-start justify-between gap-4 p-5">
          <div className="flex min-w-0 items-start gap-4">
            <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-16 bg-brand-1-background text-lg font-semibold text-brand-1-text shadow-2">
              {initials ?? <Icon name={icon} size={26} />}
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="heading-large text-text-default">{title}</h1>
                {badge}
              </div>
              {subtitle ? <p className="text-medium mt-1 text-text-subtle">{subtitle}</p> : null}
              {meta ? <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-text-subtle">{meta}</div> : null}
            </div>
          </div>
          {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
        {note ? <div className="border-t border-border-subtle px-5 py-3">{note}</div> : null}
      </Card>

      <span className="sr-only">{t('overview')}</span>
    </div>
  )
}

export function MetaItem({ icon, children, dir }: { icon: IconName; children: ReactNode; dir?: 'ltr' | 'rtl' }) {
  return (
    <span className="text-small inline-flex min-w-0 items-center gap-1.5">
      <Icon name={icon} className="h-3.5 w-3.5 shrink-0 text-text-subtlest" />
      <span className="truncate" dir={dir}>
        {children}
      </span>
    </span>
  )
}

export function ProfileStat({
  label,
  value,
  hint,
  icon,
  tone,
}: {
  label: string
  value: ReactNode
  hint?: string
  icon: IconName
  tone?: 'positive' | 'negative' | 'warning'
}) {
  return (
    <div className="flex items-start gap-3 rounded-16 border border-border-subtle bg-surface-default p-4 shadow-1">
      <span
        className={cx(
          'flex h-9 w-9 shrink-0 items-center justify-center rounded-12',
          tone === 'negative'
            ? 'bg-danger-background text-danger-text'
            : tone === 'warning'
              ? 'bg-warning-background text-warning-text'
              : tone === 'positive'
                ? 'bg-success-background text-success-text'
                : 'bg-surface-selected text-brand-1-accent',
        )}
      >
        <Icon name={icon} size={18} />
      </span>
      <div className="min-w-0">
        <p className="text-small text-text-subtle">{label}</p>
        <p className="heading-small mt-0.5 tabular text-text-default">{value}</p>
        {hint ? <p className="text-small mt-1 text-text-subtlest">{hint}</p> : null}
      </div>
    </div>
  )
}

export type ProfileTab = { id: string; label: string; icon?: IconName; count?: number }

export function ProfileTabs({
  tabs,
  active,
  onChange,
  label,
}: {
  tabs: ProfileTab[]
  active: string
  onChange: (id: string) => void
  label: string
}) {
  return (
    <div className="no-print flex gap-1 overflow-x-auto rounded-16 border border-border-subtle bg-surface-default p-1 shadow-1" role="tablist" aria-label={label}>
      {tabs.map((tab) => {
        const selected = tab.id === active
        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(tab.id)}
            className={cx(
              'text-small inline-flex shrink-0 items-center gap-2 rounded-12 px-3 py-2 font-medium transition',
              selected ? 'bg-surface-selected text-text-default' : 'text-text-subtle hover:bg-surface-hovered hover:text-text-default',
            )}
          >
            {tab.icon ? <Icon name={tab.icon} className="h-4 w-4" /> : null}
            {tab.label}
            {typeof tab.count === 'number' ? (
              <span className="rounded-full bg-surface-subtle px-1.5 text-text-subtlest">{tab.count}</span>
            ) : null}
          </button>
        )
      })}
    </div>
  )
}

export function DetailGrid({ children }: { children: ReactNode }) {
  return <dl className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">{children}</dl>
}

export function DetailItem({ label, children, dir }: { label: string; children: ReactNode; dir?: 'ltr' | 'rtl' }) {
  return (
    <div className="min-w-0">
      <dt className="text-small text-text-subtlest">{label}</dt>
      <dd className="text-medium mt-0.5 break-words font-medium text-text-default" dir={dir}>
        {children}
      </dd>
    </div>
  )
}

export function StatementView({
  statement,
  from,
  to,
  onRangeChange,
}: {
  statement: PartyStatement
  from: string
  to: string
  onRangeChange: (from: string, to: string) => void
}) {
  const { t, locale } = useI18n()
  const [draftFrom, setDraftFrom] = useState(from)
  const [draftTo, setDraftTo] = useState(to)
  const difference = statement.reconciliation.difference_minor

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader
          title={t('statementPeriod')}
          subtitle={t('statementHint')}
          icon="calendar"
          actions={
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault()
                if (draftFrom && draftTo) onRangeChange(draftFrom, draftTo)
              }}
            >
              <label className="text-small flex flex-col gap-1 text-text-subtle">
                {t('from')}
                <input
                  type="date"
                  value={draftFrom}
                  onChange={(event) => setDraftFrom(event.target.value)}
                  className="text-medium h-9 rounded-12 border border-border-default bg-surface-default px-2 text-text-default"
                />
              </label>
              <label className="text-small flex flex-col gap-1 text-text-subtle">
                {t('to')}
                <input
                  type="date"
                  value={draftTo}
                  onChange={(event) => setDraftTo(event.target.value)}
                  className="text-medium h-9 rounded-12 border border-border-default bg-surface-default px-2 text-text-default"
                />
              </label>
              <Button type="submit" variant="secondary" icon="filter" className="h-9">
                {t('apply')}
              </Button>
            </form>
          }
        />

        <div className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-4">
          <StatementFigure label={t('openingBalance')} amountMinor={statement.opening_minor} currency={statement.currency} showSide />
          <StatementFigure label={t('totalDebit')} amountMinor={statement.total_debit_minor} currency={statement.currency} tone="debit" />
          <StatementFigure label={t('totalCredit')} amountMinor={statement.total_credit_minor} currency={statement.currency} tone="credit" />
          <StatementFigure label={t('closingBalance')} amountMinor={statement.closing_minor} currency={statement.currency} emphasis showSide />
        </div>

        <div className="border-t border-border-subtle p-5">
          <InlineNotice tone={statement.reconciliation.reconciled ? 'positive' : 'warning'}>
            <span className="font-medium">
              {statement.reconciliation.reconciled ? t('reconciled') : t('notReconciled')}
            </span>
            <span className="ms-2">
              {t('ledgerBalance')}: <Money amountMinor={statement.reconciliation.normalized_ledger_minor} currency={statement.currency} />
            </span>
            <span className="ms-2">
              {t('operationalBalance')}: <Money amountMinor={statement.reconciliation.operational_open_invoices_minor} currency={statement.currency} />
            </span>
            <span className="ms-2">
              {t('difference')}: <Money amountMinor={difference} currency={statement.currency} />
            </span>
            {statement.reconciliation.differences.length > 0 ? (
              <ul className="mt-2 list-inside list-disc">
                {statement.reconciliation.differences.map((entry) => (
                  <li key={entry.code}>
                    {locale === 'ar' ? entry.label_ar : entry.label_en} —{' '}
                    <Money amountMinor={entry.amount_minor} currency={statement.currency} />
                  </li>
                ))}
              </ul>
            ) : null}
          </InlineNotice>
        </div>

        {statement.subaccounts.length > 0 ? (
          <div className="border-t border-border-subtle px-5 py-4">
            <p className="text-small mb-2 font-semibold uppercase tracking-wide text-text-subtlest">{t('subaccounts')}</p>
            <div className="flex flex-wrap gap-2">
              <Badge tone="brand">
                {statement.control_account.code} · {locale === 'ar' ? statement.control_account.name_ar : statement.control_account.name_en}
              </Badge>
              {statement.subaccounts.map((mapping) => (
                <Badge key={mapping.id} tone="neutral">
                  {mapping.account?.code} · {mapping.role}
                </Badge>
              ))}
            </div>
          </div>
        ) : null}
      </Card>

      <Card>
        <CardHeader
          title={t('statement')}
          subtitle={`${formatDate(statement.from, locale)} — ${formatDate(statement.to, locale)}`}
          icon="ledger"
          actions={<Badge tone="neutral">{statement.total}</Badge>}
        />
        {statement.lines.length === 0 ? (
          <EmptyState icon="ledger" title={t('noStatementLines')} />
        ) : (
          <StatementTable statement={statement} />
        )}
      </Card>
    </div>
  )
}

function StatementFigure({
  label,
  amountMinor,
  currency,
  tone,
  emphasis,
  showSide,
}: {
  label: string
  amountMinor: number
  currency: string
  tone?: 'debit' | 'credit'
  emphasis?: boolean
  showSide?: boolean
}) {
  return (
    <div className={cx('rounded-12 border p-3', emphasis ? 'border-border-selected bg-surface-selected' : 'border-border-subtle bg-surface-subtle')}>
      <p className="text-small text-text-subtle">{label}</p>
      <p
        className={cx(
          'text-medium mt-1 font-semibold tabular',
          tone === 'debit' ? 'text-info-text' : tone === 'credit' ? 'text-warning-text' : 'text-text-default',
        )}
      >
        {showSide ? <BalanceSide amountMinor={amountMinor} currency={currency} /> : <Money amountMinor={amountMinor} currency={currency} />}
      </p>
    </div>
  )
}

/** Absolute amount plus its ledger side, the way a printed subledger reads (Dr/Cr). */
function BalanceSide({ amountMinor, currency }: { amountMinor: number; currency: string }) {
  const { t } = useI18n()

  if (amountMinor === 0) {
    return <Money amountMinor={0} currency={currency} />
  }

  const debit = amountMinor > 0

  return (
    <span className="inline-flex items-center gap-1.5">
      <Money amountMinor={Math.abs(amountMinor)} currency={currency} />
      <span className={cx('text-small font-semibold', debit ? 'text-info-text' : 'text-warning-text')}>
        {debit ? t('dr') : t('cr')}
      </span>
    </span>
  )
}

type StatementSource = { href: string; permission?: string }

/**
 * Where a posted journal line came from. Invoice profiles exist, so those link
 * straight to the document. Searchable booking/payment lists are pre-filtered;
 * other source types link to their owning module's record list.
 */
function statementSource(line: StatementLine): StatementSource | null {
  const reference = line.source_reference ?? ''
  const publicId = line.source_public_id ?? ''
  const search = reference ? `?search=${encodeURIComponent(reference)}` : ''

  switch (line.source_type) {
    case 'invoice':
      return publicId ? { href: `/invoices/${publicId}`, permission: 'invoices.view' } : null
    case 'booking':
      return { href: `/bookings${search}` }
    case 'payment':
    case 'refund':
      return { href: `/payments${search}` }
    case 'supplier_payment':
      return { href: '/supplier-settlements' }
    case 'expense':
      return { href: '/expenses', permission: 'expenses.view' }
    case 'accounting_document':
      return { href: '/documents', permission: 'documents.view' }
    case 'accounting_schedule':
      return { href: '/schedules', permission: 'schedules.view' }
    case 'fixed_asset':
    case 'fixed_asset_depreciation_run':
      return { href: '/fixed-assets', permission: 'assets.view' }
    case 'transfer':
      return { href: '/treasury', permission: 'accounts.view' }
    default:
      return null
  }
}

function SourceDocument({ line }: { line: StatementLine }) {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const source = statementSource(line)
  const allowed = !source?.permission || can(source.permission)

  if (!line.source_type && !line.source_reference) {
    return <span className="text-text-subtlest">—</span>
  }

  return (
    <span className="text-small flex flex-col gap-0.5">
      {line.source_reference && source && allowed ? (
        <Link
          to={source.href}
          title={t('openRecord')}
          className="inline-flex items-center gap-1 font-medium text-brand-1-accent hover:underline"
        >
          <span dir="ltr">{line.source_reference}</span>
          <Icon name="externalLink" className="h-3 w-3 shrink-0" />
        </Link>
      ) : (
        <span className="font-medium" dir="ltr">
          {line.source_reference ?? '—'}
        </span>
      )}
      {line.source_type ? <span className="text-text-subtlest">{statementSourceLabel(line.source_type, locale)}</span> : null}
    </span>
  )
}

function StatementTable({ statement }: { statement: PartyStatement }) {
  const { t, locale } = useI18n()
  const { currency, lines } = statement
  const debitOf = (line: StatementLine) => (statement.basis === 'base' ? line.base_debit_minor : line.debit_minor)
  const creditOf = (line: StatementLine) => (statement.basis === 'base' ? line.base_credit_minor : line.credit_minor)
  const currencyOf = (line: StatementLine) => (statement.basis === 'base' ? currency : (line.currency ?? currency))

  return (
    <Table>
      <thead>
        <tr>
          <Th>{t('date')}</Th>
          <Th>{t('entryNumber')}</Th>
          <Th>{t('description')}</Th>
          <Th>{t('sourceDocument')}</Th>
          <Th className="text-end">{t('debit')}</Th>
          <Th className="text-end">{t('credit')}</Th>
          <Th className="text-end">{t('runningBalance')}</Th>
        </tr>
      </thead>
      <tbody>
        {statement.opening_minor !== 0 ? (
          <tr className="bg-surface-subtle">
            <Td colSpan={4} className="font-medium">
              {t('openingBalance')}
            </Td>
            <Td className="text-end text-text-subtlest">—</Td>
            <Td className="text-end text-text-subtlest">—</Td>
            <Td className="text-end font-medium">
              <BalanceSide amountMinor={statement.opening_minor} currency={currency} />
            </Td>
          </tr>
        ) : null}
        {lines.map((line) => (
          <tr key={`${line.entry_id}-${line.number}-${line.debit_minor}-${line.credit_minor}-${line.balance_minor}`}>
            <Td className="whitespace-nowrap">{formatDate(line.entry_date, locale)}</Td>
            <Td className="font-medium">{line.number}</Td>
            <Td className="min-w-48">
              <span className="block">{line.description || line.memo || '—'}</span>
              {line.account_code ? <span className="text-small text-text-subtlest">{line.account_code}</span> : null}
            </Td>
            <Td className="min-w-36">
              <SourceDocument line={line} />
            </Td>
            <Td className="text-end">
              {debitOf(line) ? <Money amountMinor={debitOf(line)} currency={currencyOf(line)} /> : '—'}
            </Td>
            <Td className="text-end">
              {creditOf(line) ? <Money amountMinor={creditOf(line)} currency={currencyOf(line)} /> : '—'}
            </Td>
            <Td className="text-end font-medium">
              <BalanceSide amountMinor={line.balance_minor} currency={currency} />
            </Td>
          </tr>
        ))}
      </tbody>
      <tfoot>
        <tr className="bg-surface-subtle">
          <Td colSpan={4} className="font-semibold">
            {t('total')}
          </Td>
          <Td className="text-end font-semibold">
            <Money amountMinor={statement.total_debit_minor} currency={currency} />
          </Td>
          <Td className="text-end font-semibold">
            <Money amountMinor={statement.total_credit_minor} currency={currency} />
          </Td>
          <Td className="text-end font-semibold">
            <BalanceSide amountMinor={statement.closing_minor} currency={currency} />
          </Td>
        </tr>
      </tfoot>
    </Table>
  )
}
