import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { Button, Field, InlineNotice, Input } from '../components/ui'
import { Icon } from '../components/icons'
import { AuthShell, PasswordField } from '../components/AuthShell'
import { api, errorMessage } from '../lib/api'
import { useBranding } from '../lib/branding'

export default function Login() {
  const { user, loading, login } = useAuth()
  const { t, locale } = useI18n()
  const branding = useBranding()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  // Same query as the demo banner, so no extra request; the note shows only on the public demo.
  const demo = useQuery({
    queryKey: ['public-demo-status'],
    queryFn: async () => (await api.get<{ data: { enabled: boolean } }>('/demo/status')).data.data,
    retry: 0,
    staleTime: 0,
  })

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
    <AuthShell
      title={t('login')}
      subtitle={t('loginSubtitle')}
      footer={
        demo.data?.enabled ? (
          <>
            <Icon name="info" className="h-3.5 w-3.5" />
            {locale === 'ar' ? 'نسخة تجريبية — الحسابات التجريبية من ملف التهيئة' : 'Demo build — the demo accounts come from the seeder'}
          </>
        ) : null
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <Field label={t('email')} required>
          <Input
            type="email"
            icon="mail"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            placeholder="name@company.com"
            dir="ltr"
          />
        </Field>
        <PasswordField label={t('password')} value={password} onChange={setPassword} autoComplete="current-password" />
        <p className="text-small -mt-2">
          {branding?.password_reset_available ? (
            <Link to="/forgot-password" className="text-brand-1-accent underline">
              {t('forgotPasswordLink')}
            </Link>
          ) : (
            <span className="text-text-subtle">{t('forgotPasswordAdmin')}</span>
          )}
        </p>
        {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
        <Button type="submit" icon="logIn" disabled={submitting} className="w-full">
          {submitting ? t('signingIn') : t('signIn')}
        </Button>
      </form>
    </AuthShell>
  )
}
