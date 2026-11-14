import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { DocumentsButton } from '../components/AttachmentsCard'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import { useTaxEnabled } from '../lib/useSetting'
import type { Paginated } from '../lib/types'
import PartyPicker from '../components/PartyPicker'
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
  Money,
  PageHeader,
  Pagination,
  SearchableSelect,
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'
import { localizedName } from '../lib/names'
import { statusLabel } from '../lib/labels'
import { AiBadge } from '../components/AiBadge'

type Category = { id: string; code: string; name_ar: string; name_en: string; account_code: string }
type CashAccount = { id: string; cash_account_id?: string; name_ar: string; name_en: string; currency: string; registered: boolean; is_active?: boolean }
type CurrencyRow = { code: string; is_active: boolean }

type Expense = {
  id: string
  created_via?: string | null
  number: string
  expense_date: string
  category: Category | null
  payee_name: string | null
  currency: string
  amount_minor: number
  tax_minor: number
  description: string
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'cancelled' | 'reversed'
  rejection_reason: string | null
}

type Action = { expense: Expense; kind: 'reject' | 'reverse' }

const emptyForm = {
  category_id: '',
  expense_date: todayISO(),
  amount: '',
  tax_applied: false,
  currency: 'EGP',
  fx_rate: '',
  description: '',
  reference: '',
  paid_from: 'cash' as 'cash' | 'supplier',
  cash_account_id: '',
  supplier_id: '',
}

export default function Expenses() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)
  const { enabled: taxEnabled } = useTaxEnabled()

  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [action, setAction] = useState<Action | null>(null)
  const [reason, setReason] = useState('')

  const expensesQuery = useQuery({
    queryKey: ['expenses', page, status, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Expense>>('/expenses', {
        params: { page, per_page: 20, ...(status ? { status } : {}) },
      })
      return data
    },
  })

  const categoriesQuery = useQuery({
    queryKey: ['expense-categories'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Category[] }>('/expense-categories')
      return data.data
    },
  })

  const cashQuery = useQuery({
    queryKey: ['cash-accounts', 'expenses'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts')
      return data.data
    },
    enabled: open,
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
    enabled: open,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['expenses'] })
  const catName = (category?: Category | null) => (category ? (localizedName(category, locale)) : '—')

  const create = useMutation({
    mutationFn: async (submit: boolean) => {
      await api.post(
        '/expenses',
        {
          category_id: form.category_id,
          expense_date: form.expense_date,
          amount_minor: toMinor(form.amount, form.currency),
          tax_applied: taxEnabled && form.tax_applied,
          currency: form.currency,
          fx_rate: form.fx_rate || undefined,
          description: form.description,
          reference: form.reference || null,
          cash_account_id: form.paid_from === 'cash' ? form.cash_account_id : null,
          payee_type: form.paid_from === 'supplier' ? 'supplier' : 'none',
          payee_id: form.paid_from === 'supplier' ? form.supplier_id : null,
          submit,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setOpen(false)
      setForm(emptyForm)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const step = useMutation({
    mutationFn: async ({ expense, path }: { expense: Expense; path: 'submit' | 'approve' | 'cancel' | 'reopen' }) => {
      await api.post(`/expenses/${expense.id}/${path}`, undefined, path === 'approve' ? { headers: { 'Idempotency-Key': idempotencyKey() } } : undefined)
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const withReason = useMutation({
    mutationFn: async () => {
      if (!action) return
      await api.post(`/expenses/${action.expense.id}/${action.kind}`, { reason })
    },
    onSuccess: async () => {
      setAction(null)
      setReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const cashOptions = (cashQuery.data ?? [])
    .filter((account) => account.registered && account.is_active !== false && account.currency === form.currency)
    .map((account) => ({ value: account.cash_account_id ?? account.id, label: localizedName(account, locale) }))

  const data = expensesQuery.data

  return (
    <div>
      <PageHeader
        title={L('المصروفات', 'Expenses')}
        subtitle={L('تسجيل المصروفات واعتمادها وترحيلها', 'Record, approve and post expenses')}
        icon="receipt"
        actions={
          can('expenses.create') ? (
            <Button icon="plus" onClick={() => { setError(''); setOpen(true) }}>{L('مصروف جديد', 'New expense')}</Button>
          ) : null
        }
      />

      {error && !open && !action ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <Card>
        <div className="flex flex-wrap items-end gap-3 border-b border-border-subtle px-5 py-4">
          <Field label={t('status')}>
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
              <option value="">{L('الكل', 'All')}</option>
              {['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'reversed'].map((value) => (
                <option key={value} value={value}>{value}</option>
              ))}
            </Select>
          </Field>
        </div>

        {expensesQuery.isLoading ? <Loading /> : null}
        {expensesQuery.isError ? <ErrorState message={errorMessage(expensesQuery.error)} onRetry={() => void expensesQuery.refetch()} /> : null}
        {data && data.data.length === 0 ? <EmptyState /> : null}
        {data && data.data.length > 0 ? (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>{t('date')}</Th>
                  <Th>{L('الرقم', 'No.')}</Th>
                  <Th>{L('التصنيف', 'Category')}</Th>
                  <Th>{t('description')}</Th>
                  <Th className="text-end">{t('amount')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((expense) => (
                  <tr key={expense.id}>
                    <Td className="tabular">{formatDate(expense.expense_date, locale)}</Td>
                    <Td className="tabular">
                      {expense.number}
                      <AiBadge via={expense.created_via} />
                    </Td>
                    <Td>{catName(expense.category)}</Td>
                    <Td>
                      {expense.description}
                      {expense.payee_name ? <div className="text-small text-text-subtle">{expense.payee_name}</div> : null}
                      {expense.rejection_reason ? <div className="text-small text-danger-text">{expense.rejection_reason}</div> : null}
                    </Td>
                    <Td className="text-end">
                      <Money amountMinor={expense.amount_minor} currency={expense.currency} />
                      {expense.tax_minor > 0 ? (
                        <div className="text-small text-text-subtle">
                          {L('ضريبة', 'Tax')} <Money amountMinor={expense.tax_minor} currency={expense.currency} />
                        </div>
                      ) : null}
                    </Td>
                    <Td>
                      <Badge tone={statusTone(expense.status)}>{statusLabel(expense.status, locale)}</Badge>
                    </Td>
                    <Td className="text-end">
                      <div className="flex flex-wrap justify-end gap-1">
                        <DocumentsButton type="expense" recordId={expense.id} />
                        {expense.status === 'draft' && can('expenses.create') ? (
                          <Button variant="ghost" onClick={() => step.mutate({ expense, path: 'submit' })}>{L('إرسال', 'Submit')}</Button>
                        ) : null}
                        {expense.status === 'rejected' && can('expenses.create') ? (
                          <Button variant="ghost" onClick={() => step.mutate({ expense, path: 'reopen' })}>{L('إعادة فتح', 'Reopen')}</Button>
                        ) : null}
                        {expense.status === 'submitted' && can('expenses.approve') ? (
                          <>
                            <Button variant="ghost" onClick={() => step.mutate({ expense, path: 'approve' })}>{L('اعتماد', 'Approve')}</Button>
                            <Button variant="ghost" onClick={() => { setError(''); setAction({ expense, kind: 'reject' }) }}>{L('رفض', 'Reject')}</Button>
                          </>
                        ) : null}
                        {['draft', 'submitted', 'rejected'].includes(expense.status) && can('expenses.create') ? (
                          <Button variant="ghost" onClick={() => step.mutate({ expense, path: 'cancel' })}>{L('إلغاء', 'Cancel')}</Button>
                        ) : null}
                        {expense.status === 'approved' && can('expenses.approve') ? (
                          <Button variant="ghost" onClick={() => { setError(''); setAction({ expense, kind: 'reverse' }) }}>{L('عكس', 'Reverse')}</Button>
                        ) : null}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onChange={setPage} />
          </>
        ) : null}
      </Card>

      <Modal
        open={open}
        title={L('مصروف جديد', 'New expense')}
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>{t('cancel')}</Button>
            <Button variant="secondary" onClick={() => create.mutate(false)} disabled={create.isPending}>{L('حفظ كمسودة', 'Save draft')}</Button>
            <Button onClick={() => create.mutate(true)} disabled={create.isPending}>{L('حفظ وإرسال', 'Save & submit')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('التصنيف', 'Category')} required>
            <SearchableSelect
              value={form.category_id}
              onChange={(value) => setForm({ ...form, category_id: value })}
              placeholder={L('اختر', 'Select')}
              options={(categoriesQuery.data ?? []).map((category) => ({ value: category.id, label: catName(category), hint: category.account_code }))}
            />
          </Field>
          <Field label={t('date')} required>
            <Input type="date" value={form.expense_date} onChange={(event) => setForm({ ...form, expense_date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={t('currency')} required>
            <Select value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value, cash_account_id: '' })}>
              {(currenciesQuery.data ?? [{ code: 'EGP', is_active: true }]).filter((row) => row.is_active).map((row) => (
                <option key={row.code} value={row.code}>{row.code}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('سعر الصرف (للعملات الأجنبية)', 'Exchange rate (foreign currency)')}>
            <Input value={form.fx_rate} onChange={(event) => setForm({ ...form, fx_rate: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={`${L('المبلغ', 'Amount')} (${form.currency})`} required>
            <Input value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          {taxEnabled ? (
            <Field label={L('تطبيق ضريبة المدخلات', 'Apply input tax')} hint={L('النسبة ونوع الاحتساب من الإعدادات.', 'Rate and inclusive/exclusive come from settings.')}>
              <input type="checkbox" checked={form.tax_applied} onChange={(event) => setForm({ ...form, tax_applied: event.target.checked })} />
            </Field>
          ) : null}
          <Field label={L('الدفع', 'Paid')} required>
            <Select value={form.paid_from} onChange={(event) => setForm({ ...form, paid_from: event.target.value as 'cash' | 'supplier' })}>
              <option value="cash">{L('من خزينة / بنك', 'From cash / bank')}</option>
              <option value="supplier">{L('آجل على مورد', 'On account to a supplier')}</option>
            </Select>
          </Field>
          {form.paid_from === 'cash' ? (
            <Field label={L('حساب الدفع', 'Payment account')} required>
              <SearchableSelect value={form.cash_account_id} onChange={(value) => setForm({ ...form, cash_account_id: value })} options={cashOptions} placeholder={L('اختر', 'Select')} />
            </Field>
          ) : (
            <Field label={L('المورد', 'Supplier')} required>
              <PartyPicker kind="supplier" value={form.supplier_id} onChange={(value) => setForm({ ...form, supplier_id: value })} placeholder={L('اختر', 'Select')} />
            </Field>
          )}
          <Field label={t('description')} required>
            <Input value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
          </Field>
          <Field label={L('مرجع / رقم فاتورة', 'Reference / invoice no.')}>
            <Input value={form.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={action !== null}
        title={action?.kind === 'reject' ? L('رفض المصروف', 'Reject expense') : L('عكس المصروف', 'Reverse expense')}
        onClose={() => setAction(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => withReason.mutate()} disabled={withReason.isPending || reason.trim() === ''}>
              {action?.kind === 'reject' ? L('رفض', 'Reject') : L('عكس', 'Reverse')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-3 text-medium text-text-subtle">{action?.expense.number}</p>
        <Field label={L('السبب', 'Reason')} required>
          <Input value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
    </div>
  )
}
