import type {
  ButtonHTMLAttributes,
  ChangeEvent,
  InputHTMLAttributes,
  KeyboardEvent as ReactKeyboardEvent,
  ReactNode,
  Ref,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react'
import { Children, Fragment, isValidElement, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useI18n } from '../i18n'
import { describeError, recallError } from '../lib/errors'
import { formatMoneyParts, moneyFitStyle } from '../lib/format'
import { statusToneOf } from '../lib/labels'
import { nextTabIndex } from '../lib/tabs'
import { Icon } from './icons'
import type { IconName } from './icons'

export function cx(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ')
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

export function Button({
  variant = 'primary',
  className,
  children,
  icon,
  iconEnd,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; icon?: IconName; iconEnd?: IconName }) {
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
      {icon ? <Icon name={icon} className="h-4 w-4" /> : null}
      {children}
      {iconEnd ? <Icon name={iconEnd} className="h-4 w-4" directional /> : null}
    </button>
  )
}

/**
 * Square, icon-only action for toolbars and table rows. Always pass `label`
 * so the action stays accessible and gets a hover tooltip.
 */
export function IconButton({
  icon,
  label,
  variant = 'ghost',
  className,
  ...props
}: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & {
  icon: IconName
  label: string
  variant?: ButtonVariant
}) {
  return (
    <Button variant={variant} aria-label={label} title={label} className={cx('h-8 w-8 px-0', className)} {...props}>
      <Icon name={icon} className="h-4 w-4" />
    </Button>
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

export function Input({
  className,
  icon,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { icon?: IconName }) {
  if (!icon) return <input className={cx(controlBase, 'h-9', className)} {...props} />
  return (
    <div className={cx('relative', className)}>
      <Icon
        name={icon}
        className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle"
      />
      <input className={cx(controlBase, 'h-9 ps-9')} {...props} />
    </div>
  )
}

export type SelectOption = {
  value: string
  label: string
  hint?: string
  /** Optional account/cost-center style code: shown as secondary monospace text before the label. */
  code?: string
  disabled?: boolean
}

const optionText = (option: SelectOption) => (option.code ? `${option.code} — ${option.label}` : option.label)

const textOf = (node: ReactNode): string =>
  Children.toArray(node)
    .map((child) => (typeof child === 'string' || typeof child === 'number' ? String(child) : isValidElement<{ children?: ReactNode }>(child) ? textOf(child.props.children) : ''))
    .join('')

/** `<option>` children (also inside fragments and `<optgroup>`) as options. */
function optionsFromChildren(children: ReactNode): SelectOption[] {
  const out: SelectOption[] = []
  const visit = (nodes: ReactNode) => {
    Children.forEach(nodes, (child) => {
      if (!isValidElement<{ value?: string | number; disabled?: boolean; children?: ReactNode }>(child)) return
      if (child.type === Fragment || child.type === 'optgroup') visit(child.props.children)
      else if (child.type === 'option') {
        const label = textOf(child.props.children)
        out.push({ value: child.props.value !== undefined ? String(child.props.value) : label, label, disabled: child.props.disabled })
      }
    })
  }
  visit(children)
  return out
}

/** Searches only pay off on longer lists; a short list is just a clean list. */
const SEARCH_THRESHOLD = 8

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange' | 'value' | 'children'> & {
  value?: string | number | readonly string[]
  onChange?: (event: ChangeEvent<HTMLSelectElement>) => void
  children?: ReactNode
  /** Force the search box on or off; by default it shows for lists of 8 or more options. */
  showSearch?: boolean
}

/**
 * The app's dropdown. Same API as the native `<select>` (`<option>` children, `event.target.value`) but
 * rendered with the app's own list (see SearchableSelect) so every dropdown looks and behaves the same:
 * keyboard navigation, a search box on longer lists, a panel that is never clipped by a modal or table.
 */
export function Select({ className, children, value, onChange, disabled, id, showSearch, dir, ...rest }: SelectProps) {
  const options = useMemo(() => optionsFromChildren(children), [children])
  const current = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '')
  const extra: Record<string, string | boolean | undefined> = {}
  for (const [key, val] of Object.entries(rest)) {
    if ((key.startsWith('aria-') || key.startsWith('data-') || key === 'title') && key !== 'aria-label') extra[key] = val as string
  }
  return (
    <SearchableSelect
      value={current}
      options={options}
      onChange={(next) => onChange?.({ target: { value: next, name: rest.name, id }, currentTarget: { value: next, name: rest.name, id } } as unknown as ChangeEvent<HTMLSelectElement>)}
      disabled={disabled}
      className={className}
      id={id}
      dir={dir as 'ltr' | 'rtl' | 'auto' | undefined}
      ariaLabel={rest['aria-label']}
      showSearch={showSearch ?? options.length >= SEARCH_THRESHOLD}
      nameFromLabel
      triggerProps={extra}
    />
  )
}

/**
 * Combobox for option lists, used directly for API-driven lists (customers, suppliers, accounts) and
 * through `Select` for every static one. The list opens in a portal positioned from the trigger (flips
 * above when there is no room below), so it is never clipped by a modal, a drawer or a table wrapper.
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
  onSearch,
  hasMore,
  ariaLabel,
  showSearch = true,
  nameFromLabel = false,
  dir,
  triggerProps,
}: {
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  placeholder?: string
  clearLabel?: string
  disabled?: boolean
  className?: string
  id?: string
  /** Optional server-side search: called (debounced) with the typed query. */
  onSearch?: (query: string) => void
  /** True when the server holds more rows than were loaded: shows "more results — refine the search". */
  hasMore?: boolean
  /** Accessible name when no visible <label> wraps the control (e.g. inside a table cell). */
  ariaLabel?: string
  /** Show the visible search box (otherwise typing still jumps to matches, for keyboard users). */
  showSearch?: boolean
  /** The control sits in a <label>: let that label name it instead of the generated "Search: value". */
  nameFromLabel?: boolean
  dir?: 'ltr' | 'rtl' | 'auto'
  triggerProps?: Record<string, string | boolean | undefined>
}) {
  const { t } = useI18n()
  const listId = useId()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const searchRef = useRef(onSearch)
  const panelRef = useRef<HTMLDivElement>(null)
  const [place, setPlace] = useState<{ top?: number; bottom?: number; left: number; width: number; maxHeight: number } | null>(null)

  useEffect(() => {
    searchRef.current = onSearch
  }, [onSearch])

  useEffect(() => {
    if (!open || !searchRef.current) return
    const timer = setTimeout(() => searchRef.current?.(query), 250)
    return () => clearTimeout(timer)
  }, [open, query])

  const selected = useMemo(() => options.find((option) => option.value === value), [options, value])

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const filtered = needle
      ? options.filter(
          (option) => optionText(option).toLowerCase().includes(needle) || (option.hint ?? '').toLowerCase().includes(needle),
        )
      : options
    return clearLabel ? [{ value: '', label: clearLabel }, ...filtered] : filtered
  }, [options, query, clearLabel])

  const close = (restoreFocus = false) => {
    setOpen(false)
    setQuery('')
    if (restoreFocus) triggerRef.current?.focus()
  }

  // Close on any press outside the trigger and the (portaled) panel, including touch.
  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: Event) => {
      const target = event.target as Node
      if (containerRef.current?.contains(target) || panelRef.current?.contains(target)) return
      close()
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [open])

  // Position from the trigger: below, or above when there is no room; clamped into the viewport.
  useLayoutEffect(() => {
    if (!open) {
      setPlace(null)
      return
    }
    const measure = () => {
      const trigger = triggerRef.current
      if (!trigger) return
      const rect = trigger.getBoundingClientRect()
      const margin = 8
      const rtl = getComputedStyle(trigger).direction === 'rtl'
      const width = Math.min(Math.max(rect.width, 224), window.innerWidth - margin * 2)
      let left = rtl ? rect.right - width : rect.left
      left = Math.max(margin, Math.min(left, window.innerWidth - margin - width))
      const below = window.innerHeight - rect.bottom - margin
      const above = rect.top - margin
      const wanted = Math.min(320, (panelRef.current?.scrollHeight ?? 320) + 4)
      if (below >= Math.min(wanted, 200) || below >= above) setPlace({ top: rect.bottom + 4, left, width, maxHeight: Math.max(120, below - 4) })
      else setPlace({ bottom: window.innerHeight - rect.top + 4, left, width, maxHeight: Math.max(120, above - 4) })
    }
    measure()
    window.addEventListener('resize', measure)
    window.addEventListener('scroll', measure, true)
    return () => {
      window.removeEventListener('resize', measure)
      window.removeEventListener('scroll', measure, true)
    }
  }, [open, rows.length])

  // The (visible or screen-reader-only) search input takes focus when the list opens.
  useEffect(() => {
    if (open) searchInputRef.current?.focus()
  }, [open])

  // Keep the active row in view while arrowing.
  useEffect(() => {
    if (!open || activeIndex < 0) return
    panelRef.current?.querySelector<HTMLElement>(`[data-row="${activeIndex}"]`)?.scrollIntoView({ block: 'nearest' })
  }, [open, activeIndex])

  const toggle = () => {
    if (disabled) return
    if (open) {
      close()
      return
    }
    setQuery('')
    setActiveIndex(Math.max(0, options.findIndex((option) => option.value === value) + (clearLabel ? 1 : 0)))
    setOpen(true)
  }

  const select = (option: SelectOption) => {
    if (option.disabled) return
    onChange(option.value)
    close(true)
  }

  const step = (from: number, delta: 1 | -1) => {
    let next = from
    for (let i = 0; i < rows.length; i += 1) {
      next = Math.min(Math.max(next + delta, 0), rows.length - 1)
      if (!rows[next]?.disabled) return next
      if (next === 0 || next === rows.length - 1) break
    }
    return from
  }

  const onTriggerKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) toggle()
    }
  }

  const onSearchKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((current) => step(current, 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((current) => step(current, -1))
    } else if (event.key === 'Home') {
      event.preventDefault()
      setActiveIndex(step(-1, 1))
    } else if (event.key === 'End') {
      event.preventDefault()
      setActiveIndex(step(rows.length, -1))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      // The list is portaled, but React still bubbles the event to the picker's ancestors (a drawer or form
      // that submits on Enter); choosing an option must not also submit them.
      event.stopPropagation()
      if (rows[activeIndex]) select(rows[activeIndex])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      close(true)
    } else if (event.key === 'Tab') {
      close()
    }
  }

  const label = ariaLabel ?? (nameFromLabel ? undefined : placeholder ?? t('search'))

  return (
    <div ref={containerRef} className={cx('relative', className)}>
      <button
        ref={triggerRef}
        id={id}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        // role=combobox does not take its name from content: a wrapping <label> (nameFromLabel) or an explicit name does.
        aria-label={label ? [label, selected ? optionText(selected) : undefined].filter(Boolean).join(': ') : undefined}
        disabled={disabled}
        dir={dir}
        className={cx(controlBase, 'flex h-9 items-center justify-between gap-2 pe-2 text-start')}
        onClick={toggle}
        onKeyDown={onTriggerKeyDown}
        {...triggerProps}
      >
        <span className={cx('truncate', selected ? 'text-text-default' : 'text-text-subtle')} title={selected ? optionText(selected) : undefined}>
          {selected ? optionText(selected) : placeholder ?? ''}
        </span>
        <Icon name="chevronDown" className={cx('h-4 w-4 shrink-0 text-text-subtle transition', open && 'rotate-180')} />
      </button>
      {open
        ? createPortal(
            // preventDefault on click: the list is also reachable through a wrapping <label> (Field); keep a click on the
            // panel's padding from being forwarded to the trigger.
            <div
              ref={panelRef}
              dir={dir}
              className={cx(
                'anim-pop fixed z-[70] flex flex-col overflow-hidden rounded-12 border border-border-default bg-surface-default shadow-3',
                place ? null : 'invisible',
              )}
              style={{ top: place?.top, bottom: place?.bottom, left: place?.left ?? 0, width: place?.width, maxHeight: place?.maxHeight }}
              onClick={(event) => event.preventDefault()}
            >
              <div className={showSearch ? 'border-b border-border-subtle p-2' : 'sr-only'}>
                <input
                  ref={searchInputRef}
                  className={cx(controlBase, 'h-8')}
                  value={query}
                  placeholder={t('search')}
                  autoComplete="off"
                  aria-label={t('search')}
                  onChange={(event) => {
                    setQuery(event.target.value)
                    // With a query, the first match is active: Enter must not pick the leading "All" row.
                    setActiveIndex(clearLabel && event.target.value.trim() !== '' ? 1 : 0)
                  }}
                  onKeyDown={onSearchKeyDown}
                />
              </div>
              <div id={listId} className="min-h-0 flex-1 overflow-y-auto p-1" role="listbox">
                {rows.length === 0 ? (
                  <p className="text-medium px-3 py-2 text-text-subtle">{t('noResults')}</p>
                ) : (
                  rows.map((option, index) => (
                    <button
                      key={`${option.value}-${index}`}
                      data-row={index}
                      type="button"
                      role="option"
                      tabIndex={-1}
                      aria-selected={option.value === value}
                      aria-disabled={option.disabled || undefined}
                      className={cx(
                        'text-medium flex w-full items-start gap-2 rounded-8 px-3 py-2 text-start transition',
                        option.value === value ? 'bg-surface-selected font-medium text-text-default' : 'text-text-default',
                        index === activeIndex ? 'bg-surface-hovered' : null,
                        option.disabled ? 'cursor-not-allowed text-text-disabled' : null,
                      )}
                      onMouseEnter={() => setActiveIndex(index)}
                      onClick={() => select(option)}
                    >
                      {option.code ? (
                        <span dir="ltr" className="tabular text-small shrink-0 pt-px font-mono text-text-subtle">{option.code}</span>
                      ) : null}
                      <span className="min-w-0 flex-1">
                        <span className="block break-words">{option.label}</span>
                        {option.hint ? <span className="text-small block break-words text-text-subtle">{option.hint}</span> : null}
                      </span>
                      {option.value === value ? <Icon name="check" className="mt-1 h-4 w-4 shrink-0 text-brand-1-accent" /> : null}
                    </button>
                  ))
                )}
                {hasMore ? <p className="text-small border-t border-border-subtle px-3 py-2 text-text-subtle">{t('moreResults')}</p> : null}
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { ref?: Ref<HTMLTextAreaElement> }) {
  return <textarea className={cx(controlBase, 'min-h-24 py-2', className)} {...props} />
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <section className={cx('rounded-16 border border-border-subtle bg-surface-default shadow-2', className)}>
      {children}
    </section>
  )
}

export function CardHeader({
  title,
  subtitle,
  actions,
  icon,
}: {
  title: string
  subtitle?: string
  actions?: ReactNode
  icon?: IconName
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
      <div className="flex min-w-0 items-start gap-3">
        {icon ? (
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-12 bg-surface-selected text-brand-1-accent">
            <Icon name={icon} size={18} />
          </span>
        ) : null}
        <div className="min-w-0">
          <h2 className="heading-small text-text-default">{title}</h2>
          {subtitle ? <p className="text-medium mt-0.5 text-text-subtle">{subtitle}</p> : null}
        </div>
      </div>
      {actions}
    </div>
  )
}

export function PageHeader({
  title,
  subtitle,
  actions,
  icon,
}: {
  title: string
  subtitle?: string
  actions?: ReactNode
  icon?: IconName
}) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 items-center gap-3">
        {icon ? (
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-16 border border-border-subtle bg-surface-default text-brand-1-accent shadow-1">
            <Icon name={icon} size={22} />
          </span>
        ) : null}
        <div className="min-w-0">
          <h1 className="heading-large text-text-default">{title}</h1>
          {subtitle ? <p className="text-medium mt-1 text-text-subtle">{subtitle}</p> : null}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2">{actions}</div>
    </div>
  )
}

type BadgeTone = 'neutral' | 'info' | 'positive' | 'warning' | 'negative' | 'brand'

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: BadgeTone }) {
  const tones: Record<BadgeTone, string> = {
    neutral: 'border-border-subtle bg-surface-subtle text-text-subtle',
    info: 'border-info-border bg-info-background text-info-text',
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

/** Semantic tone for a status code; the map lives in lib/labels.ts. */
export function statusTone(status: string): BadgeTone {
  return statusToneOf(status)
}

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto" tabIndex={0}>
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

export function Td({
  children,
  className,
  dir,
  colSpan,
}: {
  children?: ReactNode
  className?: string
  dir?: 'ltr' | 'rtl'
  colSpan?: number
}) {
  return (
    <td dir={dir} colSpan={colSpan} className={cx('border-b border-border-subtle px-4 py-3 align-middle text-text-default', className)}>
      {children}
    </td>
  )
}

export function EmptyState({
  title,
  hint,
  description,
  action,
  icon,
  variant = 'default',
  onClear,
}: {
  title?: string
  hint?: string
  /** Alias of `hint`. */
  description?: string
  action?: ReactNode
  icon?: IconName
  /** `filtered`: the list is empty because of the active filters/search; offers a clear-filters action when `onClear` is passed. */
  variant?: 'default' | 'filtered'
  onClear?: () => void
}) {
  const { t } = useI18n()
  const filtered = variant === 'filtered'
  const body = description ?? hint ?? (filtered ? t('noResultsHint') : undefined)
  const clear =
    filtered && onClear ? (
      <Button variant="secondary" icon="x" onClick={onClear}>
        {t('clearFilters')}
      </Button>
    ) : null
  return (
    <div className="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
      <span className="mb-1 flex h-12 w-12 items-center justify-center rounded-full bg-surface-subtle text-text-subtle">
        <Icon name={icon ?? (filtered ? 'search' : 'inbox')} size={22} />
      </span>
      <p className="text-medium font-medium text-text-default">{title ?? (filtered ? t('noResults') : t('noData'))}</p>
      {body ? <p className="text-medium max-w-sm text-text-subtle">{body}</p> : null}
      {clear}
      {action}
    </div>
  )
}

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'inline-block h-6 w-6 animate-spin rounded-full border-2 border-border-subtle border-t-action-primary-background motion-reduce:animate-none',
        className,
      )}
    />
  )
}

/** One grey placeholder bar. Pulses unless the user prefers reduced motion. */
export function Skeleton({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cx('block rounded-md bg-surface-subtlest motion-safe:animate-pulse', className)} />
}

const LINE_WIDTHS = ['w-full', 'w-11/12', 'w-3/4', 'w-5/6', 'w-2/3']

/** Placeholder `<tr>` rows for a `<tbody>`; keeps the table header and column count while data loads. */
export function SkeletonRows({ rows = 5, columns = 4 }: { rows?: number; columns?: number }) {
  return (
    <>
      {Array.from({ length: rows }, (_, row) => (
        <tr key={row} aria-hidden="true">
          {Array.from({ length: columns }, (_, col) => (
            <td key={col} className="border-b border-border-subtle px-4 py-3.5">
              <Skeleton className={cx('h-4', LINE_WIDTHS[(row + col) % LINE_WIDTHS.length])} />
            </td>
          ))}
        </tr>
      ))}
    </>
  )
}

/** Placeholder cards for a grid of summary tiles or record cards. */
export function SkeletonCards({ count = 4, className }: { count?: number; className?: string }) {
  return (
    <div aria-hidden="true" className={cx('grid gap-3 sm:grid-cols-2 lg:grid-cols-4', className)}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="flex flex-col gap-3 rounded-lg border border-border-subtle bg-surface-default p-4">
          <Skeleton className="h-3 w-1/3" />
          <Skeleton className="h-6 w-2/3" />
          <Skeleton className="h-3 w-1/2" />
        </div>
      ))}
    </div>
  )
}

type LoadingVariant = 'block' | 'table' | 'cards' | 'form' | 'spinner'

/**
 * Loading placeholder. The default `block` is a neutral stack of text lines; pass the
 * variant that matches what is being replaced (`table`, `cards`, `form`) so the layout
 * does not jump when data arrives. `spinner` keeps the old centred spinner.
 */
export function Loading({ className, variant = 'block', rows }: { className?: string; variant?: LoadingVariant; rows?: number }) {
  const { t } = useI18n()
  let body: ReactNode
  if (variant === 'spinner') {
    body = <Spinner />
  } else if (variant === 'table') {
    body = (
      <div aria-hidden="true" className="overflow-hidden rounded-lg border border-border-subtle">
        <div className="bg-surface-subtle px-4 py-3">
          <Skeleton className="h-3 w-1/4" />
        </div>
        <table className="w-full border-collapse">
          <tbody>
            <SkeletonRows rows={rows ?? 5} columns={4} />
          </tbody>
        </table>
      </div>
    )
  } else if (variant === 'cards') {
    body = <SkeletonCards count={rows ?? 4} />
  } else if (variant === 'form') {
    body = (
      <div aria-hidden="true" className="grid gap-4 sm:grid-cols-2">
        {Array.from({ length: rows ?? 4 }, (_, i) => (
          <div key={i} className="flex flex-col gap-2">
            <Skeleton className="h-3 w-1/4" />
            <Skeleton className="h-10 w-full" />
          </div>
        ))}
      </div>
    )
  } else {
    body = (
      <div aria-hidden="true" className="flex flex-col gap-3">
        {Array.from({ length: rows ?? 3 }, (_, i) => (
          <Skeleton key={i} className={cx('h-4', LINE_WIDTHS[i % LINE_WIDTHS.length])} />
        ))}
      </div>
    )
  }
  return (
    <div
      className={cx(variant === 'spinner' ? 'flex items-center justify-center px-6 py-16' : 'px-4 py-6', className)}
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <span className="sr-only">{t('loading')}</span>
      {body}
    </div>
  )
}

/**
 * Friendly failure panel. Existing callers pass only `message` (from `errorMessage()`); the
 * original error is recovered from the registry in lib/errors so the headline can be a
 * translated sentence chosen by HTTP status, with the server's own text under "details".
 * Callers may also pass `error` directly.
 */
export function ErrorState({ message, onRetry, error }: { message: string; onRetry?: () => void; error?: unknown }) {
  const { t, locale } = useI18n()
  const source = error ?? recallError(message)
  const info = source ? describeError(source, locale) : null
  const title = info ? info.title : t('error')
  const hint = info ? info.hint : message
  const detailParts = info
    ? [info.detail && info.detail !== info.title ? info.detail : null, info.status ? `HTTP ${info.status}` : null, info.code ?? null].filter(Boolean)
    : []
  return (
    <div role="alert" className="flex flex-col items-center gap-3 px-6 py-12 text-center">
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-danger-background text-danger-text">
        <Icon name="alertTriangle" size={22} />
      </span>
      <p className="text-medium font-medium text-danger-text">{title}</p>
      <p className="text-medium max-w-md text-text-subtle">{hint}</p>
      {onRetry ? (
        <Button variant="secondary" icon="refresh" onClick={onRetry}>
          {t('retry')}
        </Button>
      ) : null}
      {info && (detailParts.length > 0 || info.requestId) ? (
        <details className="text-small max-w-md text-text-subtle">
          <summary className="cursor-pointer select-none rounded-md px-1 py-0.5 hover:text-text-default focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-border-focus">
            {t('errorDetails')}
          </summary>
          <div className="mt-2 flex flex-col gap-1 text-start" dir="auto">
            {detailParts.length > 0 ? <p className="break-words">{detailParts.join(' · ')}</p> : null}
            {info.requestId ? (
              <p>
                {t('errorRequestId')}: <bdi dir="ltr" className="font-mono select-all">{info.requestId}</bdi>
              </p>
            ) : null}
          </div>
        </details>
      ) : null}
    </div>
  )
}

export function InlineNotice({
  tone = 'info',
  children,
}: {
  tone?: 'positive' | 'warning' | 'negative' | 'info'
  children: ReactNode
}) {
  const styles = {
    positive: 'border-success-border bg-success-background text-success-text',
    warning: 'border-warning-border bg-warning-background text-warning-text',
    negative: 'border-danger-border bg-danger-background text-danger-text',
    info: 'border-info-border bg-info-background text-info-text',
  }
  const icons: Record<typeof tone, IconName> = {
    positive: 'checkCircle',
    warning: 'alertTriangle',
    negative: 'alertTriangle',
    info: 'info',
  }
  return (
    <div className={cx('text-medium flex items-start gap-2 rounded-12 border px-3 py-2', styles[tone])}>
      <Icon name={icons[tone]} className="mt-0.5 h-4 w-4" />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

export function StatCard({
  label,
  value,
  hint,
  tone,
  icon,
}: {
  label: string
  value: ReactNode
  hint?: string
  tone?: 'positive' | 'negative'
  icon?: IconName
}) {
  return (
    <Card className="p-6">
      <div className="flex items-start justify-between gap-3">
        <p className="text-medium text-text-subtle">{label}</p>
        {icon ? (
          <span
            className={cx(
              'flex h-9 w-9 shrink-0 items-center justify-center rounded-12',
              tone === 'negative'
                ? 'bg-danger-background text-danger-text'
                : tone === 'positive'
                  ? 'bg-success-background text-success-text'
                  : 'bg-surface-selected text-brand-1-accent',
            )}
          >
            <Icon name={icon} size={18} />
          </span>
        ) : null}
      </div>
      <p className={cx('heading-large mt-2 tabular', tone === 'positive' && 'text-success-text', tone === 'negative' && 'text-danger-text')}>
        {value}
      </p>
      {hint ? <p className="text-small mt-1 text-text-subtle">{hint}</p> : null}
    </Card>
  )
}

/**
 * The one amount rule: a single nowrap, left-to-right, bidi-isolated run with
 * the minus sign first (and the danger colour), tabular digits and the full
 * value in `title`. Inside a `.money-fit` container the font steps down so a
 * 15-digit value still fits on one line.
 */
export function Money({
  amountMinor,
  currency,
  className,
}: {
  amountMinor?: number | null
  currency: string
  className?: string
}) {
  const { locale } = useI18n()
  const { negative, segments, text } = formatMoneyParts(amountMinor, currency, locale)
  return (
    <span dir="ltr" title={text} style={moneyFitStyle(text)} className={cx('money-run', negative && 'text-danger-text', className)}>
      {segments.map((segment, index) =>
        segment.kind === 'currency' ? <bdi key={index} dir="auto">{segment.value}</bdi> : <span key={index}>{segment.value}</span>,
      )}
    </span>
  )
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
  const { t } = useI18n()
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
    <div className="anim-backdrop fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-6" role="presentation" onMouseDown={onClose}>
      <div
        className="anim-sheet max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-24 bg-surface-default shadow-4 sm:rounded-16"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
          <h2 className="heading-small">{title}</h2>
          <IconButton icon="x" label={t('closeLabel')} onClick={onClose} />
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
  total,
  perPage,
}: {
  page: number
  lastPage: number
  onChange: (page: number) => void
  /** Optional: when given with `perPage`, shows a "1-20 of 120" range next to the pager. */
  total?: number
  perPage?: number
}) {
  const { t, locale } = useI18n()
  const hasRange = total !== undefined && perPage !== undefined && total > 0
  if (lastPage <= 1 && !hasRange) return null
  const from = hasRange ? (page - 1) * perPage + 1 : 0
  const to = hasRange ? Math.min(page * perPage, total) : 0
  const fmt = (value: number) => new Intl.NumberFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US').format(value)
  return (
    <div className="text-medium flex items-center justify-between gap-2 px-4 py-3">
      <span className="tabular text-small text-text-subtle">
        {hasRange ? (locale === 'ar' ? `${fmt(from)}-${fmt(to)} من ${fmt(total)}` : `${fmt(from)}-${fmt(to)} of ${fmt(total)}`) : null}
      </span>
      {lastPage > 1 ? (
      <div className="flex items-center gap-2">
      <IconButton icon="chevronLeft" label={t('previousPage')} variant="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)} />
      <span className="tabular px-1 text-text-subtle">
        {page} / {lastPage}
      </span>
      <IconButton icon="chevronRight" label={t('nextPage')} variant="secondary" disabled={page >= lastPage} onClick={() => onChange(page + 1)} />
      </div>
      ) : null}
    </div>
  )
}

export type TabItem = { id: string; label: string; icon?: IconName }

/**
 * Accessible tab bar (WAI-ARIA tabs, automatic activation). Arrow keys follow
 * the visual direction (mirrored in RTL), Home/End jump to the ends, and the
 * bar scrolls horizontally on narrow screens. Pair each tab with a `TabPanel`
 * using the same `idPrefix`.
 */
export function Tabs({
  tabs,
  active,
  onChange,
  label,
  idPrefix,
}: {
  tabs: TabItem[]
  active: string
  onChange: (id: string) => void
  label: string
  idPrefix: string
}) {
  const listRef = useRef<HTMLDivElement>(null)
  const [overflow, setOverflow] = useState({ start: false, end: false })

  // Edge cues: show a fade + chevron on a side only while more tabs are hidden there.
  useEffect(() => {
    const list = listRef.current
    if (!list) return
    const update = () => {
      const max = list.scrollWidth - list.clientWidth
      const offset = Math.abs(list.scrollLeft) // negative in RTL
      setOverflow((prev) => {
        const next = { start: offset > 2, end: offset < max - 2 }
        return prev.start === next.start && prev.end === next.end ? prev : next
      })
    }
    update()
    list.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)
    return () => {
      list.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
    }
  }, [tabs.length])

  // Keep the active tab visible (scrolls only the tab strip, never the page).
  useEffect(() => {
    const list = listRef.current
    const button = document.getElementById(`${idPrefix}-tab-${active}`)
    if (!list || !button) return
    const listRect = list.getBoundingClientRect()
    const rect = button.getBoundingClientRect()
    const delta = rect.left + rect.width / 2 - (listRect.left + listRect.width / 2)
    if (rect.left < listRect.left || rect.right > listRect.right) list.scrollBy({ left: delta })
  }, [active, idPrefix])

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    const current = tabs.findIndex((tab) => tab.id === active)
    const rtl = listRef.current ? getComputedStyle(listRef.current).direction === 'rtl' : false
    const next = nextTabIndex(event.key, current < 0 ? 0 : current, tabs.length, rtl)
    if (next === null) return
    event.preventDefault()
    onChange(tabs[next].id)
    window.requestAnimationFrame(() => document.getElementById(`${idPrefix}-tab-${tabs[next].id}`)?.focus())
  }

  const fade = 'pointer-events-none absolute inset-y-px z-10 flex w-9 items-center from-surface-default via-surface-default/80 to-transparent text-text-subtle'
  return (
    <div className="no-print relative mb-6">
    {overflow.start ? (
      <span aria-hidden="true" className={cx(fade, 'start-px justify-start rounded-s-16 bg-gradient-to-r ps-1 rtl:bg-gradient-to-l')}>
        <Icon name="chevronLeft" className="h-4 w-4 rtl:rotate-180" />
      </span>
    ) : null}
    {overflow.end ? (
      <span aria-hidden="true" className={cx(fade, 'end-px justify-end rounded-e-16 bg-gradient-to-l pe-1 rtl:bg-gradient-to-r')}>
        <Icon name="chevronRight" className="h-4 w-4 rtl:rotate-180" />
      </span>
    ) : null}
    <div
      ref={listRef}
      role="tablist"
      aria-label={label}
      onKeyDown={onKeyDown}
      className="flex gap-1 overflow-x-auto rounded-16 border border-border-subtle bg-surface-default p-1 shadow-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
      {tabs.map((tab) => {
        const selected = tab.id === active
        return (
          <button
            key={tab.id}
            id={`${idPrefix}-tab-${tab.id}`}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls={`${idPrefix}-panel-${tab.id}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(tab.id)}
            className={cx(
              'text-small inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-12 px-3 py-2 font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-border-focus',
              selected ? 'bg-surface-selected text-text-default' : 'text-text-subtle hover:bg-surface-hovered hover:text-text-default',
            )}
          >
            {tab.icon ? <Icon name={tab.icon} className="h-4 w-4" /> : null}
            {tab.label}
          </button>
        )
      })}
    </div>
    </div>
  )
}

/** Panel for the active tab; render only the active one so its queries do not fire while hidden. */
export function TabPanel({ idPrefix, id, children }: { idPrefix: string; id: string; children: ReactNode }) {
  return (
    <div
      role="tabpanel"
      id={`${idPrefix}-panel-${id}`}
      aria-labelledby={`${idPrefix}-tab-${id}`}
      tabIndex={0}
      className="outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
    >
      {children}
    </div>
  )
}
