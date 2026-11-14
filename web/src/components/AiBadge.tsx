import { useI18n } from '../i18n'
import { Icon } from './icons'

/** Marks a record that was created through the assistant (ADR-0023); renders nothing otherwise. */
export function AiBadge({ via }: { via?: string | null }) {
  const { t } = useI18n()
  if (via !== 'assistant') return null
  return (
    <span
      className="text-small ms-2 inline-flex items-center gap-1 rounded-6 border border-border-selected bg-surface-selected px-1.5 py-0.5 font-medium text-text-default"
      title={t('assistantCreatedBy')}
    >
      <Icon name="sparkles" className="h-3 w-3 text-brand-1-accent" />
      <span aria-hidden="true">AI</span>
      <span className="sr-only">{t('assistantCreatedBy')}</span>
    </span>
  )
}
