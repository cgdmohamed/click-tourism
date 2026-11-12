import { useEffect, useState } from 'react'
import { useI18n } from '../i18n'
import { brandName, logoSrc, useBranding } from '../lib/branding'
import { cx } from './ui'
import { Icon } from './icons'

/** Company name in the active locale (saved in Settings), else the built-in product name. */
export function useBrandName(): string {
  const { t, locale } = useI18n()
  return brandName(useBranding(), locale, t('appName'))
}

/** Sets the browser tab title to the saved company name. */
export function BrandTitle() {
  const name = useBrandName()
  useEffect(() => {
    document.title = name
  }, [name])
  return null
}

/** The uploaded logo when there is one, otherwise the default compass mark. */
export function BrandMark({ className, iconSize = 22 }: { className?: string; iconSize?: number }) {
  const branding = useBranding()
  const src = logoSrc(branding)
  const [failed, setFailed] = useState<string | null>(null)

  if (src && failed !== src) {
    return (
      <span className={cx('flex shrink-0 items-center justify-center overflow-hidden rounded-16 bg-surface-default', className)}>
        <img src={src} alt="" className="max-h-full max-w-full object-contain" onError={() => setFailed(src)} />
      </span>
    )
  }
  return (
    <span className={cx('flex shrink-0 items-center justify-center rounded-16 bg-brand-1-background text-brand-1-text shadow-2', className)}>
      <Icon name="compass" size={iconSize} />
    </span>
  )
}
