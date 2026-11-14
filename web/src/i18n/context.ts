import { createContext, useContext } from 'react'
import type { Locale, MessageKey } from './messages'

export type I18nValue = {
  locale: Locale
  dir: 'rtl' | 'ltr'
  t: (key: MessageKey) => string
  setLocale: (locale: Locale) => void
  toggleLocale: () => void
}

export const I18nContext = createContext<I18nValue | null>(null)

export function useI18n(): I18nValue {
  const context = useContext(I18nContext)
  if (!context) throw new Error('useI18n must be used inside I18nProvider')
  return context
}
