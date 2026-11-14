import type { ReactNode } from 'react'

/**
 * Narrow screens (under the `md` breakpoint) show a list as cards instead of a table that is cut off
 * at the screen edge. Use `<TableOnly>` around the table and `<MobileCards>` next to it: exactly one of
 * the two is visible. Each card shows the record's identity, its status, two or three key values and
 * its actions, with the value labels spelled out (`<CardField>`).
 */
export function TableOnly({ children }: { children: ReactNode }) {
  return <div className="hidden md:block">{children}</div>
}

export function MobileCards<T>({
  items,
  getKey,
  label,
  children,
}: {
  items: readonly T[]
  getKey: (item: T) => string
  label: string
  children: (item: T) => ReactNode
}) {
  return (
    <ul className="flex flex-col gap-3 p-3 md:hidden" aria-label={label}>
      {items.map((item) => (
        <li key={getKey(item)} className="flex flex-col gap-3 rounded-12 border border-border-subtle bg-surface-default p-4">
          {children(item)}
        </li>
      ))}
    </ul>
  )
}

/** One labelled value of a card, label above value so a long value never squeezes the label. */
export function CardField({ label, children, align = 'start' }: { label: string; children: ReactNode; align?: 'start' | 'end' }) {
  return (
    <div className={align === 'end' ? 'min-w-0 text-end' : 'min-w-0'}>
      <dt className="text-small text-text-subtle">{label}</dt>
      <dd className="text-medium text-text-default tabular break-words">{children}</dd>
    </div>
  )
}
