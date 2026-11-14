import { useI18n } from '../i18n'

/**
 * Header button that opens the assistant: a sparkle icon that twinkles and glows softly.
 * The motion is CSS only (see index.css, "assistant orb") and stops under reduced motion.
 */
export function AssistantOrb({ onClick }: { onClick: () => void }) {
  const { t } = useI18n()
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={t('assistantOpen')}
      title={t('assistantOpen')}
      className="assistant-orb no-print relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-action-primary-background text-action-primary-text transition hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2"
    >
      <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
        <path className="orb-star orb-star-big" d="M11 3l1.9 5.3L18 10.2l-5.1 1.9L11 17.4l-1.9-5.3L4 10.2l5.1-1.9L11 3Z" />
        <path className="orb-star orb-star-small" d="M19 14.5l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9.9-2.4Z" />
      </svg>
    </button>
  )
}
