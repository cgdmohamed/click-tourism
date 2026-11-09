import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { Button, Field, Input } from '../components/ui'
import { errorMessage } from '../lib/api'

export default function Login() {
  const { user, loading, login } = useAuth()
  const { t, locale, toggleLocale } = useI18n()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
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
    <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-brand-700">{t('appName')}</h1>
            <p className="mt-1 text-sm text-muted">{t('appTagline')}</p>
          </div>
          <button className="rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-medium text-muted" onClick={toggleLocale}>
            {locale === 'ar' ? 'EN' : 'عربي'}
          </button>
        </div>

        <form onSubmit={onSubmit} className="rounded-card border border-line bg-surface p-6 shadow-sm">
          <h2 className="text-lg font-semibold text-ink">{t('login')}</h2>
          <p className="mt-1 text-sm text-muted">{t('appTagline')}</p>

          <div className="mt-6 flex flex-col gap-4">
            <Field label={t('email')} required>
              <Input
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="admin@example.com"
                dir="ltr"
              />
            </Field>
            <Field label={t('password')} required>
              <Input
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                placeholder="••••••••"
                dir="ltr"
              />
            </Field>
            {error ? <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-negative">{error}</p> : null}
            <Button type="submit" disabled={submitting} className="w-full">
              {submitting ? t('signingIn') : t('signIn')}
            </Button>
          </div>
        </form>

        <p className="mt-4 text-center text-xs text-muted">
          {locale === 'ar' ? 'نسخة تجريبية — بيانات الدخول من ملف التهيئة' : 'Demo build — credentials come from the seeder'}
        </p>
      </div>
    </div>
  )
}
