import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useI18n } from '../i18n'
import { formatDate } from '../lib/format'
import { usePartyOptions } from '../lib/usePartyOptions'
import type { PartyKind } from '../lib/usePartyOptions'
import { DateField } from './DateField'
import { Icon } from './icons'
import { Button, Field, IconButton, Input, SearchableSelect, Select, cx } from './ui'

/**
 * Program-wide filtering: ONE "Filter" button per page opens an off-canvas drawer with every filter
 * (except the quick search box, which stays inline). Choices are edited in a draft and only change
 * the list when "Apply" is pressed; the applied ones show as removable chips next to the button.
 *
 * A page declares its filters once (`useFilters(defs)`) and drops `<FilterControl state={f} />` into
 * its toolbar; it reads `f.applied.<key>` for the query. See Bookings.tsx for the reference use.
 */
export type FilterOption = { value: string; label: string }

type Base = { key: string; label: string; default?: string }
export type FilterDef =
  | (Base & { type: 'select'; options: readonly FilterOption[]; allLabel?: string })
  | (Base & { type: 'date' })
  | (Base & { type: 'text'; placeholder?: string })
  | (Base & { type: 'custom'; render: (value: string, set: (value: string, label?: string) => void) => ReactNode; format?: (value: string) => string })

export type FilterChip = { key: string; label: string; value: string }

export type FilterState = {
  defs: readonly FilterDef[]
  applied: Record<string, string>
  draft: Record<string, string>
  /** `label` is the readable text of a custom value (a customer's name), shown in the chip. */
  setDraft: (key: string, value: string, label?: string) => void
  /** Commits the draft, closes the drawer and runs `onApply` (pages reset to page 1 there). */
  apply: () => void
  /** Clears the draft to the defaults (not applied until Apply). */
  resetDraft: () => void
  clearOne: (key: string) => void
  clearAll: () => void
  chips: FilterChip[]
  count: number
  open: boolean
  openDrawer: () => void
  closeDrawer: () => void
}

type Stored = { values: Record<string, string>; labels: Record<string, string> }

function readStored(key: string | undefined, defaults: Record<string, string>): Stored {
  const empty: Stored = { values: defaults, labels: {} }
  if (!key) return empty
  try {
    const raw = sessionStorage.getItem(`tourism_erp_filters_${key}`)
    if (!raw) return empty
    const parsed = JSON.parse(raw) as { values?: Record<string, unknown>; labels?: Record<string, unknown> }
    const values = Object.fromEntries(Object.keys(defaults).map((name) => [name, typeof parsed.values?.[name] === 'string' ? (parsed.values[name] as string) : defaults[name]]))
    const labels = Object.fromEntries(Object.entries(parsed.labels ?? {}).filter(([, text]) => typeof text === 'string')) as Record<string, string>
    return { values, labels }
  } catch {
    return empty
  }
}

function writeStored(key: string | undefined, value: Stored) {
  if (!key) return
  try {
    sessionStorage.setItem(`tourism_erp_filters_${key}`, JSON.stringify(value))
  } catch {
    // not available (private window): filters simply are not remembered
  }
}

export function useFilters(defs: readonly FilterDef[], options: { persistKey?: string; onApply?: () => void } = {}): FilterState {
  const { locale } = useI18n()
  const [defaults] = useState<Record<string, string>>(() => Object.fromEntries(defs.map((def) => [def.key, def.default ?? ''])))
  const [stored] = useState(() => readStored(options.persistKey, defaults))
  const [applied, setApplied] = useState<Record<string, string>>(stored.values)
  const [labels, setLabels] = useState<Record<string, string>>(stored.labels)
  const [draft, setDraftState] = useState<Record<string, string>>(applied)
  const [draftLabels, setDraftLabels] = useState<Record<string, string>>(labels)
  const [open, setOpen] = useState(false)
  const onApply = useRef(options.onApply)
  onApply.current = options.onApply
  const persistKey = options.persistKey

  const commit = useCallback(
    (next: Record<string, string>, nextLabels: Record<string, string>) => {
      setApplied(next)
      setDraftState(next)
      setLabels(nextLabels)
      setDraftLabels(nextLabels)
      writeStored(persistKey, { values: next, labels: nextLabels })
      onApply.current?.()
    },
    [persistKey],
  )

  const chips = useMemo<FilterChip[]>(
    () =>
      defs
        .filter((def) => (applied[def.key] ?? '') !== (defaults[def.key] ?? ''))
        .map((def) => {
          const value = applied[def.key] ?? ''
          let text = value
          if (def.type === 'select') text = def.options.find((option) => option.value === value)?.label ?? value
          else if (def.type === 'date') text = formatDate(value, locale)
          else if (def.type === 'custom') text = labels[def.key] ?? def.format?.(value) ?? value
          return { key: def.key, label: def.label, value: text }
        }),
    [defs, applied, labels, defaults, locale],
  )

  return {
    defs,
    applied,
    draft,
    setDraft: (key, value, label) => {
      setDraftState((current) => ({ ...current, [key]: value }))
      setDraftLabels((current) => {
        const next = { ...current }
        if (label && value) next[key] = label
        else delete next[key]
        return next
      })
    },
    apply: () => {
      commit(draft, draftLabels)
      setOpen(false)
    },
    resetDraft: () => {
      setDraftState(defaults)
      setDraftLabels({})
    },
    clearOne: (key) => {
      const rest = { ...labels }
      delete rest[key]
      commit({ ...applied, [key]: defaults[key] ?? '' }, rest)
    },
    clearAll: () => commit(defaults, {}),
    chips,
    count: chips.length,
    open,
    openDrawer: () => {
      setDraftState(applied)
      setDraftLabels(labels)
      setOpen(true)
    },
    closeDrawer: () => setOpen(false),
  }
}

export function FilterButton({ count, onClick, expanded }: { count: number; onClick: () => void; expanded?: boolean }) {
  const { locale } = useI18n()
  const label = locale === 'ar' ? 'فلترة' : 'Filter'
  return (
    <button
      type="button"
      onClick={onClick}
      aria-haspopup="dialog"
      aria-expanded={expanded}
      className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-default bg-surface-default px-3 font-medium text-text-default shadow-1 outline-none transition hover:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus"
    >
      <Icon name="filter" className="h-4 w-4" />
      {label}
      {count > 0 ? (
        <span className="tabular flex h-5 min-w-5 items-center justify-center rounded-full bg-action-primary-background px-1.5 text-[11px] font-semibold text-action-primary-text" aria-label={locale === 'ar' ? `${count} فلتر مفعّل` : `${count} active`}>
          {count}
        </span>
      ) : null}
    </button>
  )
}

export function FilterChips({ state }: { state: FilterState }) {
  const { locale } = useI18n()
  if (state.chips.length === 0) return null
  return (
    <ul className="flex flex-wrap items-center gap-2" aria-label={locale === 'ar' ? 'الفلاتر المفعّلة' : 'Active filters'}>
      {state.chips.map((chip) => (
        <li key={chip.key} className="text-small inline-flex items-center gap-1 rounded-full border border-border-selected bg-surface-selected py-0.5 ps-3 pe-1 text-text-default">
          <span className="text-text-subtle">{chip.label}:</span>
          <span className="font-medium">{chip.value}</span>
          <button
            type="button"
            className="flex h-5 w-5 items-center justify-center rounded-full outline-none hover:bg-surface-hovered focus-visible:ring-2 focus-visible:ring-border-focus"
            aria-label={`${locale === 'ar' ? 'إزالة' : 'Remove'} ${chip.label}`}
            onClick={() => state.clearOne(chip.key)}
          >
            <Icon name="x" className="h-3 w-3" />
          </button>
        </li>
      ))}
      <li>
        <button type="button" className="text-small text-brand-1-accent underline outline-none focus-visible:ring-2 focus-visible:ring-border-focus" onClick={state.clearAll}>
          {locale === 'ar' ? 'مسح الكل' : 'Clear all'}
        </button>
      </li>
    </ul>
  )
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** The off-canvas panel on the inline-end edge (left in Arabic, right in English). */
export function FilterDrawer({ state, title, children, applyDisabled = false }: { state: FilterState; title?: string; children?: ReactNode; applyDisabled?: boolean }) {
  const { locale } = useI18n()
  const ar = locale === 'ar'
  const panelRef = useRef<HTMLDivElement>(null)
  const openerRef = useRef<HTMLElement | null>(null)
  const [entered, setEntered] = useState(false)
  const heading = title ?? (ar ? 'الفلاتر' : 'Filters')
  const { open, closeDrawer } = state

  useEffect(() => {
    if (!open) {
      setEntered(false)
      return
    }
    openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const frame = requestAnimationFrame(() => setEntered(true))
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const focusTimer = window.setTimeout(() => panelRef.current?.querySelector<HTMLElement>('input, select, textarea, button')?.focus(), 30)
    return () => {
      cancelAnimationFrame(frame)
      window.clearTimeout(focusTimer)
      document.body.style.overflow = previousOverflow
      openerRef.current?.focus()
    }
  }, [open])

  if (!open) return null

  const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation()
      closeDrawer()
      return
    }
    if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type !== 'checkbox') {
      event.preventDefault()
      if (!applyDisabled) state.apply()
      return
    }
    if (event.key !== 'Tab') return
    const items = Array.from(panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])
    if (items.length === 0) return
    const first = items[0]
    const last = items[items.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  return createPortal(
    <div className="no-print fixed inset-0 z-50" role="presentation" onKeyDown={onKeyDown}>
      <div className={cx('absolute inset-0 bg-black/40 transition-opacity duration-200 motion-reduce:transition-none', entered ? 'opacity-100' : 'opacity-0')} onMouseDown={closeDrawer} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={heading}
        className={cx(
          'absolute inset-y-0 end-0 flex w-[min(24rem,100vw)] flex-col bg-surface-default shadow-4 transition-transform duration-200 motion-reduce:transition-none',
          entered ? 'translate-x-0' : 'translate-x-full rtl:-translate-x-full',
        )}
      >
        <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
          <h2 className="heading-small">{heading}</h2>
          <IconButton icon="x" label={ar ? 'إغلاق' : 'Close'} onClick={closeDrawer} />
        </div>

        <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-5 py-5">
          {state.defs.map((def) => {
            const value = state.draft[def.key] ?? ''
            const set = (next: string, label?: string) => state.setDraft(def.key, next, label)
            if (def.type === 'select') {
              return (
                <Field key={def.key} label={def.label}>
                  <Select value={value} onChange={(event) => set(event.target.value)}>
                    <option value="">{def.allLabel ?? (ar ? 'الكل' : 'All')}</option>
                    {def.options.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              )
            }
            if (def.type === 'date') {
              return (
                <Field key={def.key} label={def.label}>
                  <DateField value={value} onChange={(event) => set(event.target.value)} />
                </Field>
              )
            }
            if (def.type === 'text') {
              return (
                <Field key={def.key} label={def.label}>
                  <Input value={value} placeholder={def.placeholder} onChange={(event) => set(event.target.value)} />
                </Field>
              )
            }
            return (
              <Field key={def.key} label={def.label}>
                {def.render(value, set)}
              </Field>
            )
          })}
          {children}
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-border-subtle px-5 py-4">
          <Button variant="secondary" onClick={state.resetDraft}>
            {ar ? 'إعادة ضبط' : 'Reset'}
          </Button>
          <Button icon="check" onClick={state.apply} disabled={applyDisabled}>
            {ar ? 'تطبيق' : 'Apply'}
          </Button>
        </div>
      </div>
    </div>,
    document.body,
  )
}

/** The button, the chips of the applied filters and the drawer, as one toolbar piece. */
export function FilterControl({ state, title }: { state: FilterState; title?: string }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <FilterButton count={state.count} onClick={state.openDrawer} expanded={state.open} />
      <FilterChips state={state} />
      <FilterDrawer state={state} title={title} />
    </div>
  )
}

/**
 * A server-searched customer / supplier / employee picker for a filter (custom field): it reports the
 * chosen name too, so the chip reads "Customer: Ahmed" instead of an id.
 */
export function PartyFilterField({ kind, value, set }: { kind: PartyKind; value: string; set: (value: string, label?: string) => void }) {
  const { locale } = useI18n()
  const picker = usePartyOptions(kind, { value, locale })
  return (
    <SearchableSelect
      value={value}
      options={picker.options}
      onSearch={picker.setSearch}
      hasMore={picker.hasMore}
      clearLabel={locale === 'ar' ? 'الكل' : 'All'}
      onChange={(next) => set(next, picker.options.find((option) => option.value === next)?.label)}
      ariaLabel={locale === 'ar' ? 'بحث' : 'Search'}
    />
  )
}
