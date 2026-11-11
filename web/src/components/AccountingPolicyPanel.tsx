import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { Account } from '../lib/types'
import { Badge, Button, Card, CardHeader, ErrorState, Loading, Modal, SearchableSelect, Table, Td, Th } from './ui'

type Setting = { group: string; key: string; value: string | null; is_secret: boolean }
type Mapping = { group: 'accounting' | 'assets'; key: string; type: Account['type']; ar: string; en: string }

const typeLabel: Record<Account['type'], { ar: string; en: string }> = {
  asset: { ar: 'أصول', en: 'Asset' },
  liability: { ar: 'التزامات', en: 'Liability' },
  equity: { ar: 'حقوق ملكية', en: 'Equity' },
  revenue: { ar: 'إيرادات', en: 'Revenue' },
  expense: { ar: 'مصروفات', en: 'Expense' },
}

// Expected account types mirror SettingService::ACCOUNT_TYPES on the API.
const mappings: Mapping[] = [
  { group: 'accounting', key: 'ar_account_code', type: 'asset', ar: 'حسابات العملاء (المدينون)', en: 'Accounts receivable' },
  { group: 'accounting', key: 'ap_account_code', type: 'liability', ar: 'حسابات الموردين (الدائنون)', en: 'Accounts payable' },
  { group: 'accounting', key: 'advance_account_code', type: 'liability', ar: 'دفعات مقدمة من العملاء', en: 'Customer advances' },
  { group: 'accounting', key: 'supplier_advance_account_code', type: 'asset', ar: 'دفعات مقدمة للموردين', en: 'Supplier advances' },
  { group: 'accounting', key: 'revenue_account_code', type: 'revenue', ar: 'الإيرادات', en: 'Revenue' },
  { group: 'accounting', key: 'cost_account_code', type: 'expense', ar: 'تكلفة الخدمات', en: 'Cost of services' },
  { group: 'accounting', key: 'tax_payable_account_code', type: 'liability', ar: 'ضريبة مستحقة', en: 'Tax payable' },
  { group: 'accounting', key: 'output_tax_account_code', type: 'liability', ar: 'ضريبة المخرجات', en: 'Output tax' },
  { group: 'accounting', key: 'input_tax_account_code', type: 'asset', ar: 'ضريبة المدخلات', en: 'Input tax' },
  { group: 'accounting', key: 'retained_earnings_account_code', type: 'equity', ar: 'الأرباح المحتجزة', en: 'Retained earnings' },
  { group: 'accounting', key: 'fx_gain_account_code', type: 'revenue', ar: 'أرباح فروق العملة', en: 'FX gain' },
  { group: 'accounting', key: 'fx_loss_account_code', type: 'expense', ar: 'خسائر فروق العملة', en: 'FX loss' },
  { group: 'accounting', key: 'rounding_account_code', type: 'expense', ar: 'فروق التقريب', en: 'Rounding differences' },
  { group: 'accounting', key: 'bank_charges_account_code', type: 'expense', ar: 'مصروفات بنكية', en: 'Bank charges' },
  { group: 'accounting', key: 'bank_interest_account_code', type: 'revenue', ar: 'فوائد بنكية', en: 'Bank interest' },
  { group: 'accounting', key: 'employee_advance_account_code', type: 'asset', ar: 'سلف الموظفين', en: 'Employee advances' },
  { group: 'accounting', key: 'employee_custody_account_code', type: 'asset', ar: 'عهد الموظفين', en: 'Employee custody' },
  { group: 'accounting', key: 'prepaid_expense_account_code', type: 'asset', ar: 'مصروفات مدفوعة مقدماً', en: 'Prepaid expenses' },
  { group: 'accounting', key: 'deferred_revenue_account_code', type: 'liability', ar: 'إيرادات مؤجلة', en: 'Deferred revenue' },
  { group: 'accounting', key: 'accrued_liability_account_code', type: 'liability', ar: 'مصروفات مستحقة', en: 'Accrued liabilities' },
  { group: 'accounting', key: 'accrued_revenue_account_code', type: 'asset', ar: 'إيرادات مستحقة', en: 'Accrued revenue' },
  { group: 'accounting', key: 'unearned_account_code', type: 'liability', ar: 'إيرادات غير مكتسبة', en: 'Unearned revenue' },
  { group: 'assets', key: 'asset_account_code', type: 'asset', ar: 'الأصول الثابتة', en: 'Fixed assets' },
  { group: 'assets', key: 'accumulated_depreciation_account_code', type: 'asset', ar: 'مجمع الإهلاك', en: 'Accumulated depreciation' },
  { group: 'assets', key: 'depreciation_expense_account_code', type: 'expense', ar: 'مصروف الإهلاك', en: 'Depreciation expense' },
  { group: 'assets', key: 'disposal_gain_account_code', type: 'revenue', ar: 'أرباح استبعاد الأصول', en: 'Disposal gain' },
  { group: 'assets', key: 'disposal_loss_account_code', type: 'expense', ar: 'خسائر استبعاد الأصول', en: 'Disposal loss' },
  { group: 'assets', key: 'impairment_loss_account_code', type: 'expense', ar: 'خسائر انخفاض القيمة', en: 'Impairment loss' },
  { group: 'assets', key: 'acquisition_offset_account_code', type: 'liability', ar: 'مقابل اقتناء الأصول', en: 'Acquisition offset' },
  { group: 'assets', key: 'disposal_proceeds_account_code', type: 'asset', ar: 'متحصلات الاستبعاد', en: 'Disposal proceeds' },
]

export default function AccountingPolicyPanel() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [policyError, setPolicyError] = useState('')
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})
  const [savingKey, setSavingKey] = useState<string | null>(null)

  const canApprove = can('accounting.approve_policy')
  const canEdit = can('settings.update')

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Setting[] }>('/settings')
      return data.data
    },
  })

  const accountsQuery = useQuery({
    queryKey: ['accounts', 'policy-panel'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/accounts')
      return data.data
    },
    enabled: can('accounts.view'),
  })

  const values = useMemo(() => {
    const map: Record<string, string> = {}
    for (const setting of settingsQuery.data ?? []) map[`${setting.group}.${setting.key}`] = setting.value ?? ''
    return map
  }, [settingsQuery.data])

  const postable = useMemo(() => (accountsQuery.data ?? []).filter((account) => account.is_postable && account.is_active), [accountsQuery.data])

  const approved = values['accounting.accounting_policy_approved'] === 'true'
  const approvedBy = values['accounting.approved_by']
  const approvedAt = values['accounting.approved_at']

  const optionsFor = (type: Account['type']) =>
    postable
      .filter((account) => account.type === type)
      .map((account) => ({
        value: account.code,
        label: `${account.code} — ${locale === 'ar' ? account.name_ar : account.name_en}`,
        hint: `${account.name_ar} ${account.name_en}`,
      }))

  const togglePolicy = useMutation({
    mutationFn: async (next: boolean) => {
      await api.put('/settings', {
        settings: [{ group: 'accounting', key: 'accounting_policy_approved', value: next ? 'true' : 'false' }],
      })
    },
    onSuccess: async () => {
      setConfirmOpen(false)
      setPolicyError('')
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
    },
    onError: (cause) => {
      setConfirmOpen(false)
      setPolicyError(errorMessage(cause))
    },
  })

  const saveMapping = async (mapping: Mapping, code: string) => {
    const id = `${mapping.group}.${mapping.key}`
    setSavingKey(id)
    setRowErrors((current) => ({ ...current, [id]: '' }))
    try {
      await api.put('/settings', { settings: [{ group: mapping.group, key: mapping.key, value: code }] })
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
    } catch (cause) {
      let message = errorMessage(cause)
      if (axios.isAxiosError(cause)) {
        const fieldErrors = cause.response?.data?.errors as Record<string, string[]> | undefined
        message = fieldErrors?.[mapping.key]?.[0] ?? message
      }
      setRowErrors((current) => ({ ...current, [id]: message }))
    } finally {
      setSavingKey(null)
    }
  }

  return (
    <Card className="mt-6">
      <CardHeader
        title={L('السياسة المحاسبية والحسابات الافتراضية', 'Accounting policy and default accounts')}
        subtitle={L('لا يمكن ترحيل أي قيد قبل اعتماد السياسة المحاسبية', 'Nothing can be posted until the accounting policy is approved')}
        actions={
          <Badge tone={approved ? 'positive' : 'warning'}>{approved ? L('معتمدة', 'Approved') : L('غير معتمدة', 'Not approved')}</Badge>
        }
      />

      <div className="px-5 py-4">
        {!approved ? (
          <p className="rounded-12 border border-warning-border bg-warning-background px-3 py-2 text-medium text-warning-text">
            {L(
              'تنبيه: الترحيل المحاسبي معطّل حتى يعتمد المحاسب السياسة المحاسبية. لن تُرحَّل الحجوزات أو المدفوعات أو القيود.',
              'Warning: posting is disabled until an accountant approves the accounting policy. Bookings, payments and journal entries cannot be posted.',
            )}
          </p>
        ) : (
          <p className="text-medium text-text-subtle">
            {L('اعتُمدت بواسطة', 'Approved by')} <span className="font-medium text-text-default">{approvedBy || '—'}</span>
            {approvedAt ? (
              <>
                {' '}
                {L('بتاريخ', 'on')} <span className="tabular">{new Date(approvedAt).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-GB')}</span>
              </>
            ) : null}
          </p>
        )}
        {policyError ? (
          <p className="mt-3 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{policyError}</p>
        ) : null}
        {canApprove ? (
          <div className="mt-3">
            <Button variant={approved ? 'secondary' : 'primary'} onClick={() => setConfirmOpen(true)} disabled={togglePolicy.isPending}>
              {approved ? L('سحب الاعتماد', 'Revoke approval') : L('اعتماد السياسة المحاسبية', 'Approve accounting policy')}
            </Button>
          </div>
        ) : null}
      </div>

      {settingsQuery.isLoading ? <Loading /> : null}
      {settingsQuery.isError ? <ErrorState message={errorMessage(settingsQuery.error)} onRetry={() => void settingsQuery.refetch()} /> : null}
      {accountsQuery.isError ? (
        <p className="mx-5 mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">
          {errorMessage(accountsQuery.error)}
        </p>
      ) : null}
      {settingsQuery.data ? (
        <Table>
          <thead>
            <tr>
              <Th>{L('الحساب الافتراضي', 'Default account')}</Th>
              <Th>{L('الكود الحالي', 'Current code')}</Th>
              <Th>{L('تغيير إلى', 'Change to')}</Th>
            </tr>
          </thead>
          <tbody>
            {mappings.map((mapping) => {
              const id = `${mapping.group}.${mapping.key}`
              const current = values[id] ?? ''
              const error = rowErrors[id]
              return (
                <tr key={id}>
                  <Td>
                    <p className="font-medium">{L(mapping.ar, mapping.en)}</p>
                    <p className="text-xs text-text-subtle">{L(typeLabel[mapping.type].ar, typeLabel[mapping.type].en)}</p>
                  </Td>
                  <Td className="tabular font-medium" dir="ltr">
                    {current || '—'}
                  </Td>
                  <Td>
                    <SearchableSelect
                      value={current}
                      options={optionsFor(mapping.type)}
                      placeholder={L('ابحث عن حساب', 'Search accounts')}
                      disabled={!canEdit || !can('accounts.view') || savingKey === id}
                      onChange={(code) => {
                        if (code && code !== current) void saveMapping(mapping, code)
                      }}
                    />
                    {error ? <p className="mt-1 text-small text-danger-text">{error}</p> : null}
                  </Td>
                </tr>
              )
            })}
          </tbody>
        </Table>
      ) : null}

      <Modal
        open={confirmOpen}
        title={approved ? L('سحب اعتماد السياسة المحاسبية', 'Revoke accounting policy approval') : L('اعتماد السياسة المحاسبية', 'Approve accounting policy')}
        onClose={() => setConfirmOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmOpen(false)}>
              {L('إلغاء', 'Cancel')}
            </Button>
            <Button disabled={togglePolicy.isPending} onClick={() => togglePolicy.mutate(!approved)}>
              {approved ? L('تأكيد السحب', 'Confirm revoke') : L('تأكيد الاعتماد', 'Confirm approval')}
            </Button>
          </>
        }
      >
        <p className="text-medium">
          {approved
            ? L(
                'سيتوقف الترحيل المحاسبي فوراً في كل أنحاء النظام إلى أن يُعاد الاعتماد.',
                'All posting stops immediately across the system until the policy is approved again.',
              )
            : L(
                'باعتماد السياسة تؤكد مراجعة الحسابات الافتراضية أدناه وقواعد الاعتراف بالإيراد. سيُسجَّل اسمك وتاريخ الاعتماد في سجل التدقيق.',
                'By approving, you confirm the default accounts below and the revenue recognition rules were reviewed. Your name and the date are recorded in the audit trail.',
              )}
        </p>
      </Modal>
    </Card>
  )
}
