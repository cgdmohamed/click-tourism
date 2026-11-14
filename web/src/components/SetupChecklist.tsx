import { Link } from 'react-router-dom'
import { useQueries } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api } from '../lib/api'
import { useSetting } from '../lib/useSetting'
import type { Paginated } from '../lib/types'
import { Badge, Card, CardHeader } from './ui'

type Step = { key: string; label: string; hint: string; to: string; done: boolean | null }

/** True when a list endpoint has at least `min` rows; null while unknown (loading or no permission to read it). */
function useCounts(endpoints: Array<{ key: string; path: string; allowed: boolean }>): Record<string, number | null> {
  const results = useQueries({
    queries: endpoints.map((endpoint) => ({
      queryKey: ['setup-checklist', endpoint.key],
      enabled: endpoint.allowed,
      staleTime: 5 * 60_000,
      queryFn: async () => (await api.get<Paginated<unknown>>(endpoint.path, { params: { per_page: 1 } })).data.meta.total,
    })),
  })
  return Object.fromEntries(endpoints.map((endpoint, index) => [endpoint.key, endpoint.allowed ? results[index].data ?? null : null]))
}

/**
 * First-run guide for administrators, derived from what already exists (no new setting, no new endpoint).
 * It disappears once every step it can verify is done.
 */
export function SetupChecklist() {
  const { can } = useAuth()
  const { locale } = useI18n()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const policy = useSetting('accounting', 'accounting_policy_approved', 'false')
  const counts = useCounts([
    { key: 'accounts', path: '/accounts', allowed: can('accounts.view') },
    { key: 'opening', path: '/opening-balances', allowed: can('opening_balances.view') },
    { key: 'users', path: '/users', allowed: can('users.view') },
    { key: 'customers', path: '/customers', allowed: can('customers.view') },
  ])

  if (!can('settings.update')) return null

  const steps: Step[] = [
    {
      key: 'policy',
      label: L('اعتماد السياسة المحاسبية', 'Approve the accounting policy'),
      hint: L('يلزم قبل ترحيل أي قيد.', 'Required before anything can be posted.'),
      to: '/settings',
      done: policy.loaded ? policy.value === 'true' : null,
    },
    {
      key: 'accounts',
      label: L('مراجعة دليل الحسابات', 'Review the chart of accounts'),
      hint: L('الحسابات الأساسية للنظام.', 'The accounts the system posts to.'),
      to: '/accounts',
      done: counts.accounts === null ? null : counts.accounts > 0,
    },
    {
      key: 'opening',
      label: L('تسجيل الأرصدة الافتتاحية', 'Enter opening balances'),
      hint: L('أرصدة الحسابات عند بدء استخدام النظام.', 'Balances at the date you start using the system.'),
      to: '/opening-balances',
      done: counts.opening === null ? null : counts.opening > 0,
    },
    {
      key: 'users',
      label: L('إضافة المستخدمين وأدوارهم', 'Add users and roles'),
      hint: L('مستخدم واحد على الأقل غيرك.', 'At least one user besides you.'),
      to: '/users',
      done: counts.users === null ? null : counts.users > 1,
    },
    {
      key: 'customers',
      label: L('إضافة أول عميل', 'Add the first customer'),
      hint: L('أو استورد العملاء من ملف.', 'Or import customers from a file.'),
      to: '/customers',
      done: counts.customers === null ? null : counts.customers > 0,
    },
  ]
  const known = steps.filter((step) => step.done !== null)
  if (known.length === 0 || known.every((step) => step.done)) return null
  const remaining = known.filter((step) => !step.done).length

  return (
    <Card className="mb-6 p-4 sm:p-5">
      <CardHeader title={L('إعداد النظام', 'Finish setting up')} icon="listChecks" actions={<Badge tone="warning">{remaining}</Badge>} />
      <ul className="mt-3 divide-y divide-border-subtle">
        {known.map((step) => (
          <li key={step.key} className="flex items-center justify-between gap-3 py-2">
            <div className="min-w-0">
              <p className={`label ${step.done ? 'text-text-subtle line-through' : ''}`}>{step.label}</p>
              {!step.done ? <p className="text-small text-text-subtle">{step.hint}</p> : null}
            </div>
            {step.done ? (
              <Badge tone="positive">{L('تم', 'Done')}</Badge>
            ) : (
              <Link to={step.to} className="text-small shrink-0 font-medium text-brand-1-accent hover:underline">
                {L('ابدأ', 'Start')}
              </Link>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}
