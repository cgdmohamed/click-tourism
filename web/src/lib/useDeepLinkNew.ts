import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * Runs `open` once when the page is reached with `?new=<value>` (a dashboard shortcut or a profile button),
 * then removes the parameter so a refresh or Back does not reopen the dialog. `allowed` must come from the
 * user's permission; without it the link just shows the list.
 */
export function useDeepLinkNew(allowed: boolean, open: (value: string, params: URLSearchParams) => void) {
  const [params, setParams] = useSearchParams()
  const value = params.get('new')
  useEffect(() => {
    if (!value) return
    if (allowed) open(value, params)
    const next = new URLSearchParams(params)
    ;['new', 'type', 'party_type', 'party_id', 'supplier_id', 'customer_id', 'party_name'].forEach((key) => next.delete(key))
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, allowed])
}
