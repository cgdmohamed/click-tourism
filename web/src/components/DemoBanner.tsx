import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useI18n } from '../i18n'

type DemoStatus = {
  enabled: boolean
  reset_seconds: number | null
  next_reset_at: string | null
  generation: string | null
}

/**
 * Public demo warning, including login. The status comes from the server
 * rather than build-time flags, so production never displays demo branding.
 */
export function DemoBanner() {
  const { locale } = useI18n()
  const [now, setNow] = useState(Date.now())
  const previousGeneration = useRef<string | null>(null)
  const { data } = useQuery({
    queryKey: ['public-demo-status'],
    queryFn: async () => {
      const response = await api.get<{ data: DemoStatus }>('/demo/status')
      return response.data.data
    },
    retry: 0,
    staleTime: 0,
    refetchInterval: 10_000,
  })

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  useEffect(() => {
    if (!data?.enabled || !data.generation) return
    if (previousGeneration.current && previousGeneration.current !== data.generation) {
      // Database reset invalidates every session token and any unsaved form.
      window.location.reload()
      return
    }
    previousGeneration.current = data.generation
  }, [data?.enabled, data?.generation])

  if (!data?.enabled) return null
  const seconds = data.next_reset_at
    ? Math.max(0, Math.ceil((new Date(data.next_reset_at).getTime() - now) / 1000))
    : null
  const time = seconds === null
    ? (locale === 'ar' ? 'قيد التجهيز' : 'Preparing')
    : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

  return (
    <div role="status" aria-live="off" className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-warning-border bg-warning-background px-4 py-2 text-center text-small text-warning-text">
      <strong>{locale === 'ar' ? 'نسخة تجريبية — Demo Mode' : 'Demo Mode — Trial environment'}</strong>
      <span>
        {locale === 'ar'
          ? 'البيانات التي تُدخلها مؤقتة وتُحذف كل 10 دقائق. لا تُدخل أي بيانات حقيقية أو سرية.'
          : 'Entries are temporary and erased every 10 minutes. Do not enter real or sensitive data.'}
      </span>
      <span dir="ltr" className="rounded-8 border border-warning-border px-2 py-0.5 font-semibold tabular-nums">
        {time}
      </span>
    </div>
  )
}
