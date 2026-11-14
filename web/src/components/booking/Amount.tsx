import { useLayoutEffect, useRef } from 'react'
import { useI18n } from '../../i18n'
import { formatMoney } from '../../lib/format'
import { cx } from '../ui'

type Size = 'lg' | 'md'

const BASE_PX: Record<Size, number> = { lg: 18, md: 14 }
const MIN_PX = 9

/**
 * A money value that never wraps and never overflows its cell: one bidi-isolated left-to-right run
 * with tabular digits, so the minus sign and the currency symbol cannot jump sides inside Arabic
 * text. The font steps down until the whole value fits (15+ digit amounts); the full value is also
 * in the tooltip.
 */
export function Amount({
  minor,
  currency,
  size = 'md',
  align = 'start',
  className,
}: {
  minor: number
  currency: string
  size?: Size
  /** `start` follows the reading direction (right in Arabic), `end` is the opposite edge. */
  align?: 'start' | 'end'
  className?: string
}) {
  const { locale } = useI18n()
  // Locale formatting adds direction marks; the isolated LTR run below makes them unnecessary.
  const text = formatMoney(minor, currency, locale).replace(/[‎‏؜]/g, '')
  const ref = useRef<HTMLSpanElement>(null)

  useLayoutEffect(() => {
    const node = ref.current
    if (!node) return
    const fit = () => {
      let px = BASE_PX[size]
      node.style.fontSize = `${px}px`
      while (node.scrollWidth > node.clientWidth + 0.5 && px > MIN_PX) {
        px -= 1
        node.style.fontSize = `${px}px`
      }
    }
    fit()
    if (typeof ResizeObserver === 'undefined' || !node.parentElement) return
    const observer = new ResizeObserver(fit)
    observer.observe(node.parentElement)
    return () => observer.disconnect()
  }, [text, size])

  return (
    <span
      ref={ref}
      dir="ltr"
      title={text}
      className={cx(
        'tabular block min-w-0 max-w-full overflow-hidden whitespace-nowrap font-semibold [unicode-bidi:isolate]',
        (align === 'start') === (locale === 'ar') ? 'text-right' : 'text-left',
        className,
      )}
    >
      {text}
    </span>
  )
}
