import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import type { PaymentMethod } from '../lib/types'
import {
  Badge, Button, Card, EmptyState, ErrorState, Field, Input, Loading,
  Modal, PageHeader, SearchableSelect, Select, Table, Td, Th,
} from '../components/ui'

type Account = { id: string; name_ar: string; name_en: string; currency: string }
type Kind = 'cash' | 'bank_transfer' | 'card' | 'wallet' | 'other'

const blank = {
  name_ar: '',
  name_en: '',
  type: 'bank_transfer' as Kind,
  default_cash_account_id: '',
  provider_name: '',
  account_name: '',
  account_reference: '',
  iban: '',
  instructions: '',
  is_active: true,
}

export default function PaymentMethods() {
  const { locale } = useI18n()
  const { can } = useAuth()
  const L = (ar: string, en: string) => locale === 'ar' ? ar : en
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editId, setEditId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ ...blank })

  const methodsQuery = useQuery({
    queryKey: ['payment-methods', 'all'],
    queryFn: async () => {
      const { data } = await api.get<{ data: PaymentMethod[] }>('/payment-methods', {
        params: { include_inactive: true },
      })
      return data.data
    },
  })
  const cashQuery = useQuery({
    queryKey: ['cash-accounts'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Account[] }>('/cash-accounts')
      return data.data
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        default_cash_account_id: form.default_cash_account_id || null,
        provider_name: form.provider_name || null,
        account_name: form.account_name || null,
        account_reference: form.account_reference || null,
        iban: form.iban || null,
        instructions: form.instructions || null,
      }
      if (editId) {
        await api.patch('/payment-methods/' + editId, payload)
      } else {
        await api.post('/payment-methods', payload)
      }
    },
    onSuccess: async () => {
      setOpen(false)
      setEditId(null)
      setForm({ ...blank })
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const startNew = () => {
    setForm({ ...blank })
    setEditId(null)
    setError('')
    setOpen(true)
  }

  const startEdit = (item: PaymentMethod) => {
    setEditId(item.id)
    setForm({
      name_ar: item.name_ar,
      name_en: item.name_en,
      type: item.type as Kind,
      default_cash_account_id: item.default_cash_account_id ?? '',
      provider_name: item.provider_name ?? '',
      account_name: item.account_name ?? '',
      account_reference: item.account_reference ?? '',
      iban: item.iban ?? '',
      instructions: item.instructions ?? '',
      is_active: item.is_active,
    })
    setError('')
    setOpen(true)
  }

  const methods = methodsQuery.data ?? []
  const kinds: Array<{ value: Kind; ar: string; en: string }> = [
    { value: 'cash', ar: 'نقدي', en: 'Cash' },
    { value: 'bank_transfer', ar: 'تحويل بنكي', en: 'Bank transfer' },
    { value: 'card', ar: 'بطاقة / POS', en: 'Card / POS' },
    { value: 'wallet', ar: 'محفظة إلكترونية', en: 'Wallet' },
    { value: 'other', ar: 'أخرى', en: 'Other' },
  ]

  return (
    <div>
      <PageHeader
        title={L('طرق الدفع', 'Payment methods')}
        subtitle={L('أضف طرق التحصيل واربط كل طريقة بحساب خزينة أو بنك، مع تفاصيل استلام الدفعة', 'Configure payment channels and their default bank or cash accounts')}
        icon="wallet"
        actions={can('payments.create') ? <Button icon="plus" onClick={startNew}>{L('إضافة طريقة دفع', 'Add payment method')}</Button> : null}
      />
      <Card>
        {methodsQuery.isLoading ? <Loading /> : null}
        {methodsQuery.isError ? <ErrorState message={errorMessage(methodsQuery.error)} onRetry={() => void methodsQuery.refetch()} /> : null}
        {!methodsQuery.isLoading && methods.length === 0 ? <EmptyState /> : null}
        {methods.length > 0 ? (
          <Table>
            <thead><tr>
              <Th>{L('الطريقة', 'Method')}</Th>
              <Th>{L('النوع', 'Type')}</Th>
              <Th>{L('الجهة / رقم الحساب', 'Provider / Reference')}</Th>
              <Th>{L('حساب الترحيل', 'Posting account')}</Th>
              <Th>{L('الحالة', 'Status')}</Th>
              <Th />
            </tr></thead>
            <tbody>{methods.map((method) => (
              <tr key={method.id}>
                <Td>{locale === 'ar' ? method.name_ar : method.name_en}</Td>
                <Td>{kinds.find((k) => k.value === method.type)?.[locale === 'ar' ? 'ar' : 'en'] ?? method.type}</Td>
                <Td>{method.provider_name ?? '—'} {method.account_reference ?? method.iban ?? ''}</Td>
                <Td>{method.default_cash_account ? (locale === 'ar' ? method.default_cash_account.name_ar : method.default_cash_account.name_en) : '—'}</Td>
                <Td><Badge tone={method.is_active ? 'positive' : 'neutral'}>{method.is_active ? L('نشطة', 'Active') : L('معطلة', 'Inactive')}</Badge></Td>
                <Td>{can('payments.create') ? <Button variant="ghost" onClick={() => startEdit(method)}>{L('تعديل', 'Edit')}</Button> : null}</Td>
              </tr>
            ))}</tbody>
          </Table>
        ) : null}
      </Card>
      <Modal
        open={open}
        title={editId ? L('تعديل طريقة الدفع', 'Edit payment method') : L('إضافة طريقة دفع', 'Add payment method')}
        onClose={() => setOpen(false)}
        footer={<>
          <Button variant="secondary" onClick={() => setOpen(false)}>{L('إلغاء', 'Cancel')}</Button>
          <Button disabled={save.isPending || !form.name_ar || !form.name_en} onClick={() => save.mutate()}>
            {save.isPending ? L('جار الحفظ', 'Saving') : L('حفظ', 'Save')}
          </Button>
        </>}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <p className="sm:col-span-2 text-small text-text-subtle">{editId
            ? L('كود طريقة الدفع يُنشأ تلقائيًا ولا يمكن تعديله.', 'Payment method code is generated automatically and cannot be changed.')
            : L('سيُنشئ النظام كودًا فريدًا للطريقة تلقائيًا عند الحفظ.', 'A unique payment method code is generated automatically when saved.')}</p>
          <Field label={L('النوع', 'Type')} required>
            <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as Kind })}>
              {kinds.map((kind) => <option value={kind.value} key={kind.value}>{locale === 'ar' ? kind.ar : kind.en}</option>)}
            </Select>
          </Field>
          <Field label={L('الاسم بالعربي', 'Arabic name')} required>
            <Input value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} />
          </Field>
          <Field label={L('الاسم بالإنجليزي', 'English name')} required>
            <Input value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} />
          </Field>
          <Field label={L('حساب الخزينة أو البنك الافتراضي', 'Default cash / bank account')}>
            <SearchableSelect
              value={form.default_cash_account_id}
              onChange={(value) => setForm({ ...form, default_cash_account_id: value })}
              placeholder={L('اختر حسابًا', 'Select an account')}
              options={(cashQuery.data ?? []).map((a) => ({
                value: a.id, label: locale === 'ar' ? a.name_ar : a.name_en, hint: a.currency,
              }))}
            />
          </Field>
          <Field label={L('البنك أو مزود الخدمة', 'Bank or provider')}>
            <Input value={form.provider_name} onChange={(e) => setForm({ ...form, provider_name: e.target.value })} />
          </Field>
          <Field label={L('اسم صاحب الحساب', 'Account holder')}>
            <Input value={form.account_name} onChange={(e) => setForm({ ...form, account_name: e.target.value })} />
          </Field>
          <Field label={L('رقم الحساب أو المحفظة', 'Account / wallet reference')}>
            <Input dir="ltr" value={form.account_reference} onChange={(e) => setForm({ ...form, account_reference: e.target.value })} />
          </Field>
          <Field label="IBAN">
            <Input dir="ltr" value={form.iban} onChange={(e) => setForm({ ...form, iban: e.target.value })} />
          </Field>
          <Field label={L('تعليمات الدفع', 'Payment instructions')}>
            <Input value={form.instructions} onChange={(e) => setForm({ ...form, instructions: e.target.value })} />
          </Field>
          <Field label={L('الحالة', 'Status')}>
            <Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
              <option value="1">{L('نشطة', 'Active')}</option>
              <option value="0">{L('معطلة', 'Inactive')}</option>
            </Select>
          </Field>
        </div>
        <p className="mt-3 text-small text-text-subtle">
          {L('لا تُدخل كلمات مرور أو أرقام بطاقات كاملة أو CVV. البيانات هنا مخصصة لتعريف طريقة التحصيل فقط.', 'Never enter passwords, full card numbers or CVV. These fields identify the payment destination only.')}
        </p>
        {error ? <p className="mt-3 text-small text-danger-text">{error}</p> : null}
      </Modal>
    </div>
  )
}
