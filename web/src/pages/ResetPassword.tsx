import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import axios from 'axios'
import { useI18n } from '../i18n'
import { Button, InlineNotice } from '../components/ui'
import { Icon } from '../components/icons'
import { AuthShell, PasswordField } from '../components/AuthShell'
import { api, errorMessage } from '../lib/api'

/** Sets a new password from the e-mailed link (the token and e-mail are in the address). */
export default function ResetPassword() {
  const { t } = useI18n()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState('')
  const [invalid, setInvalid] = useState(token === '' || email === '')
  const [done, setDone] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError('')
    if (password.length < 12) return setError(t('resetTooShort'))
    if (password !== confirm) return setError(t('resetMismatch'))
    setSubmitting(true)
    try {
      await api.post('/auth/reset-password', { email, token, password, password_confirmation: confirm })
      setDone(true)
    } catch (cause) {
      if (axios.isAxiosError<{ code?: string }>(cause) && cause.response?.data?.code === 'invalid_reset_link') setInvalid(true)
      else setError(errorMessage(cause))
    } finally {
      setSubmitting(false)
    }
  }

  if (done) {
    return (
      <AuthShell title={t('resetDoneTitle')}>
        <div className="flex flex-col gap-4">
          <p className="text-medium flex items-start gap-3 text-text-default" role="status">
            <Icon name="checkCircle" className="mt-0.5 h-5 w-5 shrink-0 text-success-text" />
            {t('resetDoneBody')}
          </p>
          <Link to="/login">
            <Button type="button" icon="logIn" className="w-full">
              {t('signIn')}
            </Button>
          </Link>
        </div>
      </AuthShell>
    )
  }

  if (invalid) {
    return (
      <AuthShell title={t('resetTitle')}>
        <div className="flex flex-col gap-4">
          <InlineNotice tone="negative">{t('resetInvalid')}</InlineNotice>
          <Link to="/forgot-password">
            <Button type="button" variant="secondary" className="w-full">
              {t('resetRequestNew')}
            </Button>
          </Link>
          <p className="text-small text-center">
            <Link to="/login" className="text-brand-1-accent underline">
              {t('backToLogin')}
            </Link>
          </p>
        </div>
      </AuthShell>
    )
  }

  return (
    <AuthShell title={t('resetTitle')} subtitle={t('resetSubtitle')}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <PasswordField label={t('newPassword')} value={password} onChange={setPassword} autoComplete="new-password" />
        <PasswordField label={t('confirmPassword')} value={confirm} onChange={setConfirm} autoComplete="new-password" />
        {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
        <Button type="submit" icon="check" disabled={submitting} className="w-full">
          {submitting ? t('resetSaving') : t('resetSave')}
        </Button>
      </form>
    </AuthShell>
  )
}
