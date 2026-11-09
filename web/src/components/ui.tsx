import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useEffect } from 'react'
import { useI18n } from '../i18n'
import { formatMoney } from '../lib/format'

export function cx(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ')
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

export function Button({
  variant = 'primary',
  className,
  children,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
  const styles: Record<ButtonVariant, string> = {
    primary:
      'bg-action-primary-background text-action-primary-text hover:bg-action-primary-hovered active:bg-action-primary-pressed disabled:opacity-50',
    secondary:
      'border border-action-secondary-border bg-action-secondary-background text-action-secondary-text hover:bg-action-secondary-hovered active:bg-action-secondary-pressed disabled:opacity-50',
    ghost: 'text-text-subtle hover:bg-surface-hovered hover:text-text-default disabled:text-text-disabled',
    danger: 'border border-danger-border bg-surface-default text-danger-text hover:bg-danger-background disabled:opacity-50',
  }
  return (
    <button
      className={cx(
        'label inline-flex h-9 items-center justify-center gap-2 rounded-12 px-4 transition disabled:cursor-not-allowed',
        styles[variant],
        className,
      )}
      {...props}
    >
      {children}
    </button>
  )
}

export function Field({
  label,
  error,
  required,
  children,
  hint,
}: {
  label: string
  error?: string
  required?: boolean
  hint?: string
  children: ReactNode
}) {
  return (
    <label className="flex flex-col text-text-default">
      <span className="label mb-3">
        {label}
        {required ? <span className="text-danger-text"> *</span> : null}
      </span>
      {children}
      {hint && !error ? <span className="text-small mt-1 text-text-subtlest">{hint}</span> : null}
      {error ? <span className="text-small mt-1 text-danger-text">{error}</span> : null}
    </label>
  )
}

const controlBase =
  'text-medium w-full rounded-12 border border-border-default bg-surface-default px-3 text-text-default shadow-1 outline-none transition placeholder:text-text-placeholder focus:border-border-focus focus:ring-2 focus:ring-border-focus/25 disabled:bg-surface-disabled disabled:text-text-disabled'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={cx(controlBase, 'h-9', className)} {...props} />
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className={cx(controlBase, 'h-9', className)} {...props}>
      {children}
    </select>
  )
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={cx(controlBase, 'min-h-24 py-2', className)} {...props} />
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <section className={cx('rounded-16 border border-border-subtle bg-surface-default shadow-2', className)}>
      {children}
    </section>
  )
}

export function CardHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
      <div>
        <h2 className="heading-small text-text-default">{title}</h2>
        {subtitle ? <p className="text-medium mt-0.5 text-text-subtle">{subtitle}</p> : null}
      </div>
      {actions}
    </div>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="heading-large text-text-default">{title}</h1>
        {subtitle ? <p className="text-medium mt-1 text-text-subtle">{subtitle}</p> : null}
      </div>
      <div className="flex flex-wrap items-center gap-2">{actions}</div>
    </div>
  )
}

type BadgeTone = 'neutral' | 'positive' | 'warning' | 'negative' | 'brand'

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: BadgeTone }) {
  const tones: Record<BadgeTone, string> = {
    neutral: 'border-border-subtle bg-surface-subtle text-text-subtle',
    positive: 'border-success-border bg-success-background text-success-text',
    warning: 'border-warning-border bg-warning-background text-warning-text',
    negative: 'border-danger-border bg-danger-background text-danger-text',
    brand: 'border-border-selected bg-surface-selected text-text-default',
  }
  return (
    <span className={cx('text-small inline-flex items-center rounded-6 border px-2.5 py-0.5 font-medium', tones[tone])}>
      {children}
    </span>
  )
}

export function statusTone(status: string): BadgeTone {
  switch (status) {
    case 'confirmed':
    case 'completed':
    case 'posted':
    case 'paid':
    case 'approved':
      return 'positive'
    case 'draft':
    case 'quoted':
    case 'accrued':
    case 'partially_paid':
      return 'warning'
    case 'cancelled':
    case 'no_show':
    case 'void':
      return 'negative'
    default:
      return 'brand'
  }
}

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="text-medium w-full min-w-[640px] border-collapse">{children}</table>
    </div>
  )
}

export function Th({ children, className }: { children?: ReactNode; className?: string }) {
  return (
    <th className={cx('text-small border-b border-border-subtle bg-surface-subtle px-4 py-3 text-start font-semibold uppercase tracking-wide text-text-subtle', className)}>
      {children}
    </th>
  )
}

export function Td({ children, className, dir }: { children?: ReactNode; className?: string; dir?: 'ltr' | 'rtl' }) {
  return (
    <td dir={dir} className={cx('border-b border-border-subtle px-4 py-3 align-middle text-text-default', className)}>
      {children}
    </td>
  )
}

export function EmptyState({ title, hint, action }: { title?: string; hint?: string; action?: ReactNode }) {
  const { t } = useI18n()
  return (
    <div className="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
      <p className="text-medium font-medium text-text-default">{title ?? t('noData')}</p>
      {hint ? <p className="text-medium max-w-sm text-text-subtle">{hint}</p> : null}
      {action}
    </div>
  )
}

export function Loading() {
  const { t } = useI18n()
  return <div className="text-medium px-6 py-16 text-center text-text-subtle">{t('loading')}</div>
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const { t } = useI18n()
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
      <p className="text-medium font-medium text-danger-text">{t('error')}</p>
      <p className="text-medium max-w-md text-text-subtle">{message}</p>
      {onRetry ? (
        <Button variant="secondary" onClick={onRetry}>
          {t('retry')}
        </Button>
      ) : null}
    </div>
  )
}

export function StatCard({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: string; tone?: 'positive' | 'negative' }) {
  return (
    <Card className="p-6">
      <p className="text-medium text-text-subtle">{label}</p>
      <p className={cx('heading-large mt-2 tabular', tone === 'positive' && 'text-positive', tone === 'negative' && 'text-negative')}>
        {value}
      </p>
      {hint ? <p className="text-small mt-1 text-text-subtle">{hint}</p> : null}
    </Card>
  )
}

export function Money({ amountMinor, currency }: { amountMinor?: number | null; currency: string }) {
  const { locale } = useI18n()
  return <span className="tabular">{formatMoney(amountMinor, currency, locale)}</span>
}

export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
}: {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-6" role="presentation" onMouseDown={onClose}>
      <div
        className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-24 bg-surface-default shadow-4 sm:rounded-16"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
          <h2 className="heading-small">{title}</h2>
          <button className="rounded-8 p-1 text-text-subtle hover:bg-surface-hovered" onClick={onClose} aria-label="Close">
            ✕
          </button>
        </div>
        <div className="px-5 py-5">{children}</div>
        {footer ? <div className="flex justify-end gap-2 border-t border-border-subtle px-5 py-4">{footer}</div> : null}
      </div>
    </div>
  )
}

export function Pagination({
  page,
  lastPage,
  onChange,
}: {
  page: number
  lastPage: number
  onChange: (page: number) => void
}) {
  if (lastPage <= 1) return null
  return (
    <div className="text-medium flex items-center justify-end gap-2 px-4 py-3">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        ‹
      </Button>
      <span className="tabular text-text-subtle">
        {page} / {lastPage}
      </span>
      <Button variant="secondary" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
        ›
      </Button>
    </div>
  )
}
