/** Pure helpers for the shared Tabs component and URL-backed tab state. */

/** The tab named by a `?tab=` value, or the fallback when it is missing/unknown/not allowed. */
export function resolveTab(value: string | null | undefined, allowed: readonly string[], fallback: string): string {
  return value && allowed.includes(value) ? value : fallback
}

/**
 * Index to focus after a key press in a tablist, or null when the key is not
 * a tab-navigation key. Arrow keys follow the visual direction: in RTL the
 * left arrow moves to the NEXT tab. Navigation wraps around.
 */
export function nextTabIndex(key: string, current: number, count: number, rtl: boolean): number | null {
  if (count <= 0) return null
  if (key === 'Home') return 0
  if (key === 'End') return count - 1
  const forward = rtl ? 'ArrowLeft' : 'ArrowRight'
  const backward = rtl ? 'ArrowRight' : 'ArrowLeft'
  if (key === forward) return (current + 1) % count
  if (key === backward) return (current - 1 + count) % count
  return null
}
