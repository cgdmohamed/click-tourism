import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { useFeatureFlags } from '../lib/useSetting'
import { Badge, Button, Card, CardHeader, InlineNotice } from './ui'

/**
 * Settings > Assistant: switch for reading uploaded documents automatically (ADR-0021). Off by
 * default. It sends the document itself to the provider, so it needs the same accepted notice and
 * provider key as the assistant. Readings are proposals a person must review.
 */
export default function ExtractionSwitchCard() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const { flags } = useFeatureFlags()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const toggle = useMutation({
    mutationFn: async (enable: boolean) => {
      await api.put('/settings', { settings: [{ group: 'attachments', key: 'extraction_enabled', value: enable ? 'true' : 'false' }] })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['feature-flags'] })
    },
  })

  const on = flags?.attachments_extraction_enabled === true
  const ready = flags?.assistant_accepted === true && flags?.assistant_key_configured === true
  if (!can('settings.view')) return null

  return (
    <Card className="mt-6">
      <CardHeader
        title={L('قراءة المستندات تلقائياً', 'Automatic document reading')}
        subtitle={L('اختياري ومعطّل افتراضياً', 'Optional, off by default')}
        icon="sparkles"
        actions={<Badge tone={on ? 'positive' : 'neutral'}>{on ? L('مفعّل', 'On') : L('متوقف', 'Off')}</Badge>}
      />
      <div className="flex flex-col gap-3 px-5 py-5">
        <p className="text-medium text-text-subtle">
          {L(
            'عند التفعيل يمكن للمستخدمين طلب قراءة فاتورة مورد أو إيصال أو جواز سفر. يُرسَل المستند نفسه إلى مزوّد الذكاء الاصطناعي، والنتيجة اقتراح يراجعه المستخدم ويصحّحه قبل الاعتماد؛ لا تُنشأ فواتير ولا قيود تلقائياً.',
            'When on, users can ask for a supplier invoice, receipt or passport to be read. The document itself is sent to the AI provider, and the result is a proposal the user reviews and corrects before confirming; no invoice or posting is created automatically.',
          )}
        </p>
        {!ready ? (
          <InlineNotice tone="warning">
            {L('يتطلب قبول إشعار معالجة البيانات وحفظ مفتاح المزوّد في هذا التبويب أولاً.', 'Accept the data-processing notice and save the provider key in this tab first.')}
          </InlineNotice>
        ) : null}
        {toggle.isError ? <InlineNotice tone="negative">{errorMessage(toggle.error)}</InlineNotice> : null}
        {can('settings.update') ? (
          <div>
            <Button variant="secondary" disabled={toggle.isPending || (!on && !ready)} onClick={() => toggle.mutate(!on)}>
              {on ? L('إيقاف القراءة التلقائية', 'Turn automatic reading off') : L('تفعيل القراءة التلقائية', 'Turn automatic reading on')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}
