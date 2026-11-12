import type { AxiosResponse } from 'axios'

/**
 * Filename a server suggested through `Content-Disposition`.
 *
 * Supports the plain `filename=` form and the RFC 5987 `filename*=UTF-8''`
 * form, and never throws on malformed headers: the caller's fallback wins.
 */
export function filenameFromDisposition(disposition: unknown, fallback: string): string {
  const value = String(disposition ?? '')

  const extended = /filename\*=UTF-8''([^;]+)/i.exec(value)?.[1]?.trim()
  if (extended) {
    try {
      return decodeURIComponent(extended)
    } catch {
      return extended
    }
  }

  const raw = /filename="?([^";]+)"?/i.exec(value)?.[1]?.trim()

  return raw ? raw : fallback
}

/** Saves an already-fetched binary body under the given filename. */
export function downloadBlob(data: Blob, filename: string): void {
  const href = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(href)
}

/** Saves a binary API response, preferring the server-provided filename. */
export function downloadResponse(response: AxiosResponse<Blob>, fallback: string): void {
  downloadBlob(
    response.data,
    filenameFromDisposition(response.headers['content-disposition'], fallback),
  )
}
