import type {
  ButtonHTMLAttributes,
  InputHTMLAttributes,
  KeyboardEvent as ReactKeyboardEvent,
  ReactNode,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react'
import { useEffect, useMemo, useRef, useState } from 'react'
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

export type SelectOption = {
  value: string
  label: string
  hint?: string
}

/**
 * Combobox for API-driven option lists.
 *
 * Native `<select>` stays the right control for short, static lists. Use this
 * when the list is dynamic (customers, suppliers, accounts, bookings) and the
 * user benefits from typing to filter.
 */
export function SearchableSelect({
  value,
  onChange,
  options,
  placeholder,
  clearLabel,
  disabled,
  className,
  id,
}: {
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  placeholder?: string
  clearLabel?: string
  disabled?: boolean
  className?: string
  id?: string
}) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const containerRef = useRef<HTMLDivElement>(null)

  const selected = useMemo(() => options.find((option) => option.value === value), [options, value])

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const filtered = needle
      ? options.filter(
          (option) => option.label.toLowerCase().includes(needle) || (option.hint ?? '').toLowerCase().includes(needle),
        )
      : options
    return clearLabel ? [{ value: '', label: clearLabel }, ...filtered] : filtered
  }, [options, query, clearLabel])

  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
        setQuery('')
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  const openList = () => {
    if (disabled) return
    setQuery('')
    setActiveIndex(options.findIndex((option) => option.value === value))
    setOpen(true)
  }

  const select = (option: SelectOption) => {
    onChange(option.value)
    setOpen(false)
    setQuery('')
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (!open) {
        openList()
        return
      }
      setActiveIndex((current) => Math.min(current + 1, rows.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((current) => Math.max(current - 1, 0))
    } else if (event.key === 'Enter') {
      if (open && rows[activeIndex]) {
        event.preventDefault()
        select(rows[activeIndex])
      }
    } else if (event.key === 'Escape') {
      setOpen(false)
      setQuery('')
    } else if (event.key === 'Tab') {
      setOpen(false)
    }
  }

  return (
    <div ref={containerRef} className={cx('relative', className)}>
      <input
        id={id}
        className={cx(controlBase, 'h-9 pe-8')}
        value={open ? query : (selected?.label ?? '')}
        placeholder={placeholder}
        disabled={disabled}
        role="combobox"
        aria-expanded={open}
        aria-autocomplete="list"
        autoComplete="off"
        onFocus={openList}
        onChange={(event) => {
          setQuery(event.target.value)
          setActiveIndex(0)
          if (!open) setOpen(true)
        }}
        onKeyDown={onKeyDown}
      />
      <svg
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        className="pointer-events-none absolute end-2 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle"
        aria-hidden="true"
      >
        <path d="m5 7.5 5 5 5-5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      {open ? (
        <div
          className="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-12 border border-border-default bg-surface-default p-1 shadow-3"
          role="listbox"
        >
          {rows.length === 0 ? (
            <p className="text-medium px-3 py-2 text-text-subtle">{t('noResults')}</p>
          ) : (
            rows.map((option, index) => (
              <button
                key={`${option.value}-${index}`}
                type="button"
                role="option"
                aria-selected={option.value === value}
                className={cx(
                  'text-medium flex w-full items-center justify-between gap-2 rounded-8 px-3 py-2 text-start transition',
                  option.value === value ? 'bg-surface-selected text-text-default' : 'text-text-default',
                  index === activeIndex ? 'bg-surface-hovered' : null,
                )}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => select(option)}
              >
                <span className="truncate">{option.label}</span>
                {option.hint ? <span className="text-small truncate text-text-subtle">{option.hint}</span> : null}
              </button>
            ))
          )}
        </div>
      ) : null}
    </div>
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
    case 'rejected':
    case 'reversed':
      return 'negative'
    case 'open':
      return 'positive'
    case 'submitted':
      return 'warning'
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

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'inline-block h-6 w-6 animate-spin rounded-full border-2 border-border-subtle border-t-action-primary-background',
        className,
      )}
    />
  )
}

export function Loading({ className }: { className?: string }) {
  const { t } = useI18n()
  return (
    <div className={cx('flex items-center justify-center px-6 py-16', className)} role="status" aria-live="polite">
      <span className="sr-only">{t('loading')}</span>
      <Spinner />
    </div>
  )
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
