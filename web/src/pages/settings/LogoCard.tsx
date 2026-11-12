import { useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../auth/AuthProvider'
import { useI18n } from '../../i18n'
import { api, errorMessage } from '../../lib/api'
import { logoFileProblem, logoSrc, useBranding, type Branding } from '../../lib/branding'
import { Button, Card, CardHeader, InlineNotice } from '../../components/ui'

/** Report/letterhead logo: preview, upload (PNG/JPEG, max 1 MB) and remove. No path is ever shown or typed. */
export function LogoCard() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const branding = useBranding()
  const input = useRef<HTMLInputElement>(null)
  const [error, setError] = useState('')
  const [broken, setBroken] = useState<string | null>(null)
  const ar = locale === 'ar'
  const canEdit = can('settings.update')
  const src = logoSrc(branding)

  const refreshBranding = async (next: Branding) => {
    queryClient.setQueryData(['branding'], next)
    await queryClient.invalidateQueries({ queryKey: ['branding'] })
  }

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('logo', file)
      const { data } = await api.post<{ data: Branding }>('/settings/logo', form)
      return data.data
    },
    onSuccess: async (next) => {
      setError('')
      await refreshBranding(next)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const remove = useMutation({
    mutationFn: async () => {
      const { data } = await api.delete<{ data: Branding }>('/settings/logo')
      return data.data
    },
    onSuccess: async (next) => {
      setError('')
      await refreshBranding(next)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const onPick = (file: File | undefined) => {
    if (input.current) input.current.value = ''
    if (!file) return
    const problem = logoFileProblem(file)
    if (problem === 'type') {
      setError(ar ? 'يجب أن يكون الشعار صورة PNG أو JPEG فقط.' : 'The logo must be a PNG or JPEG image.')
      return
    }
    if (problem === 'size') {
      setError(ar ? 'يجب ألا يتجاوز حجم الشعار 1 ميجابايت.' : 'The logo must not be larger than 1 MB.')
      return
    }
    setError('')
    upload.mutate(file)
  }

  const busy = upload.isPending || remove.isPending

  return (
    <Card className="mt-6">
      <CardHeader
        title={ar ? 'شعار التقارير' : 'Report logo'}
        subtitle={ar ? 'يظهر في الترويسة وفي تقارير وفواتير PDF. PNG أو JPEG حتى 1 ميجابايت.' : 'Shown in the app header and on PDF reports and invoices. PNG or JPEG, up to 1 MB.'}
      />
      <div className="flex flex-wrap items-center gap-5 px-5 py-5">
        <div
          className="flex h-24 w-40 items-center justify-center overflow-hidden rounded-12 border border-border-subtle bg-surface-subtle"
          data-testid="logo-preview"
        >
          {src && broken !== src ? (
            <img src={src} alt={ar ? 'معاينة الشعار' : 'Logo preview'} className="max-h-full max-w-full object-contain" onError={() => setBroken(src)} />
          ) : (
            <span className="text-small px-2 text-center text-text-subtlest">{ar ? 'لا يوجد شعار' : 'No logo'}</span>
          )}
        </div>
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap gap-2">
            <input
              ref={input}
              type="file"
              accept="image/png,image/jpeg"
              hidden
              aria-label={ar ? 'ملف الشعار' : 'Logo file'}
              onChange={(event) => onPick(event.target.files?.[0])}
            />
            <Button icon="upload" variant="secondary" disabled={!canEdit || busy} onClick={() => input.current?.click()}>
              {upload.isPending ? (ar ? 'جارٍ الرفع…' : 'Uploading…') : ar ? 'رفع شعار' : 'Upload logo'}
            </Button>
            {branding?.has_logo ? (
              <Button icon="trash" variant="danger" disabled={!canEdit || busy} onClick={() => remove.mutate()}>
                {ar ? 'إزالة' : 'Remove'}
              </Button>
            ) : null}
          </div>
          <p className="text-small text-text-subtlest">
            {ar ? 'يتم حفظ الشعار فور رفعه ولا يحتاج زر الحفظ.' : 'The logo is saved as soon as it is uploaded; no need to press Save.'}
          </p>
        </div>
      </div>
      {error ? (
        <div className="px-5 pb-5" role="alert">
          <InlineNotice tone="negative">{error}</InlineNotice>
        </div>
      ) : null}
    </Card>
  )
}
