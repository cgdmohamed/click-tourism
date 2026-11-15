import { describe, expect, it } from 'vitest'
import { isPublicPath } from './api'

describe('isPublicPath', () => {
  it('lets a stale session stay on the signed-out screens', () => {
    for (const path of ['/login', '/forgot-password', '/reset-password']) expect(isPublicPath(path)).toBe(true)
  })

  it('does not exempt the app itself or look-alike paths', () => {
    for (const path of ['/', '/invoices', '/loginx', '/reset-passwords', '/settings/reset-password']) expect(isPublicPath(path)).toBe(false)
  })
})
