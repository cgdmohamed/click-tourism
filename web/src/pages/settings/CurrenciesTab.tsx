import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../auth/AuthProvider'
import { useI18n } from '../../i18n'
import { api, errorMessage } from '../../lib/api'
import { isValidCurrencyCode, normalizeCurrencyCode, type CurrencyDetail, type CurrencyRow } from '../../lib/currencies'
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, IconButton, Input, Loading, Modal, Select, Table, Td, Th } from '../../components/ui'

type FormState = { code: string; name: string; symbol: string; exponent: string }
const emptyForm: FormState = { code: '', name: '', symbol: '', exponent: '2' }

/** Currencies master data: list, add/edit modal, deactivate/activate and delete (when unused), all behind settings.update. */
export function CurrenciesTab() {
  const { locale, t } = useI18n()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const ar = locale === 'ar'
  const L = (a: string, e: string) => (ar ? a : e)
  const canEdit = can('settings.update')

  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [editing, setEditing] = useState<CurrencyRow | 'new' | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [confirm, setConfirm] = useState<{ kind: 'delete' | 'deactivate'; currency: CurrencyRow } | null>(null)
  const [confirmError, setConfirmError] = useState('')

  const baseQuery = useQuery({
    queryKey: ['feature-flags'],
    queryFn: async () => {
      const { data } = await api.get<{ data: { base_currency: string } }>('/feature-flags')
      return data.data
    },
    staleTime: 60_000,
  })
  const base = baseQuery.data?.base_currency

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
  })

  const detailQuery = useQuery({
    queryKey: ['currency-detail', editing && editing !== 'new' ? editing.code : null],
    enabled: editing !== null && editing !== 'new' && can('settings.view'),
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyDetail }>(`/currencies/${(editing as CurrencyRow).code}`)
      return data.data
    },
  })
  const inUse = editing !== null && editing !== 'new' ? (detailQuery.data?.in_use ?? true) : false

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['currencies'] })
    await queryClient.invalidateQueries({ queryKey: ['currency-detail'] })
  }

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name.trim(),
        symbol: form.symbol.trim() === '' ? null : form.symbol.trim(),
        exponent: Number(form.exponent),
      }
      if (editing === 'new') return api.post('/currencies', { code: form.code, ...payload })
      return api.patch(`/currencies/${(editing as CurrencyRow).code}`, inUse ? { name: payload.name, symbol: payload.symbol } : payload)
    },
    onSuccess: async () => {
      setEditing(null)
      setFormError('')
      await refresh()
    },
    onError: (cause) => setFormError(errorMessage(cause)),
  })

  const toggle = useMutation({
    mutationFn: async (currency: CurrencyRow) => api.patch(`/currencies/${currency.code}`, { is_active: !currency.is_active }),
    onSuccess: async () => {
      setConfirm(null)
      setConfirmError('')
      setError('')
      await refresh()
    },
    onError: (cause) => {
      const message = errorMessage(cause)
      if (confirm) setConfirmError(message)
      else setError(message)
    },
  })

  const remove = useMutation({
    mutationFn: async (currency: CurrencyRow) => api.delete(`/currencies/${currency.code}`),
    onSuccess: async () => {
      setConfirm(null)
      setConfirmError('')
      setError('')
      await refresh()
    },
    onError: (cause) => setConfirmError(errorMessage(cause)),
  })

  const openNew = () => {
    setForm(emptyForm)
    setFormError('')
    setEditing('new')
  }
  const openEdit = (currency: CurrencyRow) => {
    setForm({ code: currency.code, name: currency.name, symbol: currency.symbol ?? '', exponent: String(currency.exponent) })
    setFormError('')
    setEditing(currency)
  }

  const formValid = form.name.trim() !== '' && (editing !== 'new' || isValidCurrencyCode(form.code))

  return (
    <Card>
      <CardHeader
        title={L('العملات', 'Currencies')}
        subtitle={L(
          'العملات الموقوفة لا تظهر في المستندات الجديدة وتبقى على المستندات القديمة.',
          'Deactivated currencies disappear from pickers for new documents but stay on existing ones.',
        )}
        actions={
          canEdit ? (
            <Button icon="plus" onClick={openNew}>
              {L('عملة جديدة', 'New currency')}
            </Button>
          ) : null
        }
      />
      {error ? (
        <p className="m-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text" role="alert">
          {error}
        </p>
      ) : null}
      {currenciesQuery.isLoading ? <Loading /> : null}
      {currenciesQuery.isError ? <ErrorState message={errorMessage(currenciesQuery.error)} onRetry={() => void currenciesQuery.refetch()} /> : null}
      {currenciesQuery.data && currenciesQuery.data.length === 0 ? <EmptyState /> : null}
      {currenciesQuery.data && currenciesQuery.data.length > 0 ? (
        <Table>
          <thead>
            <tr>
              <Th>{t('code')}</Th>
              <Th>{t('name')}</Th>
              <Th>{L('الرمز', 'Symbol')}</Th>
              <Th>{L('الخانات العشرية', 'Decimals')}</Th>
              <Th>{t('status')}</Th>
              {canEdit ? <Th>{L('إجراءات', 'Actions')}</Th> : null}
            </tr>
          </thead>
          <tbody>
            {currenciesQuery.data.map((currency) => {
              const isBase = currency.code === base
              return (
                <tr key={currency.code}>
                  <Td className="tabular font-medium">
                    {currency.code} {isBase ? <Badge tone="brand">{L('أساسية', 'Base')}</Badge> : null}
                  </Td>
                  <Td>{currency.name}</Td>
                  <Td>{currency.symbol ?? '—'}</Td>
                  <Td className="tabular">{currency.exponent}</Td>
                  <Td>
                    <Badge tone={currency.is_active ? 'positive' : 'neutral'}>{currency.is_active ? L('نشط', 'Active') : L('موقوف', 'Inactive')}</Badge>
                  </Td>
                  {canEdit ? (
                    <Td>
                      <div className="flex items-center gap-1">
                        <IconButton icon="edit" label={`${t('edit')} ${currency.code}`} onClick={() => openEdit(currency)} />
                        {currency.is_active ? (
                          <Button
                            variant="ghost"
                            disabled={isBase}
                            title={isBase ? L('لا يمكن إيقاف العملة الأساسية', 'The base currency cannot be deactivated') : undefined}
                            aria-label={`${L('إيقاف', 'Deactivate')} ${currency.code}`}
                            onClick={() => {
                              setConfirmError('')
                              setConfirm({ kind: 'deactivate', currency })
                            }}
                          >
                            {L('إيقاف', 'Deactivate')}
                          </Button>
                        ) : (
                          <Button
                            variant="ghost"
                            disabled={toggle.isPending}
                            aria-label={`${L('تفعيل', 'Activate')} ${currency.code}`}
                            onClick={() => toggle.mutate(currency)}
                          >
                            {L('تفعيل', 'Activate')}
                          </Button>
                        )}
                        <IconButton
                          icon="trash"
                          variant="ghost"
                          disabled={isBase}
                          label={isBase ? L('لا يمكن حذف العملة الأساسية', 'The base currency cannot be deleted') : `${L('حذف', 'Delete')} ${currency.code}`}
                          onClick={() => {
                            setConfirmError('')
                            setConfirm({ kind: 'delete', currency })
                          }}
                        />
                      </div>
                    </Td>
                  ) : null}
                </tr>
              )
            })}
          </tbody>
        </Table>
      ) : null}

      <Modal
        open={editing !== null}
        title={editing === 'new' ? L('عملة جديدة', 'New currency') : `${L('تعديل العملة', 'Edit currency')} ${form.code}`}
        onClose={() => setEditing(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              {t('cancel')}
            </Button>
            <Button disabled={save.isPending || !formValid} onClick={() => save.mutate()}>
              {save.isPending ? t('saving') : t('save')}
            </Button>
          </>
        }
      >
        {formError ? (
          <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text" role="alert">
            {formError}
          </p>
        ) : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t('code')}
            required
            hint={editing === 'new' ? L('3 أحرف إنجليزية، لا يمكن تغييره لاحقاً.', '3 letters, cannot be changed later.') : L('رمز العملة لا يتغير.', 'The code cannot be changed.')}
          >
            <Input
              value={form.code}
              disabled={editing !== 'new'}
              onChange={(event) => setForm({ ...form, code: normalizeCurrencyCode(event.target.value) })}
              dir="ltr"
              maxLength={3}
              autoComplete="off"
            />
          </Field>
          <Field label={L('الخانات العشرية', 'Decimals')} hint={inUse ? L('مقفلة: العملة مستخدمة في سجلات قائمة.', 'Locked: the currency is already used by existing records.') : undefined}>
            <Select value={form.exponent} disabled={inUse} onChange={(event) => setForm({ ...form, exponent: event.target.value })}>
              {[0, 1, 2, 3].map((value) => (
                <option key={value} value={String(value)}>
                  {value}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t('name')} required>
            <Input value={form.name} maxLength={100} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label={L('الرمز المختصر', 'Symbol')}>
            <Input value={form.symbol} maxLength={10} onChange={(event) => setForm({ ...form, symbol: event.target.value })} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={confirm !== null}
        title={
          confirm?.kind === 'delete'
            ? `${L('حذف العملة', 'Delete currency')} ${confirm.currency.code}`
            : `${L('إيقاف العملة', 'Deactivate currency')} ${confirm?.currency.code ?? ''}`
        }
        onClose={() => setConfirm(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirm(null)}>
              {t('cancel')}
            </Button>
            <Button
              variant={confirm?.kind === 'delete' ? 'danger' : 'primary'}
              disabled={toggle.isPending || remove.isPending}
              onClick={() => {
                if (!confirm) return
                if (confirm.kind === 'delete') remove.mutate(confirm.currency)
                else toggle.mutate(confirm.currency)
              }}
            >
              {confirm?.kind === 'delete' ? L('حذف', 'Delete') : L('إيقاف', 'Deactivate')}
            </Button>
          </>
        }
      >
        {confirmError ? (
          <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text" role="alert">
            {confirmError}
          </p>
        ) : null}
        <p className="text-medium text-text-default">
          {confirm?.kind === 'delete'
            ? L(
                'سيتم حذف العملة نهائياً. لا يمكن حذف عملة مستخدمة في أي سجل؛ أوقفها بدلاً من ذلك.',
                'The currency will be permanently deleted. A currency used by any record cannot be deleted; deactivate it instead.',
              )
            : L(
                'لن تظهر هذه العملة في المستندات الجديدة، وستبقى على المستندات القديمة. يمكنك تفعيلها لاحقاً.',
                'This currency will no longer be offered for new documents but stays on existing ones. You can activate it again later.',
              )}
        </p>
      </Modal>
    </Card>
  )
}
