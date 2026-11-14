// The i18n entry point. The messages, the context/hook and the provider component live in separate
// files so each file exports only components or only plain values (fast refresh keeps working).
export type { Locale, MessageKey } from './messages'
export { useI18n } from './context'
export type { I18nValue } from './context'
export { I18nProvider } from './I18nProvider'
