import { useQuery } from '@tanstack/react-query'
import { API_BASE, api } from './api'

export type Branding = {
  company_name_ar: string
  company_name_en: string
  has_logo: boolean
  logo_version: string | null
}

/** Company name in the active locale; falls back to the other language, then to the built-in name. */
export function brandName(branding: Pick<Branding, 'company_name_ar' | 'company_name_en'> | undefined, locale: 'ar' | 'en', fallback: string): string {
  const own = (locale === 'ar' ? branding?.company_name_ar : branding?.company_name_en)?.trim()
  const other = (locale === 'ar' ? branding?.company_name_en : branding?.company_name_ar)?.trim()
  return own || other || fallback
}

/** Public logo URL (cache-busted by the server-provided version), or null when no logo is set. */
export function logoSrc(branding: Pick<Branding, 'has_logo' | 'logo_version'> | undefined): string | null {
  if (!branding?.has_logo) return null
  return `${API_BASE}/branding/logo${branding.logo_version ? `?v=${encodeURIComponent(branding.logo_version)}` : ''}`
}

/** Public company branding (names + logo flag); works on the unauthenticated login page. */
export function useBranding(): Branding | undefined {
  const query = useQuery({
    queryKey: ['branding'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Branding }>('/branding')
      return data.data
    },
    staleTime: 5 * 60_000,
    retry: false,
  })
  return query.data
}

export const LOGO_MAX_BYTES = 1024 * 1024

/** Client-side pre-check for the logo upload; the server re-validates the real content. */
export function logoFileProblem(file: { type: string; size: number }): 'type' | 'size' | null {
  if (file.type !== 'image/png' && file.type !== 'image/jpeg') return 'type'
  if (file.size <= 0 || file.size > LOGO_MAX_BYTES) return 'size'
  return null
}
