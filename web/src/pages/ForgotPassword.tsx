import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import axios from 'axios'
import { useI18n } from '../i18n'
import { Button, Field, InlineNotice, Input } from '../components/ui'
import { Icon } from '../components/icons'
import { AuthShell } from '../components/AuthShell'
import { api, errorMessage } from '../lib/api'

/** Asks for a reset link. The answer is the same for any e-mail, so the page never says whether an account exists. */
export default function ForgotPassword() {
  const { t } = useI18n()
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await api.post('/auth/forgot-password', { email })
      setSent(true)
    } catch (cause) {
      setError(axios.isAxiosError(cause) && cause.response?.status === 503 ? t('forgotUnavailable') : errorMessage(cause))
    } finally {
      setSubmitting(false)
    }
  }

  const back = (
    <Link to="/login" className="text-brand-1-accent underline">
      {t('backToLogin')}
    </Link>
  )

  if (sent) {
    return (
      <AuthShell title={t('forgotSentTitle')}>
        <div className="flex flex-col gap-4">
          <p className="text-medium flex items-start gap-3 text-text-default" role="status">
            <Icon name="checkCircle" className="mt-0.5 h-5 w-5 shrink-0 text-success-text" />
            {t('forgotSentBody')}
          </p>
          <p className="text-small">{back}</p>
        </div>
      </AuthShell>
    )
  }

  return (
    <AuthShell title={t('forgotTitle')} subtitle={t('forgotSubtitle')}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <Field label={t('email')} required>
          <Input type="email" icon="mail" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} placeholder="name@company.com" dir="ltr" />
        </Field>
        {error ? <InlineNotice tone="negative">{error}</InlineNotice> : null}
        <Button type="submit" icon="send" disabled={submitting} className="w-full">
          {submitting ? t('forgotSending') : t('forgotSend')}
        </Button>
        <p className="text-small text-center">{back}</p>
      </form>
    </AuthShell>
  )
}
