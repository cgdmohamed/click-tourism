import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { draftFromStatus, formBranchMapError, missingSecrets, settingsPatch } from '../lib/integrations'
import type { IntegrationDraft, IntegrationStatus, SecretsConfigured } from '../lib/integrations'
import type { Branch } from '../lib/types'
import { Badge, Button, Card, CardHeader, ErrorState, Field, Input, Loading, Select } from './ui'

const secretLabels: Record<keyof SecretsConfigured, { ar: string; en: string }> = {
  app_secret: { ar: 'سر التطبيق', en: 'App secret' },
  verify_token: { ar: 'رمز التحقق', en: 'Verify token' },
  access_token: { ar: 'رمز الوصول', en: 'Access token' },
}

/**
 * Optional Meta Lead Ads / WhatsApp Cloud API integrations. Secrets are never
 * entered or shown here: they live in the server environment and this screen
 * only reports whether each one is configured.
 */
export default function IntegrationsPanel() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const canEdit = can('settings.update')
  const [draft, setDraft] = useState<IntegrationDraft | null>(null)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [copied, setCopied] = useState('')

  const statusQuery = useQuery({
    queryKey: ['integrations-status'],
    queryFn: async () => {
      const { data } = await api.get<{ data: IntegrationStatus }>('/integrations/status')
      return data.data
    },
  })

  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Branch[] }>('/branches')
      return data.data
    },
    enabled: can('branches.view'),
  })

  const current = useMemo(() => (statusQuery.data ? draftFromStatus(statusQuery.data) : null), [statusQuery.data])

  useEffect(() => {
    if (current) setDraft(current)
  }, [current])

  const mapError = draft ? formBranchMapError(draft.meta_form_branch_map) : null

  const save = useMutation({
    mutationFn: async () => {
      if (!draft || !current) return null
      const patch = settingsPatch(draft, current)
      if (Object.keys(patch).length === 0) return null
      const { data } = await api.put<{ data: IntegrationStatus }>('/integrations/settings', patch)
      return data.data
    },
    onSuccess: async () => {
      setSaved(true)
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['integrations-status'] })
      window.setTimeout(() => setSaved(false), 2500)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  async function copy(text: string, id: string) {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(id)
      window.setTimeout(() => setCopied(''), 2000)
    } catch {
      setCopied('')
    }
  }

  const set = <K extends keyof IntegrationDraft>(key: K, value: IntegrationDraft[K]) =>
    setDraft((previous) => (previous ? { ...previous, [key]: value } : previous))

  function Toggle({ field, label }: { field: 'meta_leads_enabled' | 'whatsapp_enabled' | 'whatsapp_outbound_enabled'; label: string }) {
    if (!draft) return null
    return (
      <Field label={label}>
        <label className="flex items-center gap-2 py-2 text-sm">
          <input type="checkbox" checked={draft[field]} disabled={!canEdit} onChange={(event) => set(field, event.target.checked)} />
          {draft[field] ? L('مفعّل', 'Enabled') : L('معطّل', 'Disabled')}
        </label>
      </Field>
    )
  }

  function SecretBadges({ configured, needsAccessToken }: { configured: SecretsConfigured; needsAccessToken: boolean }) {
    const missing = missingSecrets(configured, needsAccessToken)
    return (
      <div className="space-y-2">
        <div className="flex flex-wrap gap-2">
          {(Object.keys(secretLabels) as Array<keyof SecretsConfigured>).map((key) => (
            <Badge key={key} tone={configured[key] ? 'positive' : 'warning'}>
              {locale === 'ar' ? secretLabels[key].ar : secretLabels[key].en}: {configured[key] ? L('مضبوط', 'configured') : L('غير مضبوط', 'not configured')}
            </Badge>
          ))}
        </div>
        <p className="text-small text-text-subtlest">
          {L(
            'تُضبط الأسرار عبر متغيرات البيئة على الخادم فقط ولا تُعرض أو تُخزَّن هنا.',
            'Secrets are set only through server environment variables; they are never entered, stored or shown here.',
          )}
          {missing.length > 0 ? ` ${L('ناقص:', 'Missing:')} ${missing.map((key) => (locale === 'ar' ? secretLabels[key].ar : secretLabels[key].en)).join(', ')}.` : ''}
        </p>
      </div>
    )
  }

  function WebhookUrl({ id, url }: { id: string; url: string }) {
    return (
      <Field label={L('رابط الـ Webhook', 'Webhook URL')} hint={L('الصقه في إعدادات تطبيق Meta.', 'Paste into the Meta app webhook settings.')}>
        <div className="flex gap-2">
          <Input value={url} readOnly dir="ltr" onFocus={(event) => event.currentTarget.select()} />
          <Button variant="secondary" type="button" onClick={() => void copy(url, id)}>
            {copied === id ? L('تم النسخ', 'Copied') : L('نسخ', 'Copy')}
          </Button>
        </div>
      </Field>
    )
  }

  function Verification({ verifiedAt, lastEventAt, failed }: { verifiedAt: string | null; lastEventAt: string | null; failed: number }) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={verifiedAt ? 'positive' : 'neutral'}>
          {verifiedAt ? `${L('تم التحقق', 'Webhook verified')} ${formatDateTime(verifiedAt, locale)}` : L('لم يتم التحقق من الـ Webhook بعد', 'Webhook not verified yet')}
        </Badge>
        <Badge tone="neutral">
          {L('آخر حدث:', 'Last event:')} {lastEventAt ? formatDateTime(lastEventAt, locale) : '—'}
        </Badge>
        {failed > 0 ? <Badge tone="negative">{L('أحداث فاشلة:', 'Failed events:')} {failed}</Badge> : null}
      </div>
    )
  }

  const status = statusQuery.data

  return (
    <Card className="mt-6">
      <CardHeader
        title={L('التكاملات (Meta و WhatsApp)', 'Integrations (Meta and WhatsApp)')}
        subtitle={L(
          'اختيارية ومعطّلة افتراضياً. تتطلب مراجعة منصة Meta ورسوماً وحدود معدل خارجية.',
          'Optional and off by default. Meta platform review, messaging fees and rate limits are external dependencies.',
        )}
        actions={
          <Button icon="save" onClick={() => save.mutate()} disabled={!canEdit || save.isPending || Boolean(mapError)}>
            {save.isPending ? L('جارٍ الحفظ', 'Saving') : saved ? L('تم الحفظ', 'Saved') : L('حفظ', 'Save')}
          </Button>
        }
      />
      {statusQuery.isLoading ? <Loading /> : null}
      {statusQuery.isError ? <ErrorState message={errorMessage(statusQuery.error)} onRetry={() => void statusQuery.refetch()} /> : null}
      {error ? <p className="mx-5 mb-2 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}

      {status && draft ? (
        <div className="grid gap-8 px-5 py-5 xl:grid-cols-2">
          <section className="space-y-4" aria-label="Meta Lead Ads">
            <h3 className="label">{L('إعلانات العملاء المحتملين من Meta', 'Meta Lead Ads')}</h3>
            <Toggle field="meta_leads_enabled" label={L('تفعيل', 'Enable')} />
            <WebhookUrl id="meta" url={status.meta.webhook_url} />
            <Verification verifiedAt={status.meta.webhook_verified_at} lastEventAt={status.meta.last_event_at} failed={status.meta.failed_events} />
            <SecretBadges configured={status.meta.configured} needsAccessToken />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={L('معرّف الصفحة', 'Page ID')}>
                <Input value={draft.meta_page_id} disabled={!canEdit} dir="ltr" inputMode="numeric" onChange={(event) => set('meta_page_id', event.target.value)} />
              </Field>
              <Field label={L('إصدار Graph API', 'Graph API version')} hint={L('غير مؤكد: راجع الإصدار الحالي المدعوم.', 'UNVERIFIED: confirm the currently supported version.')}>
                <Input value={draft.meta_graph_version} disabled={!canEdit} dir="ltr" onChange={(event) => set('meta_graph_version', event.target.value)} />
              </Field>
              <Field label={L('الفرع الافتراضي', 'Default branch')}>
                <Select value={draft.meta_default_branch_id} disabled={!canEdit} onChange={(event) => set('meta_default_branch_id', event.target.value)}>
                  <option value="">{L('أول فرع نشط', 'First active branch')}</option>
                  {(branchesQuery.data ?? []).filter((branch) => branch.is_active).map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.code} — {locale === 'ar' ? branch.name_ar : branch.name_en}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field
                label={L('ربط النماذج بالفروع (JSON)', 'Form to branch map (JSON)')}
                error={mapError ? L('JSON غير صالح: {"معرّف النموذج":"معرّف الفرع"}', 'Invalid JSON: {"formId":"branchId"}') : undefined}
                hint={L('اختياري. التوزيع على الموظفين يتم بقواعد التوزيع.', 'Optional. Owner assignment still follows the assignment rules.')}
              >
                <Input value={draft.meta_form_branch_map} disabled={!canEdit} dir="ltr" onChange={(event) => set('meta_form_branch_map', event.target.value)} />
              </Field>
            </div>
          </section>

          <section className="space-y-4" aria-label="WhatsApp Cloud API">
            <h3 className="label">{L('واتساب Cloud API', 'WhatsApp Cloud API')}</h3>
            <Toggle field="whatsapp_enabled" label={L('تفعيل (استقبال وسجل الرسائل)', 'Enable (inbound and message log)')} />
            <Toggle field="whatsapp_outbound_enabled" label={L('تفعيل الإرسال بواسطة الموظفين', 'Enable staff-triggered sending')} />
            <WebhookUrl id="whatsapp" url={status.whatsapp.webhook_url} />
            <Verification verifiedAt={status.whatsapp.webhook_verified_at} lastEventAt={status.whatsapp.last_event_at} failed={status.whatsapp.failed_events} />
            <SecretBadges configured={status.whatsapp.configured} needsAccessToken />
            <div className="flex flex-wrap gap-2">
              <Badge tone="neutral">
                {L('رسائل في الانتظار:', 'Queued messages:')} {status.whatsapp.queued_messages}
              </Badge>
              {status.whatsapp.failed_messages > 0 ? (
                <Badge tone="negative">
                  {L('رسائل فاشلة:', 'Failed messages:')} {status.whatsapp.failed_messages}
                </Badge>
              ) : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={L('معرّف رقم الهاتف', 'Phone number ID')}>
                <Input value={draft.whatsapp_phone_number_id} disabled={!canEdit} dir="ltr" inputMode="numeric" onChange={(event) => set('whatsapp_phone_number_id', event.target.value)} />
              </Field>
              <Field label={L('معرّف حساب الأعمال', 'Business account ID')}>
                <Input value={draft.whatsapp_business_account_id} disabled={!canEdit} dir="ltr" inputMode="numeric" onChange={(event) => set('whatsapp_business_account_id', event.target.value)} />
              </Field>
              <Field label={L('إصدار Graph API', 'Graph API version')}>
                <Input value={draft.whatsapp_graph_version} disabled={!canEdit} dir="ltr" onChange={(event) => set('whatsapp_graph_version', event.target.value)} />
              </Field>
              <Field label={L('كلمات إلغاء الاشتراك', 'Opt-out keywords')} hint={L('مفصولة بفواصل.', 'Comma separated.')}>
                <Input value={draft.whatsapp_stop_keywords} disabled={!canEdit} onChange={(event) => set('whatsapp_stop_keywords', event.target.value)} />
              </Field>
            </div>
            <p className="text-small text-text-subtlest">
              {L(
                'لا يوجد صندوق محادثات ولا رد تلقائي. الإرسال يتطلب موافقة (opt-in) مسجلة وقالباً معتمداً خارج نافذة 24 ساعة.',
                'No chat inbox and no auto-replies. Sending requires a recorded opt-in, and an approved template outside the 24-hour window.',
              )}
            </p>
          </section>
        </div>
      ) : null}
    </Card>
  )
}
