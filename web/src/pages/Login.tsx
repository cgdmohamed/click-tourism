import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { Button, Field, IconButton, InlineNotice, Input } from '../components/ui'
import { Icon } from '../components/icons'
import { errorMessage } from '../lib/api'

export default function Login() {
  const { user, loading, login } = useAuth()
  const { t, locale, toggleLocale } = useI18n()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  if (!loading && user) return <Navigate to="/" replace />

  const onSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await login(email, password)
      const from = (location.state as { from?: string } | null)?.from ?? '/'
      navigate(from, { replace: true })
    } catch (cause) {
      setError(errorMessage(cause, locale === 'ar' ? 'تعذر تسجيل الدخول' : 'Unable to sign in'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-canvas px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-16 bg-brand-1-background text-brand-1-text shadow-2">
              <Icon name="compass" size={26} />
            </span>
            <div>
              <h1 className="heading-large text-brand-1-accent">{t('appName')}</h1>
              <p className="text-medium mt-1 text-text-subtle">{t('appTagline')}</p>
            </div>
          </div>
          <button
            className="text-small inline-flex h-9 items-center gap-2 rounded-12 border border-border-subtle bg-surface-default px-2.5 font-medium text-text-subtle transition hover:bg-surface-hovered"
            onClick={toggleLocale}
            aria-label={t('language')}
          >
            <Icon name="globe" className="h-4 w-4" />
            {locale === 'ar' ? 'EN' : 'عربي'}
          </button>
        </div>

        <form onSubmit={onSubmit} className="rounded-16 border border-border-subtle bg-surface-default p-6 shadow-2">
          <h2 className="heading-medium text-text-default">{t('login')}</h2>
          <p className="text-medium mt-1 text-text-subtle">{t('appTagline')}</p>

          <div className="mt-6 flex flex-col gap-4">
            <Field label={t('email')} required>
              <Input
                type="email"
                icon="mail"
                autoComplete="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="admin@example.com"
                dir="ltr"
              />
            </Field>
            <Field label={t('password')} required>
              <div className="relative">
                <Input
                  type={showPassword ? 'text' : 'password'}
                  icon="lock"
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="••••••••"
                  dir="ltr"
                  className="[&>input]:pe-10"
                />
                <IconButton
                  type="button"
                  icon={showPassword ? 'eyeOff' : 'eye'}
                  label={showPassword ? t('hidePassword') : t('showPassword')}
                  className="absolute end-1 top-1/2 -translate-y-1/2"
                  onClick={() => setShowPassword((current) => !current)}
                />
              </div>
            </Field>
            {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
            <Button type="submit" icon="logIn" disabled={submitting} className="w-full">
              {submitting ? t('signingIn') : t('signIn')}
            </Button>
          </div>
        </form>

        <p className="text-small mt-4 flex items-center justify-center gap-1.5 text-center text-text-subtle">
          <Icon name="info" className="h-3.5 w-3.5" />
          {locale === 'ar' ? 'نسخة تجريبية — بيانات الدخول من ملف التهيئة' : 'Demo build — credentials come from the seeder'}
        </p>
      </div>
    </div>
  )
}
