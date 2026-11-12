export type Locale = 'ar' | 'en'

type Named = { name_ar?: string | null; name_en?: string | null }

/**
 * The name to show for the active locale only (ar -> name_ar, en -> name_en).
 * Falls back to the other language only when the active one is empty.
 */
export function localizedName(item: Named | null | undefined, locale: Locale, fallback = ''): string {
  if (!item) return fallback
  const primary = locale === 'ar' ? item.name_ar : item.name_en
  const secondary = locale === 'ar' ? item.name_en : item.name_ar
  return primary?.trim() || secondary?.trim() || fallback
}

/** "code — localized name" label for account/cost-center style options. */
export function codeLabel(item: Named & { code?: string | null }, locale: Locale): string {
  const name = localizedName(item, locale)
  return item.code ? (name ? `${item.code} — ${name}` : item.code) : name
}
