import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { Badge, Button, Card, CardHeader, Field, InlineNotice, Input, Select } from '../components/ui'
import { DetailGrid, DetailItem, MetaItem, ProfileHeader } from '../components/profile'

function initialsOf(name: string) {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('') || '?'
  )
}

export default function Profile() {
  const { user, refresh, activeBranch } = useAuth()
  const { t, locale, setLocale } = useI18n()

  const [localeDraft, setLocaleDraft] = useState<'ar' | 'en'>(user?.locale ?? 'ar')
  const [localeError, setLocaleError] = useState('')
  const [localeSaved, setLocaleSaved] = useState(false)

  const [passwordForm, setPasswordForm] = useState({ current: '', next: '', confirm: '' })
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({})
  const [passwordError, setPasswordError] = useState('')
  const [passwordSaved, setPasswordSaved] = useState(false)

  const saveLocale = useMutation({
    mutationFn: async () => {
      await api.patch('/auth/me', { locale: localeDraft })
    },
    onSuccess: async () => {
      setLocale(localeDraft)
      await refresh()
      setLocaleError('')
      setLocaleSaved(true)
      window.setTimeout(() => setLocaleSaved(false), 2500)
    },
    onError: (cause) => setLocaleError(errorMessage(cause)),
  })

  const changePassword = useMutation({
    mutationFn: async () => {
      await api.patch('/auth/me', {
        current_password: passwordForm.current,
        password: passwordForm.next,
      })
    },
    onSuccess: () => {
      setPasswordForm({ current: '', next: '', confirm: '' })
      setPasswordErrors({})
      setPasswordError('')
      setPasswordSaved(true)
      window.setTimeout(() => setPasswordSaved(false), 2500)
    },
    onError: (cause) => setPasswordError(errorMessage(cause)),
  })

  if (!user) return null

  const branchName = (branch: { name_ar: string; name_en: string }) =>
    locale === 'ar' ? branch.name_ar : branch.name_en

  const submitPassword = () => {
    const errors: Record<string, string> = {}
    if (!passwordForm.current) errors.current = t('currentPasswordRequired')
    if (passwordForm.next.length < 12) errors.next = t('validationPasswordShort')
    if (passwordForm.confirm !== passwordForm.next) errors.confirm = t('passwordMismatch')
    setPasswordErrors(errors)
    setPasswordError('')
    if (Object.keys(errors).length > 0) return
    changePassword.mutate()
  }

  return (
    <div>
      <ProfileHeader
        title={user.name}
        subtitle={t('profileSubtitle')}
        icon="user"
        initials={initialsOf(user.name)}
        backTo="/"
        backLabel={t('dashboard')}
        badge={
          <span className="flex flex-wrap gap-1">
            {user.roles.map((role) => (
              <Badge key={role} tone="neutral">
                {role}
              </Badge>
            ))}
          </span>
        }
        meta={
          <>
            <MetaItem icon="mail" dir="ltr">
              {user.email}
            </MetaItem>
            <MetaItem icon="building">{user.branches.map(branchName).join('، ')}</MetaItem>
          </>
        }
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title={t('profile')} icon="user" />
          <DetailGrid>
            <DetailItem label={t('email')} dir="ltr">
              {user.email}
            </DetailItem>
            <DetailItem label={t('language')}>{user.locale === 'ar' ? t('arabic') : t('english')}</DetailItem>
            <DetailItem label={t('roles')}>
              <span className="flex flex-wrap gap-1">
                {user.roles.length === 0 ? (
                  <span className="text-text-subtlest">—</span>
                ) : (
                  user.roles.map((role) => (
                    <Badge key={role} tone="neutral">
                      {role}
                    </Badge>
                  ))
                )}
              </span>
            </DetailItem>
            <DetailItem label={t('branches')}>
              <span className="flex flex-wrap gap-1">
                {user.branches.length === 0 ? (
                  <span className="text-text-subtlest">—</span>
                ) : (
                  user.branches.map((branch) => (
                    <span key={branch.id} title={branch.id === activeBranch?.id ? t('branch') : undefined}>
                      <Badge tone={branch.id === activeBranch?.id ? 'brand' : 'neutral'}>{branchName(branch)}</Badge>
                    </span>
                  ))
                )}
              </span>
            </DetailItem>
          </DetailGrid>
        </Card>

        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader title={t('preferences')} icon="globe" />
            <div className="flex flex-col gap-4 px-5 py-5">
              <Field label={t('language')}>
                <Select
                  value={localeDraft}
                  onChange={(event) => setLocaleDraft(event.target.value as 'ar' | 'en')}
                >
                  <option value="ar">{t('arabic')}</option>
                  <option value="en">{t('english')}</option>
                </Select>
              </Field>
              <Button icon="save" disabled={saveLocale.isPending} onClick={() => saveLocale.mutate()}>
                {saveLocale.isPending ? t('saving') : t('save')}
              </Button>
              {localeSaved ? <InlineNotice tone="positive">{t('localeUpdated')}</InlineNotice> : null}
              {localeError ? <InlineNotice tone="negative">{localeError}</InlineNotice> : null}
            </div>
          </Card>

          <Card>
            <CardHeader title={t('changePassword')} icon="lock" />
            <div className="flex flex-col gap-4 px-5 py-5">
              <Field label={t('currentPassword')} required error={passwordErrors.current || undefined}>
                <Input
                  type="password"
                  value={passwordForm.current}
                  onChange={(event) => setPasswordForm({ ...passwordForm, current: event.target.value })}
                  dir="ltr"
                  autoComplete="current-password"
                />
              </Field>
              <Field label={t('newPassword')} required error={passwordErrors.next || undefined} hint={t('passwordHint')}>
                <Input
                  type="password"
                  value={passwordForm.next}
                  onChange={(event) => setPasswordForm({ ...passwordForm, next: event.target.value })}
                  dir="ltr"
                  autoComplete="new-password"
                />
              </Field>
              <Field label={t('confirmPassword')} required error={passwordErrors.confirm || undefined}>
                <Input
                  type="password"
                  value={passwordForm.confirm}
                  onChange={(event) => setPasswordForm({ ...passwordForm, confirm: event.target.value })}
                  dir="ltr"
                  autoComplete="new-password"
                />
              </Field>
              <Button icon="lock" disabled={changePassword.isPending} onClick={submitPassword}>
                {changePassword.isPending ? t('saving') : t('save')}
              </Button>
              {passwordSaved ? <InlineNotice tone="positive">{t('passwordChanged')}</InlineNotice> : null}
              {passwordError ? <InlineNotice tone="negative">{passwordError}</InlineNotice> : null}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
