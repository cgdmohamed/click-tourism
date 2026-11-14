import { useEffect, useRef, type ReactNode } from 'react'
import { useI18n } from '../../i18n'
import { IconButton } from '../ui'

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'

/**
 * The booking form container: a full-screen sheet on phones and a wide dialog on larger screens.
 * Header and footer stay fixed while only the sections scroll, so the summary bar in the footer is
 * always visible. Escape closes it (unless a nested dialog is open); a click on the backdrop does not,
 * because a long form must not be lost by a stray tap. Focus is trapped inside and restored on close.
 */
export function BookingFormShell({
  title,
  onClose,
  escapeDisabled,
  footer,
  children,
}: {
  title: string
  onClose: () => void
  escapeDisabled?: boolean
  footer: ReactNode
  children: ReactNode
}) {
  const { t } = useI18n()
  const dialogRef = useRef<HTMLDivElement>(null)
  const closeRef = useRef(onClose)
  const escapeRef = useRef(Boolean(escapeDisabled))
  closeRef.current = onClose
  escapeRef.current = Boolean(escapeDisabled)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !escapeRef.current) closeRef.current()
    }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    dialogRef.current?.focus()
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
      previous?.focus?.()
    }
  }, [])

  const trapTab = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'Tab' || !dialogRef.current) return
    const nodes = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((node) => node.offsetParent !== null)
    if (nodes.length === 0) return
    const first = nodes[0]
    const last = nodes[nodes.length - 1]
    const active = document.activeElement
    if (event.shiftKey && (active === first || active === dialogRef.current)) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && active === last) {
      event.preventDefault()
      first.focus()
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-stretch justify-center bg-black/40 sm:items-center sm:p-6" role="presentation">
      <div
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onKeyDown={trapTab}
        style={{ outline: "none" }}
        className="flex h-full w-full min-w-0 flex-col bg-surface-default shadow-4 sm:h-auto sm:max-h-[94vh] sm:max-w-5xl sm:rounded-16"
      >
        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border-subtle px-4 py-3 sm:px-6 sm:py-4">
          <h2 className="heading-small min-w-0 truncate">{title}</h2>
          <IconButton icon="x" label={t('closeLabel')} onClick={onClose} />
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-6">{children}</div>
        <div className="shrink-0 border-t border-border-default bg-surface-default px-4 py-3 shadow-3 sm:rounded-b-16 sm:px-6">{footer}</div>
      </div>
    </div>
  )
}

/** A titled block of the form. */
export function FormSection({ id, title, hint, children }: { id: string; title: string; hint?: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="min-w-0 border-b border-border-subtle pb-6 last:border-b-0 last:pb-0 [&:not(:first-child)]:pt-6">
      <div className="mb-4">
        <h3 id={id} className="heading-small">{title}</h3>
        {hint ? <p className="text-small mt-1 text-text-subtle">{hint}</p> : null}
      </div>
      {children}
    </section>
  )
}
