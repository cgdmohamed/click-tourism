import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { useRemoteOptions } from '../lib/useRemoteOptions'
import type { AssignmentRule, LeadSourceConfig } from '../lib/types'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Loading,
  Modal,
  PageHeader,
  SearchableSelect,
  type SelectOption,
  Select,
  Table,
  Td,
  Th,
} from '../components/ui'

type Strategy = 'least_loaded' | 'round_robin' | 'manual'

const userToOption = (user: { id: string; name: string; email?: string }): SelectOption => ({
  value: user.id,
  label: user.name,
  hint: user.email,
})

export default function AssignmentRules() {
  const { t, locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [form, setForm] = useState({
    priority: '0',
    source_id: '',
    service_category: '',
    strategy: 'least_loaded' as Strategy,
    target_user_id: '',
    max_leads_per_user: '',
    is_active: true,
  })

  const rulesQuery = useQuery({
    queryKey: ['assignment-rules', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: AssignmentRule[] }>('/assignment-rules')
      return data.data
    },
  })

  const rules = rulesQuery.data ?? []

  const sourcesQuery = useQuery({
    queryKey: ['lead-sources'],
    queryFn: async () => {
      const { data } = await api.get<{ data: LeadSourceConfig[] }>('/lead-sources')
      return data.data
    },
  })

  const sourceOptions = useMemo(
    () => (sourcesQuery.data ?? []).map((source) => ({
      value: source.code,
      label: locale === 'ar' ? source.name_ar : source.name_en,
    })),
    [sourcesQuery.data, locale],
  )

  const sourceLabel = (code: string | null) => {
    if (!code) return locale === 'ar' ? 'أي مصدر' : 'Any Source'
    const found = (sourcesQuery.data ?? []).find((source) => source.code === code)
    return found ? (locale === 'ar' ? found.name_ar : found.name_en) : code
  }

  // The saved target of the rule being edited stays labelled even if the search no longer lists it.
  const knownTargets = useMemo(
    () => rules.flatMap((rule) => (rule.target_user ? [{ value: rule.target_user.id, label: rule.target_user.name }] : [])),
    [rules],
  )
  const userPicker = useRemoteOptions<{ id: string; name: string; email?: string }>({
    endpoint: '/users/assignable',
    keyPrefix: 'users-for-rules',
    toOption: userToOption,
    value: form.target_user_id,
    known: knownTargets,
  })

  const createOrUpdate = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        priority: parseInt(form.priority, 10) || 0,
        max_leads_per_user: form.max_leads_per_user ? parseInt(form.max_leads_per_user, 10) : null,
      }
      if (editId) {
        const { data } = await api.patch<{ data: AssignmentRule }>(`/assignment-rules/${editId}`, payload)
        return data.data
      }
      const { data } = await api.post<{ data: AssignmentRule }>('/assignment-rules', payload)
      return data.data
    },
    onSuccess: async () => {
      setOpen(false)
      setEditId(null)
      setError('')
      setForm({
        priority: '0',
        source_id: '',
        service_category: '',
        strategy: 'least_loaded',
        target_user_id: '',
        max_leads_per_user: '',
        is_active: true,
      })
      await queryClient.invalidateQueries({ queryKey: ['assignment-rules'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const deleteRule = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/assignment-rules/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['assignment-rules'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const strategyLabel = (value: string) => {
    const labels: Record<string, [string, string]> = {
      least_loaded: ['أقل حمل', 'Least Loaded'],
      round_robin: ['تناوب', 'Round Robin'],
      manual: ['يدوي', 'Manual'],
    }
    const found = labels[value]
    return found ? found[locale === 'ar' ? 0 : 1] : value
  }

  const openEdit = (rule: AssignmentRule) => {
    setForm({
      priority: String(rule.priority ?? 0),
      source_id: rule.source_id ?? '',
      service_category: rule.service_category ?? '',
      strategy: (rule.strategy as Strategy) ?? 'least_loaded',
      target_user_id: rule.target_user?.id ?? '',
      max_leads_per_user: rule.max_leads_per_user ? String(rule.max_leads_per_user) : '',
      is_active: rule.is_active,
    })
    setEditId(rule.id)
    setError('')
    setOpen(true)
  }

  return (
    <div>
      <PageHeader
        title={locale === 'ar' ? 'قواعد توزيع العملاء المحتملين' : 'Lead Assignment Rules'}
        subtitle={locale === 'ar' ? 'تحديد قواعد التوزيع حسب الفرع والمصدر والفئة والتوفر' : 'Rule-based lead routing by branch, source, category, and availability'}
        icon="target"
        actions={<Button icon="plus" onClick={() => setOpen(true)}>{locale === 'ar' ? 'قاعدة جديدة' : 'New rule'}</Button>}
      />

      <Card>
        <div className="flex items-center gap-3 border-b border-border-subtle px-5 py-4">
          <span className="text-xs text-text-subtle">{rules.length} {locale === 'ar' ? 'قاعدة' : 'rules'}</span>
        </div>

        {rulesQuery.isLoading ? <Loading /> : null}
        {rulesQuery.isError ? <ErrorState message={errorMessage(rulesQuery.error)} onRetry={() => void rulesQuery.refetch()} /> : null}
        {rules.length === 0 ? <EmptyState /> : null}

        {rules.length > 0 ? (
          <Table>
            <thead>
              <tr>
                <Th>{locale === 'ar' ? 'الأولوية' : 'Priority'}</Th>
                <Th>{locale === 'ar' ? 'الفرع' : 'Branch'}</Th>
                <Th>{locale === 'ar' ? 'المصدر' : 'Source'}</Th>
                <Th>{locale === 'ar' ? 'فئة الخدمة' : 'Service Category'}</Th>
                <Th>{locale === 'ar' ? 'الاستراتيجية' : 'Strategy'}</Th>
                <Th>{locale === 'ar' ? 'المستهدف' : 'Target'}</Th>
                <Th>{locale === 'ar' ? 'الحد الأقصى' : 'Max Leads'}</Th>
                <Th>{locale === 'ar' ? 'الحالة' : 'Status'}</Th>
                <Th className="text-end">{locale === 'ar' ? 'الإجراءات' : 'Actions'}</Th>
              </tr>
            </thead>
            <tbody>
              {rules.map((rule) => (
                <tr key={rule.id}>
                  <Td className="tabular font-medium">{rule.priority ?? 0}</Td>
                  <Td>{rule.branch_id ? 'Branch' : locale === 'ar' ? 'جميع الفروع' : 'All Branches'}</Td>
                  <Td>{sourceLabel(rule.source_id)}</Td>
                  <Td>{rule.service_category ?? locale === 'ar' ? 'أي فئة' : 'Any Category'}</Td>
                  <Td>{strategyLabel(rule.strategy)}</Td>
                  <Td>{rule.target_user_id ? rule.target_user?.name ?? rule.target_user_id : locale === 'ar' ? 'أي موظف متاح' : 'Any Available'}</Td>
                  <Td>{rule.max_leads_per_user ?? locale === 'ar' ? 'غير محدود' : 'Unlimited'}</Td>
                  <Td>
                    <Badge tone={rule.is_active ? 'positive' : 'neutral'}>
                      {rule.is_active ? (locale === 'ar' ? 'مفعل' : 'Active') : locale === 'ar' ? 'معطل' : 'Inactive'}
                    </Badge>
                  </Td>
                  <Td className="text-end">
                    <Button variant="ghost" icon="edit" onClick={() => openEdit(rule)} />
                    <Button variant="ghost" icon="trash" onClick={() => {
                      if (window.confirm(locale === 'ar' ? 'هل أنت متأكد؟' : 'Are you sure?')) {
                        deleteRule.mutate(rule.id)
                      }
                    }} />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={editId ? locale === 'ar' ? 'تعديل القاعدة' : 'Edit Rule' : locale === 'ar' ? 'قاعدة جديدة' : 'New Rule'}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('cancel')}
            </Button>
            <Button disabled={createOrUpdate.isPending} onClick={() => createOrUpdate.mutate()}>
              {createOrUpdate.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={locale === 'ar' ? 'الأولوية' : 'Priority'} required>
            <Input
              value={form.priority}
              onChange={(event) => setForm({ ...form, priority: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field label={locale === 'ar' ? 'الاستراتيجية' : 'Strategy'} required>
            <Select value={form.strategy} onChange={(event) => setForm({ ...form, strategy: event.target.value as Strategy })}>
              <option value="least_loaded">{locale === 'ar' ? 'أقل حمل' : 'Least Loaded'}</option>
              <option value="round_robin">{locale === 'ar' ? 'تناوب' : 'Round Robin'}</option>
              <option value="manual">{locale === 'ar' ? 'يدوي' : 'Manual'}</option>
            </Select>
          </Field>
          <Field label={locale === 'ar' ? 'المصدر' : 'Source'}>
            <SearchableSelect
              value={form.source_id}
              onChange={(source_id) => setForm({ ...form, source_id })}
              placeholder={locale === 'ar' ? 'أي مصدر' : 'Any Source'}
              clearLabel={locale === 'ar' ? 'أي مصدر' : 'Any Source'}
              options={sourceOptions}
            />
          </Field>
          <Field label={locale === 'ar' ? 'فئة الخدمة' : 'Service Category'}>
            <Input
              value={form.service_category}
              onChange={(event) => setForm({ ...form, service_category: event.target.value })}
              dir="ltr"
            />
          </Field>
          <Field label={locale === 'ar' ? 'المستخدم المستهدف' : 'Target User'}>
            <SearchableSelect
              value={form.target_user_id}
              onChange={(target_user_id) => setForm({ ...form, target_user_id })}
              placeholder={locale === 'ar' ? 'أي موظف' : 'Any User'}
              clearLabel={locale === 'ar' ? 'أي موظف' : 'Any User'}
              options={userPicker.options}
              onSearch={userPicker.setSearch}
              hasMore={userPicker.hasMore}
            />
          </Field>
          <Field label={locale === 'ar' ? 'الحد الأقصى لكل مستخدم' : 'Max Leads per User'}>
            <Input
              value={form.max_leads_per_user}
              onChange={(event) => setForm({ ...form, max_leads_per_user: event.target.value })}
              inputMode="decimal"
              dir="ltr"
            />
          </Field>
          <Field label={locale === 'ar' ? 'القاعدة نشطة' : 'Rule Active'}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
              />
              {locale === 'ar' ? 'القاعدة نشطة' : 'Rule is active'}
            </label>
          </Field>
        </div>
        {error ? <p className="mt-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
