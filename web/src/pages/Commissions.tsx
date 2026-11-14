import { DateField } from '../components/DateField'
import { useCallback, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toDecimal, toMinor, todayISO } from '../lib/format'
import { useRemoteOptions } from '../lib/useRemoteOptions'
import type { Employee, Paginated, Supplier } from '../lib/types'
import {
  basisLabel,
  beneficiaryName,
  beneficiaryTypeLabel,
  bpToPercent,
  commissionStatusLabel,
  percentToBp,
  ruleStatusLabel,
  type BeneficiaryRow,
  type BeneficiaryType,
  type Commission,
  type CommissionBasis,
  type CommissionRule,
  type CommissionStatement,
} from '../lib/commission'
import { localizedName } from '../lib/names'
import { ReasonModal } from '../components/ReasonModal'
import { CodeField } from '../components/CodeField'
import { createdWithCode } from '../lib/codes'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  InlineNotice,
  Input,
  Loading,
  Modal,
  Money,
  PageHeader,
  Pagination,
  SearchableSelect,
  type SelectOption,
  Select,
  StatCard,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'

type Tab = 'rules' | 'commissions' | 'statements'
type RuleAction = { rule: CommissionRule; kind: 'reject' }
type CommissionAction = { commission: Commission; kind: 'reject' | 'reverse' }
type CashAccount = { id: string; cash_account_id?: string; name_ar: string; name_en: string; currency: string; registered: boolean; is_active?: boolean }

const SERVICE_TYPES = ['flight', 'hotel', 'transport', 'visa', 'program', 'other']
const SERVICE_LABELS: Record<string, [string, string]> = {
  flight: ['طيران', 'Flight'],
  hotel: ['فندق', 'Hotel'],
  transport: ['نقل', 'Transport'],
  visa: ['تأشيرة', 'Visa'],
  program: ['برنامج', 'Program'],
  other: ['أخرى', 'Other'],
}

const emptyRule = {
  name_ar: '',
  name_en: '',
  beneficiary_type: 'employee' as BeneficiaryType,
  beneficiary_id: '',
  basis: 'percent_revenue' as CommissionBasis,
  rate: '',
  fixed: '',
  branch_id: '',
  service_type: '',
  allow_cross_branch: false,
  effective_from: todayISO(),
  effective_to: '',
}

const isAgent = (row: Supplier) => row.category === 'agent'

export default function Commissions() {
  const { t, locale } = useI18n()
  const { activeBranch, branches, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [tab, setTab] = useState<Tab>('commissions')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [editCode, setEditCode] = useState<string | null>(null)

  // ---- rules ------------------------------------------------------------
  const [ruleStatus, setRuleStatus] = useState('')
  const [ruleOpen, setRuleOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [form, setForm] = useState(emptyRule)
  const [ruleAction, setRuleAction] = useState<RuleAction | null>(null)

  const rulesQuery = useQuery({
    queryKey: ['commission-rules', ruleStatus],
    enabled: tab === 'rules',
    queryFn: async () => {
      const { data } = await api.get<Paginated<CommissionRule>>('/commission-rules', {
        params: { per_page: 100, ...(ruleStatus ? { status: ruleStatus } : {}) },
      })
      return data.data
    },
  })

  const employeeOption = useCallback(
    (row: Employee): SelectOption => ({ value: row.id, label: localizedName(row, locale), hint: row.internal_code }),
    [locale],
  )
  const agentOption = useCallback(
    (row: Supplier): SelectOption => ({ value: row.id, label: localizedName(row, locale) }),
    [locale],
  )
  const employeePicker = useRemoteOptions<Employee>({
    endpoint: '/employees',
    keyPrefix: 'commission-employees',
    toOption: employeeOption,
    value: form.beneficiary_type === 'employee' ? form.beneficiary_id : '',
    enabled: ruleOpen && form.beneficiary_type === 'employee',
  })
  const agentPicker = useRemoteOptions<Supplier>({
    endpoint: '/suppliers',
    keyPrefix: 'commission-agents',
    toOption: agentOption,
    select: isAgent,
    value: form.beneficiary_type === 'agent' ? form.beneficiary_id : '',
    enabled: ruleOpen && form.beneficiary_type === 'agent',
  })
  const branchOptions = useMemo<SelectOption[]>(
    () => branches.map((branch) => ({ value: branch.id, label: localizedName(branch, locale), hint: branch.code })),
    [branches, locale],
  )
  const beneficiaryOptions =
    form.beneficiary_type === 'employee' ? employeePicker.options : form.beneficiary_type === 'agent' ? agentPicker.options : branchOptions
  const onBeneficiarySearch = useCallback(
    (query: string) => {
      if (form.beneficiary_type === 'employee') employeePicker.setSearch(query)
      if (form.beneficiary_type === 'agent') agentPicker.setSearch(query)
    },
    [form.beneficiary_type, employeePicker, agentPicker],
  )

  const refreshRules = () => queryClient.invalidateQueries({ queryKey: ['commission-rules'] })

  const saveRule = useMutation({
    mutationFn: async () => {
      const percent = form.basis === 'fixed' ? null : percentToBp(form.rate)
      if (form.basis !== 'fixed' && percent === null) throw new Error(L('أدخل نسبة بين 0.01 و100 (خانتان عشريتان كحد أقصى).', 'Enter a percentage from 0.01 to 100 (two decimals at most).'))
      const payload = {
        name_ar: form.name_ar,
        name_en: form.name_en,
        beneficiary_type: form.beneficiary_type,
        beneficiary_id: form.beneficiary_id || null,
        basis: form.basis,
        rate_bp: percent,
        fixed_minor: form.basis === 'fixed' ? toMinor(form.fixed, activeCurrency) : null,
        branch_id: form.branch_id || null,
        service_type: form.service_type || null,
        allow_cross_branch: form.allow_cross_branch,
        effective_from: form.effective_from,
        effective_to: form.effective_to || null,
      }
      if (editId) {
        await api.patch(`/commission-rules/${editId}`, payload)
        return null
      }
      // The code is generated by the server (RULE-001, ...) and comes back in the response.
      const { data } = await api.post<{ data: CommissionRule }>('/commission-rules', payload)
      return data.data
    },
    onSuccess: async (created) => {
      if (created) setNotice(createdWithCode(locale === 'ar', 'القاعدة', 'Rule', created.code))
      setRuleOpen(false)
      setEditId(null)
      setForm(emptyRule)
      setError('')
      await refreshRules()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const ruleStep = useMutation({
    mutationFn: async ({ rule, path }: { rule: CommissionRule; path: 'submit' | 'approve' | 'retire' | 'new-version' }) => {
      await api.post(`/commission-rules/${rule.id}/${path}`)
    },
    onSuccess: async () => {
      setError('')
      await refreshRules()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const ruleReason = useMutation({
    mutationFn: async (reason: string) => {
      if (!ruleAction) return
      await api.post(`/commission-rules/${ruleAction.rule.id}/${ruleAction.kind}`, { reason })
    },
    onSuccess: async () => {
      setRuleAction(null)
      setError('')
      await refreshRules()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const deleteRule = useMutation({
    mutationFn: async (rule: CommissionRule) => {
      await api.delete(`/commission-rules/${rule.id}`)
    },
    onSuccess: async () => {
      setError('')
      await refreshRules()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  // Fixed amounts are base-currency minor units; the base currency exponent comes from the active currency list.
  const currencyQuery = useQuery({
    queryKey: ['commission-base-currency'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ group: string; key: string; value: string | null }> }>('/settings')
      return data.data.find((row) => row.group === 'general' && row.key === 'base_currency')?.value ?? 'EGP'
    },
    enabled: can('settings.view'),
  })
  const activeCurrency = currencyQuery.data ?? 'EGP'

  const openRule = (rule?: CommissionRule) => {
    setError('')
    setNotice('')
    setEditCode(rule?.code ?? null)
    if (rule) {
      setEditId(rule.id)
      setForm({
        name_ar: rule.name_ar,
        name_en: rule.name_en,
        beneficiary_type: rule.beneficiary_type,
        beneficiary_id: rule.beneficiary?.id ?? '',
        basis: rule.basis,
        rate: bpToPercent(rule.rate_bp),
        fixed: rule.fixed_minor !== null ? toDecimal(rule.fixed_minor, activeCurrency) : '',
        branch_id: rule.branch_id ?? '',
        service_type: rule.service_type ?? '',
        allow_cross_branch: rule.allow_cross_branch,
        effective_from: rule.effective_from,
        effective_to: rule.effective_to ?? '',
      })
    } else {
      setEditId(null)
      setForm(emptyRule)
    }
    setRuleOpen(true)
  }

  // ---- accruals ------------------------------------------------------------
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [action, setAction] = useState<CommissionAction | null>(null)
  const [overrideTarget, setOverrideTarget] = useState<Commission | null>(null)
  const [overrideForm, setOverrideForm] = useState({ amount: '', reason: '' })
  const [payTarget, setPayTarget] = useState<Commission | null>(null)
  const [payForm, setPayForm] = useState({ cash_account_id: '', amount: '', paid_on: todayISO(), reference: '' })

  const commissionsQuery = useQuery({
    queryKey: ['commissions', page, status, activeBranch?.id],
    enabled: tab === 'commissions',
    queryFn: async () => {
      const { data } = await api.get<Paginated<Commission>>('/commissions', {
        params: { page, per_page: 20, ...(status ? { status } : {}) },
      })
      return data
    },
  })

  const cashQuery = useQuery({
    queryKey: ['cash-accounts', 'commissions'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
    enabled: payTarget !== null,
  })

  const refreshCommissions = async () => {
    await queryClient.invalidateQueries({ queryKey: ['commissions'] })
    await queryClient.invalidateQueries({ queryKey: ['commission-beneficiaries'] })
    await queryClient.invalidateQueries({ queryKey: ['commission-statement'] })
  }

  const step = useMutation({
    mutationFn: async ({ commission, path }: { commission: Commission; path: 'approve' | 'post' }) => {
      await api.post(`/commissions/${commission.id}/${path}`, undefined, { headers: { 'Idempotency-Key': idempotencyKey() } })
    },
    onSuccess: async () => {
      setError('')
      await refreshCommissions()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const withReason = useMutation({
    mutationFn: async (reason: string) => {
      if (!action) return
      await api.post(`/commissions/${action.commission.id}/${action.kind}`, { reason })
    },
    onSuccess: async () => {
      setAction(null)
      setError('')
      await refreshCommissions()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const override = useMutation({
    mutationFn: async () => {
      if (!overrideTarget) return
      await api.post(`/commissions/${overrideTarget.id}/override`, {
        amount_minor: toMinor(overrideForm.amount, overrideTarget.currency),
        reason: overrideForm.reason,
      })
    },
    onSuccess: async () => {
      setOverrideTarget(null)
      setError('')
      await refreshCommissions()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const pay = useMutation({
    mutationFn: async () => {
      if (!payTarget) return
      await api.post(
        `/commissions/${payTarget.id}/pay`,
        {
          cash_account_id: payForm.cash_account_id,
          amount_minor: payForm.amount ? toMinor(payForm.amount, payTarget.currency) : undefined,
          paid_on: payForm.paid_on,
          reference: payForm.reference || undefined,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setPayTarget(null)
      setError('')
      await refreshCommissions()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const cashOptions = (cashQuery.data ?? [])
    .filter((account) => account.registered && account.is_active !== false && payTarget && account.currency === payTarget.currency)
    .map((account) => ({ value: account.cash_account_id ?? account.id, label: localizedName(account, locale) }))

  // ---- statements -----------------------------------------------------------
  const [beneficiaryFilter, setBeneficiaryFilter] = useState('')
  const [selected, setSelected] = useState<BeneficiaryRow | null>(null)
  const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`)
  const [to, setTo] = useState(todayISO())

  const beneficiariesQuery = useQuery({
    queryKey: ['commission-beneficiaries', beneficiaryFilter],
    enabled: tab === 'statements',
    queryFn: async () => {
      const { data } = await api.get<{ data: BeneficiaryRow[] }>('/commissions/beneficiaries', {
        params: beneficiaryFilter ? { beneficiary_type: beneficiaryFilter } : {},
      })
      return data.data
    },
  })

  const statementQuery = useQuery({
    queryKey: ['commission-statement', selected?.beneficiary_type, selected?.beneficiary?.id, from, to],
    enabled: tab === 'statements' && selected?.beneficiary?.id != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: CommissionStatement }>('/commissions/statement', {
        params: { beneficiary_type: selected?.beneficiary_type, beneficiary_id: selected?.beneficiary?.id, from, to },
      })
      return data.data
    },
  })

  const statement = statementQuery.data
  const base = activeCurrency

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'commissions', label: L('العمولات المستحقة', 'Commissions') },
    { id: 'rules', label: L('قواعد العمولة', 'Rules') },
    { id: 'statements', label: L('كشوف المستفيدين', 'Statements') },
  ]

  const banner = error && !ruleOpen && !ruleAction && !action && !overrideTarget && !payTarget ? error : ''

  return (
    <div>
      <PageHeader
        title={L('العمولات', 'Commissions')}
        subtitle={L(
          'قواعد العمولة وفترات سريانها واعتماد المستحقات ودفعها وكشف حساب كل مستفيد',
          'Commission rules, effective dates, approval, payout and a statement per beneficiary',
        )}
        icon="coins"
        actions={
          tab === 'rules' && can('commission_rules.manage') ? (
            <Button icon="plus" onClick={() => openRule()}>{L('قاعدة جديدة', 'New rule')}</Button>
          ) : null
        }
      />

      <div className="mb-4 flex flex-wrap gap-2" role="tablist">
        {tabs.map((item) => (
          <Button key={item.id} variant={tab === item.id ? 'primary' : 'secondary'} onClick={() => { setTab(item.id); setError('') }}>
            {item.label}
          </Button>
        ))}
      </div>

      {notice ? (
        <div className="mb-4" role="status">
          <InlineNotice tone="positive">{notice}</InlineNotice>
        </div>
      ) : null}

      {banner ? <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{banner}</p> : null}

      {tab === 'rules' ? (
        <Card>
          <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
            <Field label={t('status')}>
              <Select value={ruleStatus} onChange={(event) => setRuleStatus(event.target.value)}>
                <option value="">{L('الكل', 'All')}</option>
                {['draft', 'pending_approval', 'approved', 'rejected', 'retired'].map((value) => (
                  <option key={value} value={value}>{ruleStatusLabel(value, locale)}</option>
                ))}
              </Select>
            </Field>
          </div>
          {rulesQuery.isLoading ? <Loading /> : null}
          {rulesQuery.isError ? <ErrorState message={errorMessage(rulesQuery.error)} onRetry={() => void rulesQuery.refetch()} /> : null}
          {rulesQuery.data && rulesQuery.data.length === 0 ? <EmptyState /> : null}
          {rulesQuery.data && rulesQuery.data.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{L('القاعدة', 'Rule')}</Th>
                  <Th>{L('المستفيد', 'Beneficiary')}</Th>
                  <Th>{L('الأساس', 'Basis')}</Th>
                  <Th>{L('النطاق', 'Scope')}</Th>
                  <Th>{L('الفترة', 'Effective')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {rulesQuery.data.map((rule) => (
                  <tr key={rule.id}>
                    <Td>
                      <div className="font-medium tabular">{rule.code} · v{rule.version}</div>
                      <div className="text-small text-text-subtle">{localizedName(rule, locale)}</div>
                      {rule.rejection_reason ? <div className="text-small text-danger-text">{rule.rejection_reason}</div> : null}
                    </Td>
                    <Td>
                      {beneficiaryTypeLabel(rule.beneficiary_type, locale)}
                      <div className="text-small text-text-subtle">
                        {beneficiaryName(rule.beneficiary, locale, rule.beneficiary_type === 'employee' ? L('صاحب الحجز', 'Booking owner') : L('فرع الحجز', 'Booking branch'))}
                      </div>
                    </Td>
                    <Td className="tabular">
                      {basisLabel(rule.basis, locale)}
                      <div className="text-small text-text-subtle">
                        {rule.basis === 'fixed' ? <Money amountMinor={rule.fixed_minor} currency={base} /> : `${bpToPercent(rule.rate_bp)}%`}
                      </div>
                    </Td>
                    <Td className="text-small">
                      {rule.service_type ? (SERVICE_LABELS[rule.service_type]?.[locale === 'ar' ? 0 : 1] ?? rule.service_type) : L('كل الخدمات', 'All services')}
                      <div className="text-text-subtle">{rule.branch_id ? L('فرع محدد', 'One branch') : L('كل الفروع', 'All branches')}</div>
                      {rule.allow_cross_branch ? <div className="text-text-subtle">{L('يسمح بين الفروع', 'Cross-branch allowed')}</div> : null}
                    </Td>
                    <Td className="tabular text-small">
                      {formatDate(rule.effective_from, locale)}
                      <div className="text-text-subtle">{rule.effective_to ? formatDate(rule.effective_to, locale) : L('مفتوحة', 'Open-ended')}</div>
                    </Td>
                    <Td><Badge tone={statusTone(rule.status)}>{ruleStatusLabel(rule.status, locale)}</Badge></Td>
                    <Td className="text-end">
                      <div className="flex flex-wrap justify-end gap-1">
                        {['draft', 'rejected'].includes(rule.status) && can('commission_rules.manage') ? (
                          <>
                            <Button variant="ghost" onClick={() => openRule(rule)}>{t('edit')}</Button>
                            <Button variant="ghost" onClick={() => ruleStep.mutate({ rule, path: 'submit' })}>{L('إرسال للاعتماد', 'Submit')}</Button>
                          </>
                        ) : null}
                        {rule.status === 'draft' && can('commission_rules.manage') ? (
                          <Button variant="ghost" onClick={() => { if (window.confirm(L('حذف المسودة؟', 'Delete this draft?'))) deleteRule.mutate(rule) }}>{L('حذف', 'Delete')}</Button>
                        ) : null}
                        {rule.status === 'pending_approval' && can('commission_rules.approve') ? (
                          <>
                            <Button variant="ghost" onClick={() => ruleStep.mutate({ rule, path: 'approve' })}>{L('اعتماد', 'Approve')}</Button>
                            <Button variant="ghost" onClick={() => { setError(''); setRuleAction({ rule, kind: 'reject' }) }}>{L('رفض', 'Reject')}</Button>
                          </>
                        ) : null}
                        {rule.status === 'approved' && can('commission_rules.manage') ? (
                          <>
                            <Button variant="ghost" onClick={() => ruleStep.mutate({ rule, path: 'new-version' })}>{L('إصدار جديد', 'New version')}</Button>
                            <Button variant="ghost" onClick={() => ruleStep.mutate({ rule, path: 'retire' })}>{L('إيقاف', 'Retire')}</Button>
                          </>
                        ) : null}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      ) : null}

      {tab === 'commissions' ? (
        <Card>
          <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
            <Field label={t('status')}>
              <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
                <option value="">{L('الكل', 'All')}</option>
                {['accrued', 'approved', 'posted', 'paid', 'rejected', 'reversed', 'cancelled'].map((value) => (
                  <option key={value} value={value}>{commissionStatusLabel(value, locale)}</option>
                ))}
              </Select>
            </Field>
          </div>
          {commissionsQuery.isLoading ? <Loading /> : null}
          {commissionsQuery.isError ? <ErrorState message={errorMessage(commissionsQuery.error)} onRetry={() => void commissionsQuery.refetch()} /> : null}
          {commissionsQuery.data && commissionsQuery.data.data.length === 0 ? <EmptyState /> : null}
          {commissionsQuery.data && commissionsQuery.data.data.length > 0 ? (
            <>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('date')}</Th>
                    <Th>{L('الرقم', 'No.')}</Th>
                    <Th>{L('الحجز', 'Booking')}</Th>
                    <Th>{L('المستفيد', 'Beneficiary')}</Th>
                    <Th className="text-end">{L('الأساس', 'Basis')}</Th>
                    <Th className="text-end">{t('amount')}</Th>
                    <Th>{t('status')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {commissionsQuery.data.data.map((commission) => (
                    <tr key={commission.id}>
                      <Td className="tabular">{formatDate(commission.accrued_on, locale)}</Td>
                      <Td className="tabular">
                        {commission.number}
                        <div className="text-small text-text-subtle">{commission.rule_code} · v{commission.rule_version}</div>
                      </Td>
                      <Td className="tabular">{commission.booking_number ?? '—'}</Td>
                      <Td>
                        {beneficiaryName(commission.beneficiary, locale, '—')}
                        <div className="text-small text-text-subtle">{beneficiaryTypeLabel(commission.beneficiary_type, locale)}</div>
                      </Td>
                      <Td className="text-end">
                        <Money amountMinor={commission.basis_amount_minor} currency={commission.currency} />
                        <div className="text-small text-text-subtle">{commission.basis === 'fixed' ? basisLabel('fixed', locale) : `${bpToPercent(commission.rate_bp)}%`}</div>
                      </Td>
                      <Td className="text-end">
                        <Money amountMinor={commission.amount_minor} currency={commission.currency} />
                        {commission.override_minor !== null ? (
                          <div className="text-small text-text-subtle">
                            {L('محسوب', 'Calculated')} <Money amountMinor={commission.calculated_minor} currency={commission.currency} />
                          </div>
                        ) : null}
                        {commission.paid_minor > 0 ? (
                          <div className="text-small text-text-subtle">
                            {L('مدفوع', 'Paid')} <Money amountMinor={commission.paid_minor} currency={commission.currency} />
                          </div>
                        ) : null}
                      </Td>
                      <Td>
                        <Badge tone={statusTone(commission.status)}>{commissionStatusLabel(commission.status, locale)}</Badge>
                        {commission.needs_reversal ? <div className="text-small text-danger-text">{L('يتطلب عكسًا', 'Needs reversal')}</div> : null}
                      </Td>
                      <Td className="text-end">
                        <div className="flex flex-wrap justify-end gap-1">
                          {commission.status === 'accrued' && can('commissions.override') ? (
                            <Button variant="ghost" onClick={() => { setError(''); setOverrideForm({ amount: '', reason: '' }); setOverrideTarget(commission) }}>{L('تعديل المبلغ', 'Override')}</Button>
                          ) : null}
                          {commission.status === 'accrued' && can('commissions.approve') ? (
                            <>
                              <Button variant="ghost" onClick={() => step.mutate({ commission, path: 'approve' })}>{L('اعتماد', 'Approve')}</Button>
                              <Button variant="ghost" onClick={() => { setError(''); setAction({ commission, kind: 'reject' }) }}>{L('رفض', 'Reject')}</Button>
                            </>
                          ) : null}
                          {commission.status === 'approved' && can('commissions.post') ? (
                            <Button variant="ghost" onClick={() => step.mutate({ commission, path: 'post' })}>{L('ترحيل', 'Post')}</Button>
                          ) : null}
                          {commission.status === 'posted' && can('commissions.pay') ? (
                            <Button variant="ghost" onClick={() => { setError(''); setPayForm({ cash_account_id: '', amount: '', paid_on: todayISO(), reference: '' }); setPayTarget(commission) }}>{L('دفع', 'Pay')}</Button>
                          ) : null}
                          {['accrued', 'approved', 'posted', 'paid'].includes(commission.status) && can('commissions.reverse') ? (
                            <Button variant="ghost" onClick={() => { setError(''); setAction({ commission, kind: 'reverse' }) }}>{L('عكس', 'Reverse')}</Button>
                          ) : null}
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <Pagination page={commissionsQuery.data.meta.current_page} lastPage={commissionsQuery.data.meta.last_page} onChange={setPage} />
            </>
          ) : null}
        </Card>
      ) : null}

      {tab === 'statements' ? (
        <div className="grid gap-4">
          <Card>
            <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
              <Field label={L('نوع المستفيد', 'Beneficiary type')}>
                <Select value={beneficiaryFilter} onChange={(event) => { setBeneficiaryFilter(event.target.value); setSelected(null) }}>
                  <option value="">{L('الكل', 'All')}</option>
                  {(['employee', 'agent', 'branch'] as const).map((value) => (
                    <option key={value} value={value}>{beneficiaryTypeLabel(value, locale)}</option>
                  ))}
                </Select>
              </Field>
              <Field label={L('من', 'From')}>
                <DateField value={from} onChange={(event) => setFrom(event.target.value)} dir="ltr" />
              </Field>
              <Field label={L('إلى', 'To')}>
                <DateField value={to} onChange={(event) => setTo(event.target.value)} dir="ltr" />
              </Field>
            </div>
            {beneficiariesQuery.isLoading ? <Loading /> : null}
            {beneficiariesQuery.isError ? <ErrorState message={errorMessage(beneficiariesQuery.error)} onRetry={() => void beneficiariesQuery.refetch()} /> : null}
            {beneficiariesQuery.data && beneficiariesQuery.data.length === 0 ? <EmptyState /> : null}
            {beneficiariesQuery.data && beneficiariesQuery.data.length > 0 ? (
              <Table>
                <thead>
                  <tr>
                    <Th>{L('المستفيد', 'Beneficiary')}</Th>
                    <Th className="text-end">{L('بانتظار الاعتماد', 'Pending approval')}</Th>
                    <Th className="text-end">{L('معتمد غير مرحّل', 'Approved, unposted')}</Th>
                    <Th className="text-end">{L('مستحق الدفع', 'Payable')}</Th>
                    <Th className="text-end">{L('المدفوع', 'Paid')}</Th>
                    <Th className="text-end">{L('متوقعة (غير مستحقة)', 'Expected (not payable)')}</Th>
                    <Th className="text-end">{L('معدّلة', 'Adjusted')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {beneficiariesQuery.data.map((row) => (
                    <tr key={`${row.beneficiary_type}:${row.beneficiary?.id}`}>
                      <Td>
                        {beneficiaryName(row.beneficiary, locale, '—')}
                        <div className="text-small text-text-subtle">{beneficiaryTypeLabel(row.beneficiary_type, locale)}</div>
                      </Td>
                      <Td className="text-end"><Money amountMinor={row.pending_approval_minor} currency={base} /></Td>
                      <Td className="text-end"><Money amountMinor={row.approved_unposted_minor} currency={base} /></Td>
                      <Td className="text-end"><Money amountMinor={row.payable_minor} currency={base} /></Td>
 <Td className="text-end"><Money amountMinor={row.paid_minor} currency={base} /></Td>
                      <Td className="text-end"><Money amountMinor={row.expected_minor} currency={base} /></Td>
                      <Td className="text-end"><Money amountMinor={row.adjusted_minor} currency={base} /></Td>
                      <Td className="text-end"><Button variant="ghost" icon="eye" onClick={() => setSelected(row)}>{L('كشف الحساب', 'Statement')}</Button></Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            ) : null}
          </Card>

          {selected ? (
            <>
              <h2 className="heading-medium">
                {beneficiaryName(selected.beneficiary, locale, '—')} · {beneficiaryTypeLabel(selected.beneficiary_type, locale)}
              </h2>
              {statementQuery.isLoading ? <Loading /> : null}
              {statementQuery.isError ? <ErrorState message={errorMessage(statementQuery.error)} onRetry={() => void statementQuery.refetch()} /> : null}
              {statement ? (
                <>
                  <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={L('بانتظار الاعتماد', 'Pending approval')} value={<Money amountMinor={statement.summary.pending_approval_minor} currency={statement.currency} />} icon="clock" />
                    <StatCard label={L('معتمد غير مرحّل', 'Approved, unposted')} value={<Money amountMinor={statement.summary.approved_unposted_minor} currency={statement.currency} />} icon="checkCircle" />
                    <StatCard label={L('مستحق الدفع', 'Payable')} value={<Money amountMinor={statement.summary.payable_minor} currency={statement.currency} />} icon="coins" tone={statement.summary.payable_minor > 0 ? 'negative' : undefined} />
                    <StatCard label={L('المدفوع', 'Paid')} value={<Money amountMinor={statement.summary.paid_minor} currency={statement.currency} />} icon="banknote" tone="positive" />
                    <StatCard label={L('متوقعة (لا تُدفع قبل التحصيل الكامل)', 'Expected (not payable until fully collected)')} value={<Money amountMinor={statement.summary.expected_minor} currency={statement.currency} />} icon="clock" />
                    <StatCard label={L('معدّلة (استردادات وإلغاءات)', 'Adjusted (refunds and cancellations)')} value={<Money amountMinor={statement.summary.adjusted_minor} currency={statement.currency} />} icon="refresh" />
                  </div>
                  <Card>
                    {statement.lines.length === 0 ? <EmptyState /> : (
                      <Table>
                        <thead>
                          <tr>
                            <Th>{t('date')}</Th>
                            <Th>{L('البيان', 'Entry')}</Th>
                            <Th>{L('الحجز', 'Booking')}</Th>
                            <Th>{t('status')}</Th>
                            <Th className="text-end">{t('amount')}</Th>
                          </tr>
                        </thead>
                        <tbody>
                          {statement.lines.map((line, index) => (
                            <tr key={`${line.type}-${line.commission.id}-${index}`}>
                              <Td className="tabular">{formatDate(line.date, locale)}</Td>
                              <Td>{line.type === 'payment' ? L('دفعة عمولة', 'Commission payout') : L('عمولة', 'Commission')} <span className="tabular text-text-subtle">{line.commission.number}</span></Td>
                              <Td className="tabular">{line.commission.booking_number ?? '—'}</Td>
                              <Td><Badge tone={statusTone(line.commission.status)}>{commissionStatusLabel(line.commission.status, locale)}</Badge></Td>
                              <Td className="text-end"><Money amountMinor={line.amount_minor} currency={statement.currency} /></Td>
                            </tr>
                          ))}
                        </tbody>
                      </Table>
                    )}
                  </Card>
                </>
              ) : null}
            </>
          ) : null}
        </div>
      ) : null}

      <Modal
        open={ruleOpen}
        title={editId ? L('تعديل قاعدة العمولة', 'Edit commission rule') : L('قاعدة عمولة جديدة', 'New commission rule')}
        onClose={() => setRuleOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRuleOpen(false)}>{t('cancel')}</Button>
            <Button disabled={saveRule.isPending} onClick={() => saveRule.mutate()}>{saveRule.isPending ? t('saving') : t('save')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <CodeField code={editCode} label={L('الرمز', 'Code')} ar={locale === 'ar'} />
          <Field label={L('الاسم (عربي)', 'Name (Arabic)')} required>
            <Input value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} />
          </Field>
          <Field label={L('الاسم (إنجليزي)', 'Name (English)')} required>
            <Input value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('نوع المستفيد', 'Beneficiary type')} required>
            <Select value={form.beneficiary_type} onChange={(event) => setForm({ ...form, beneficiary_type: event.target.value as BeneficiaryType, beneficiary_id: '' })}>
              {(['employee', 'agent', 'branch'] as const).map((value) => (
                <option key={value} value={value}>{beneficiaryTypeLabel(value, locale)}</option>
              ))}
            </Select>
          </Field>
          <Field
            label={L('المستفيد', 'Beneficiary')}
            required={form.beneficiary_type === 'agent'}
            hint={form.beneficiary_type === 'agent' ? undefined : form.beneficiary_type === 'employee' ? L('اتركه فارغًا ليُصرف لصاحب الحجز.', 'Leave empty to pay the booking owner.') : L('اتركه فارغًا ليُصرف لفرع الحجز.', 'Leave empty to pay the booking branch.')}
          >
            <SearchableSelect
              value={form.beneficiary_id}
              onChange={(value) => setForm({ ...form, beneficiary_id: value })}
              options={beneficiaryOptions}
              onSearch={onBeneficiarySearch}
              placeholder={L('اختر', 'Select')}
              clearLabel={form.beneficiary_type === 'agent' ? undefined : L('تلقائي', 'Automatic')}
            />
          </Field>
          <Field label={L('أساس الاحتساب', 'Calculation basis')} required>
            <Select value={form.basis} onChange={(event) => setForm({ ...form, basis: event.target.value as CommissionBasis })}>
              {(['percent_revenue', 'percent_margin', 'fixed'] as const).map((value) => (
                <option key={value} value={value}>{basisLabel(value, locale)}</option>
              ))}
            </Select>
          </Field>
          {form.basis === 'fixed' ? (
            <Field label={`${L('المبلغ الثابت', 'Fixed amount')} (${base})`} required>
              <Input value={form.fixed} onChange={(event) => setForm({ ...form, fixed: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
          ) : (
            <Field label={L('النسبة %', 'Rate %')} required hint={L('حتى خانتين عشريتين.', 'Up to two decimals.')}>
              <Input value={form.rate} onChange={(event) => setForm({ ...form, rate: event.target.value })} inputMode="decimal" dir="ltr" />
            </Field>
          )}
          <Field label={L('نوع الخدمة', 'Service type')}>
            <Select value={form.service_type} onChange={(event) => setForm({ ...form, service_type: event.target.value })}>
              <option value="">{L('كل الخدمات', 'All services')}</option>
              {SERVICE_TYPES.map((value) => (
                <option key={value} value={value}>{SERVICE_LABELS[value][locale === 'ar' ? 0 : 1]}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('الفرع', 'Branch')}>
            <SearchableSelect
              value={form.branch_id}
              onChange={(value) => setForm({ ...form, branch_id: value })}
              options={branchOptions}
              placeholder={L('كل الفروع', 'All branches')}
              clearLabel={L('كل الفروع', 'All branches')}
            />
          </Field>
          <Field label={L('يسري من', 'Effective from')} required>
            <DateField value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('يسري حتى', 'Effective to')}>
            <DateField value={form.effective_to} onChange={(event) => setForm({ ...form, effective_to: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('بين الفروع', 'Cross-branch')}>
            <label className="flex items-center gap-2 py-2 text-sm">
              <input type="checkbox" checked={form.allow_cross_branch} onChange={(event) => setForm({ ...form, allow_cross_branch: event.target.checked })} />
              {L('صرف عمولة لمستفيد من فرع آخر', 'Pay a beneficiary of another branch')}
            </label>
          </Field>
        </div>
      </Modal>

      <ReasonModal
        open={ruleAction !== null}
        title={L('رفض قاعدة العمولة', 'Reject commission rule')}
        confirmLabel={L('رفض', 'Reject')}
        pending={ruleReason.isPending}
        error={error}
        onClose={() => setRuleAction(null)}
        onConfirm={(reason) => ruleReason.mutate(reason)}
      />

      <ReasonModal
        open={action !== null}
        title={action?.kind === 'reverse' ? L('عكس العمولة', 'Reverse commission') : L('رفض العمولة', 'Reject commission')}
        description={action?.kind === 'reverse' ? L('يُلغى المستحق غير المرحّل، ويُعكس القيد إذا كان مرحّلًا.', 'An unposted accrual is cancelled; a posted one is reversed in the ledger.') : undefined}
        confirmLabel={action?.kind === 'reverse' ? L('عكس', 'Reverse') : L('رفض', 'Reject')}
        pending={withReason.isPending}
        error={error}
        onClose={() => setAction(null)}
        onConfirm={(reason) => withReason.mutate(reason)}
      />

      <Modal
        open={overrideTarget !== null}
        title={L('تعديل مبلغ العمولة', 'Override commission amount')}
        onClose={() => setOverrideTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOverrideTarget(null)}>{t('cancel')}</Button>
            <Button disabled={override.isPending || overrideForm.amount === '' || overrideForm.reason.trim() === ''} onClick={() => override.mutate()}>{t('save')}</Button>
          </>
        }
      >
        <p className="mb-4 text-medium text-text-subtle">
          {L('لن يسري التعديل إلا بعد اعتماده من شخص آخر.', 'The override only takes effect once someone else approves it.')}
          {overrideTarget ? <> {L('المحسوب', 'Calculated')}: <Money amountMinor={overrideTarget.calculated_minor} currency={overrideTarget.currency} /></> : null}
        </p>
        <div className="grid gap-4">
          <Field label={`${L('المبلغ الجديد', 'New amount')} (${overrideTarget?.currency ?? base})`} required>
            <Input value={overrideForm.amount} onChange={(event) => setOverrideForm({ ...overrideForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('السبب', 'Reason')} required>
            <Input value={overrideForm.reason} maxLength={255} onChange={(event) => setOverrideForm({ ...overrideForm, reason: event.target.value })} />
          </Field>
        </div>
        {error ? <p className="mt-4 text-medium text-danger-text">{error}</p> : null}
      </Modal>

      <Modal
        open={payTarget !== null}
        title={L('دفع العمولة', 'Pay commission')}
        onClose={() => setPayTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setPayTarget(null)}>{t('cancel')}</Button>
            <Button disabled={pay.isPending || payForm.cash_account_id === ''} onClick={() => pay.mutate()}>{L('دفع', 'Pay')}</Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('حساب الدفع', 'Payment account')} required>
            <SearchableSelect value={payForm.cash_account_id} onChange={(value) => setPayForm({ ...payForm, cash_account_id: value })} options={cashOptions} placeholder={L('اختر', 'Select')} />
          </Field>
          <Field label={`${L('المبلغ', 'Amount')} (${payTarget?.currency ?? base})`} hint={payTarget ? `${L('المتبقي', 'Outstanding')}: ${toDecimal(payTarget.outstanding_minor, payTarget.currency)}` : undefined}>
            <Input value={payForm.amount} onChange={(event) => setPayForm({ ...payForm, amount: event.target.value })} inputMode="decimal" dir="ltr" placeholder={L('كامل المتبقي', 'Full outstanding')} />
          </Field>
          <Field label={t('date')}>
            <DateField value={payForm.paid_on} onChange={(event) => setPayForm({ ...payForm, paid_on: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('المرجع', 'Reference')}>
            <Input value={payForm.reference} onChange={(event) => setPayForm({ ...payForm, reference: event.target.value })} />
          </Field>
        </div>
        {error ? <p className="mt-4 text-medium text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
