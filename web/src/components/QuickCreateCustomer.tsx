import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage } from '../lib/api'
import { Button, Field, Input, Modal, Select } from './ui'

type CustomerResult = { id: string; name_ar: string; name_en: string }

/** Small customer editor inside the booking workflow; keeps the booking draft intact. */
export default function QuickCreateCustomer({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: (customer: CustomerResult) => void
}) {
  const { locale } = useI18n()
  const { activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => locale === 'ar' ? ar : en
  const [form, setForm] = useState({ type: 'individual', name: '', phone: '', email: '' })
  const [error, setError] = useState('')
  const create = useMutation({
    mutationFn: async () => {
      if (!activeBranch) throw new Error(L('اختر فرعًا أولاً', 'Select a branch first'))
      const { data } = await api.post<{ data: CustomerResult }>('/customers', {
        branch_id: activeBranch.id,
        type: form.type,
        name_ar: form.name.trim(),
        name_en: form.name.trim(),
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
      })
      return data.data
    },
    onSuccess: async (customer) => {
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
      setError('')
      setForm({ type: 'individual', name: '', phone: '', email: '' })
      onCreated(customer)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })
  return (
    <Modal
      open={open}
      title={L('إضافة عميل أثناء الحجز', 'Add customer without leaving booking')}
      onClose={onClose}
      footer={<>
        <Button variant="secondary" onClick={onClose}>{L('إلغاء', 'Cancel')}</Button>
        <Button disabled={create.isPending || !form.name.trim()} onClick={() => create.mutate()}>
          {create.isPending ? L('جارِ الحفظ', 'Saving') : L('حفظ واختيار العميل', 'Save and select')}
        </Button>
      </>}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={L('النوع', 'Type')}>
          <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            <option value="individual">{L('فرد', 'Individual')}</option>
            <option value="company">{L('شركة', 'Company')}</option>
          </Select>
        </Field>
        <Field label={L('اسم العميل', 'Customer name')} required>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field label={L('رقم الهاتف', 'Phone')}>
          <Input dir="ltr" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
        </Field>
        <Field label={L('البريد الإلكتروني', 'Email')}>
          <Input type="email" dir="ltr" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        </Field>
      </div>
      <p className="mt-3 text-small text-text-subtle">{L('يمكن استكمال البيانات الضريبية وحد الائتمان لاحقًا من ملف العميل.', 'Tax details and credit limit can be completed later in the customer profile.')}</p>
      {error ? <p className="mt-2 text-small text-danger-text">{error}</p> : null}
    </Modal>
  )
}
