import { useState } from 'react'
import type { ReactNode } from 'react'
import { useI18n } from '../i18n'
import { useTheme } from '../theme'
import { Field, IconButton, Input } from './ui'
import { Icon } from './icons'
import { BrandMark, useBrandName } from './Brand'

/** The frame of every signed-out screen (sign in, forgot password, reset password): brand, language, theme and one card. */
export function AuthShell({ title, subtitle, children, footer }: { title: string; subtitle?: string; children: ReactNode; footer?: ReactNode }) {
  const { t, locale, toggleLocale } = useI18n()
  const { theme, toggleTheme } = useTheme()
  const brand = useBrandName()

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-canvas px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <BrandMark className="h-12 w-12" iconSize={26} />
            <div>
              <h1 className="heading-large text-brand-1-accent">{brand}</h1>
              <p className="text-medium mt-1 text-text-subtle">{t('appTagline')}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <IconButton
              icon={theme === 'dark' ? 'sun' : 'moon'}
              label={theme === 'dark' ? t('switchToLight') : t('switchToDark')}
              variant="secondary"
              onClick={toggleTheme}
            />
            <button
              type="button"
              className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-subtle bg-surface-default px-2.5 font-medium text-text-subtle transition hover:bg-surface-hovered"
              onClick={toggleLocale}
              aria-label={t('language')}
            >
              <Icon name="globe" className="h-4 w-4" />
              {locale === 'ar' ? 'EN' : 'عربي'}
            </button>
          </div>
        </div>

        <section aria-labelledby="auth-title" className="anim-rise rounded-16 border border-border-subtle bg-surface-default p-6 shadow-2">
          <h2 id="auth-title" className="heading-medium text-text-default">
            {title}
          </h2>
          {subtitle ? <p className="text-medium mt-1 text-text-subtle">{subtitle}</p> : null}
          <div className="mt-6">{children}</div>
        </section>

        {footer ? <div className="text-small mt-4 flex items-center justify-center gap-1.5 text-center text-text-subtle">{footer}</div> : null}
      </div>
    </div>
  )
}

/** A password field with a show/hide button. */
export function PasswordField({
  label,
  value,
  onChange,
  autoComplete,
  hint,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  autoComplete: 'current-password' | 'new-password'
  hint?: string
}) {
  const { t } = useI18n()
  const [show, setShow] = useState(false)

  return (
    <Field label={label} required hint={hint}>
      <div className="relative">
        <Input
          type={show ? 'text' : 'password'}
          icon="lock"
          autoComplete={autoComplete}
          required
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder="••••••••"
          dir="ltr"
          className="[&>input]:pe-10"
        />
        <IconButton
          type="button"
          icon={show ? 'eyeOff' : 'eye'}
          label={show ? t('hidePassword') : t('showPassword')}
          className="absolute end-1 top-1/2 -translate-y-1/2"
          onClick={() => setShow((current) => !current)}
        />
      </div>
    </Field>
  )
}
