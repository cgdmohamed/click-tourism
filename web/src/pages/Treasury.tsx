import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, toMinor, todayISO } from '../lib/format'
import type { Paginated } from '../lib/types'
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

type CashAccount = {
  id: string
  cash_account_id?: string
  code: string
  name_ar: string
  name_en: string
  kind?: 'cash' | 'bank'
  type?: string
  currency: string
  bank_name?: string | null
  account_last4?: string | null
  is_active?: boolean
  registered: boolean
  balance_minor?: number | null
  balance_currency?: string | null
}

type Transfer = {
  id: string
  number: string
  transfer_date: string
  from: CashAccount | null
  to: CashAccount | null
  from_currency: string
  from_amount_minor: number
  to_currency: string
  to_amount_minor: number
  fee_minor: number
  memo: string | null
  status: 'posted' | 'reversed'
}

type CurrencyRow = { code: string; is_active: boolean }

type Tab = 'accounts' | 'transfers'

const emptyAccount = { kind: 'cash', name_ar: '', name_en: '', currency: 'EGP', account_code: '', bank_name: '', account_last4: '' }
const emptyTransfer = { from: '', to: '', date: todayISO(), amount: '', received: '', fromRate: '', fee: '', memo: '' }

export default function Treasury() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [tab, setTab] = useState<Tab>('accounts')
  const [page, setPage] = useState(1)
  const [error, setError] = useState('')
  const [accountOpen, setAccountOpen] = useState(false)
  const [accountForm, setAccountForm] = useState(emptyAccount)
  const [transferOpen, setTransferOpen] = useState(false)
  const [transferForm, setTransferForm] = useState(emptyTransfer)
  const [reversing, setReversing] = useState<Transfer | null>(null)
  const [reason, setReason] = useState('')

  const accountsQuery = useQuery({
    queryKey: ['cash-accounts', 'treasury', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: CashAccount[] }>('/cash-accounts', { params: { include_inactive: 1 } })
      return data.data
    },
  })

  const transfersQuery = useQuery({
    queryKey: ['transfers', page, activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Transfer>>('/transfers', { params: { page, per_page: 20 } })
      return data
    },
    enabled: can('transfers.view'),
  })

  const currenciesQuery = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CurrencyRow[] }>('/currencies')
      return data.data
    },
  })

  const accounts = useMemo(() => accountsQuery.data ?? [], [accountsQuery.data])
  const registered = useMemo(() => accounts.filter((account) => account.registered && account.is_active !== false), [accounts])
  const fromAccount = registered.find((account) => account.cash_account_id === transferForm.from)
  const toAccount = registered.find((account) => account.cash_account_id === transferForm.to)
  const crossCurrency = Boolean(fromAccount && toAccount && fromAccount.currency !== toAccount.currency)
  const name = (account?: CashAccount | null) => (account ? (locale === 'ar' ? account.name_ar : account.name_en) : '—')

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['cash-accounts'] })
    await queryClient.invalidateQueries({ queryKey: ['transfers'] })
  }

  const createAccount = useMutation({
    mutationFn: async () => {
      await api.post('/cash-accounts', {
        kind: accountForm.kind,
        name_ar: accountForm.name_ar,
        name_en: accountForm.name_en,
        currency: accountForm.currency,
        account_code: accountForm.account_code,
        bank_name: accountForm.bank_name || null,
        account_last4: accountForm.account_last4 || null,
      })
    },
    onSuccess: async () => {
      setAccountOpen(false)
      setAccountForm(emptyAccount)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const toggleActive = useMutation({
    mutationFn: async (account: CashAccount) => {
      await api.patch(`/cash-accounts/${account.cash_account_id}`, { is_active: !(account.is_active ?? true) })
    },
    onSuccess: refresh,
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createTransfer = useMutation({
    mutationFn: async () => {
      if (!fromAccount || !toAccount) throw new Error(L('اختر الحسابين', 'Choose both accounts'))
      await api.post(
        '/transfers',
        {
          from_cash_account_id: transferForm.from,
          to_cash_account_id: transferForm.to,
          transfer_date: transferForm.date,
          amount_minor: toMinor(transferForm.amount, fromAccount.currency),
          to_amount_minor: crossCurrency ? toMinor(transferForm.received, toAccount.currency) : undefined,
          from_fx_rate: transferForm.fromRate || undefined,
          fee_minor: transferForm.fee ? toMinor(transferForm.fee, fromAccount.currency) : 0,
          memo: transferForm.memo || null,
        },
        { headers: { 'Idempotency-Key': idempotencyKey() } },
      )
    },
    onSuccess: async () => {
      setTransferOpen(false)
      setTransferForm(emptyTransfer)
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const reverse = useMutation({
    mutationFn: async () => {
      if (!reversing) return
      await api.post(`/transfers/${reversing.id}/reverse`, { reason })
    },
    onSuccess: async () => {
      setReversing(null)
      setReason('')
      setError('')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const options = registered.map((account) => ({
    value: account.cash_account_id ?? account.id,
    label: `${name(account)} (${account.currency})`,
  }))

  const tabButton = (key: Tab, label: string) => (
    <button
      className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
        tab === key ? 'border-border-selected bg-surface-selected text-text-default' : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
      }`}
      onClick={() => setTab(key)}
    >
      {label}
    </button>
  )

  return (
    <div>
      <PageHeader
        title={L('الخزينة والبنوك', 'Cash & bank')}
        subtitle={L('الحسابات النقدية والبنكية والتحويلات بينها', 'Cash and bank accounts and transfers between them')}
        icon="wallet"
        actions={
          <>
            {can('cash_accounts.manage') ? (
              <Button variant="secondary" icon="plus" onClick={() => { setError(''); setAccountOpen(true) }}>
                {L('حساب جديد', 'New account')}
              </Button>
            ) : null}
            {can('transfers.create') ? (
              <Button icon="plus" onClick={() => { setError(''); setTransferOpen(true) }}>{L('تحويل جديد', 'New transfer')}</Button>
            ) : null}
          </>
        }
      />

      {error && !accountOpen && !transferOpen && !reversing ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}

      <div className="mb-4 flex gap-2">
        {tabButton('accounts', L('الحسابات', 'Accounts'))}
        {can('transfers.view') ? tabButton('transfers', L('التحويلات', 'Transfers')) : null}
      </div>

      {tab === 'accounts' ? (
        <Card>
          {accountsQuery.isLoading ? <Loading /> : null}
          {accountsQuery.isError ? <ErrorState message={errorMessage(accountsQuery.error)} onRetry={() => void accountsQuery.refetch()} /> : null}
          {accounts.length === 0 && !accountsQuery.isLoading ? <EmptyState /> : null}
          {accounts.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{t('code')}</Th>
                  <Th>{t('name')}</Th>
                  <Th>{t('type')}</Th>
                  <Th>{t('currency')}</Th>
                  <Th className="text-end">{L('الرصيد', 'Balance')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {accounts.map((account) => (
                  <tr key={account.id}>
                    <Td className="tabular">{account.code}</Td>
                    <Td>
                      {name(account)}
                      {account.account_last4 ? <span className="tabular text-text-subtle"> ····{account.account_last4}</span> : null}
                    </Td>
                    <Td>{account.kind ?? account.type ?? '—'}</Td>
                    <Td>{account.currency}</Td>
                    <Td className="text-end">
                      {account.balance_minor !== null && account.balance_minor !== undefined ? (
                        <Money amountMinor={account.balance_minor} currency={account.balance_currency ?? account.currency} />
                      ) : (
                        '—'
                      )}
                    </Td>
                    <Td>
                      {account.registered ? (
                        <Badge tone={account.is_active === false ? 'neutral' : 'positive'}>
                          {account.is_active === false ? L('موقوف', 'Inactive') : L('نشط', 'Active')}
                        </Badge>
                      ) : (
                        <Badge tone="warning">{L('غير مسجل', 'Not registered')}</Badge>
                      )}
                    </Td>
                    <Td className="text-end">
                      {account.registered && can('cash_accounts.manage') ? (
                        <Button variant="ghost" onClick={() => toggleActive.mutate(account)}>
                          {account.is_active === false ? L('تفعيل', 'Activate') : L('إيقاف', 'Deactivate')}
                        </Button>
                      ) : null}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      ) : (
        <Card>
          {transfersQuery.isLoading ? <Loading /> : null}
          {transfersQuery.isError ? <ErrorState message={errorMessage(transfersQuery.error)} onRetry={() => void transfersQuery.refetch()} /> : null}
          {transfersQuery.data && transfersQuery.data.data.length === 0 ? <EmptyState /> : null}
          {transfersQuery.data && transfersQuery.data.data.length > 0 ? (
            <>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('date')}</Th>
                    <Th>{L('الرقم', 'No.')}</Th>
                    <Th>{L('من', 'From')}</Th>
                    <Th>{L('إلى', 'To')}</Th>
                    <Th className="text-end">{t('amount')}</Th>
                    <Th className="text-end">{L('الرسوم', 'Fee')}</Th>
                    <Th>{t('status')}</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {transfersQuery.data.data.map((transfer) => (
                    <tr key={transfer.id}>
                      <Td className="tabular">{formatDate(transfer.transfer_date, locale)}</Td>
                      <Td className="tabular">{transfer.number}</Td>
                      <Td>{name(transfer.from)}</Td>
                      <Td>{name(transfer.to)}</Td>
                      <Td className="text-end">
                        <Money amountMinor={transfer.from_amount_minor} currency={transfer.from_currency} />
                        {transfer.from_currency !== transfer.to_currency ? (
                          <div className="text-small text-text-subtle">
                            → <Money amountMinor={transfer.to_amount_minor} currency={transfer.to_currency} />
                          </div>
                        ) : null}
                      </Td>
                      <Td className="text-end">
                        {transfer.fee_minor > 0 ? <Money amountMinor={transfer.fee_minor} currency={transfer.from_currency} /> : '—'}
                      </Td>
                      <Td>
                        <Badge tone={statusTone(transfer.status)}>{transfer.status}</Badge>
                      </Td>
                      <Td className="text-end">
                        {transfer.status === 'posted' && can('transfers.reverse') ? (
                          <Button variant="ghost" onClick={() => { setError(''); setReversing(transfer) }}>
                            {L('عكس', 'Reverse')}
                          </Button>
                        ) : null}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              <Pagination page={transfersQuery.data.meta.current_page} lastPage={transfersQuery.data.meta.last_page} onChange={setPage} />
            </>
          ) : null}
        </Card>
      )}

      <Modal
        open={accountOpen}
        title={L('حساب نقدي / بنكي جديد', 'New cash / bank account')}
        onClose={() => setAccountOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAccountOpen(false)}>{t('cancel')}</Button>
            <Button onClick={() => createAccount.mutate()} disabled={createAccount.isPending}>{t('save')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('type')} required>
            <Select value={accountForm.kind} onChange={(event) => setAccountForm({ ...accountForm, kind: event.target.value })}>
              <option value="cash">{L('خزينة', 'Cash')}</option>
              <option value="bank">{L('بنك', 'Bank')}</option>
            </Select>
          </Field>
          <Field label={t('currency')} required>
            <Select value={accountForm.currency} onChange={(event) => setAccountForm({ ...accountForm, currency: event.target.value })}>
              {(currenciesQuery.data ?? []).filter((row) => row.is_active).map((row) => (
                <option key={row.code} value={row.code}>{row.code}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('الاسم (عربي)', 'Name (Arabic)')} required>
            <Input value={accountForm.name_ar} onChange={(event) => setAccountForm({ ...accountForm, name_ar: event.target.value })} />
          </Field>
          <Field label={L('الاسم (إنجليزي)', 'Name (English)')} required>
            <Input value={accountForm.name_en} onChange={(event) => setAccountForm({ ...accountForm, name_en: event.target.value })} dir="ltr" />
          </Field>
          <Field
            label={L('كود حساب الأستاذ', 'GL account code')}
            required
            hint={L('كود جديد ينشئ حسابًا، أو كود حساب أصل موجود', 'A new code creates the account; or enter an existing asset account code')}
          >
            <Input value={accountForm.account_code} onChange={(event) => setAccountForm({ ...accountForm, account_code: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('اسم البنك', 'Bank name')}>
            <Input value={accountForm.bank_name} onChange={(event) => setAccountForm({ ...accountForm, bank_name: event.target.value })} />
          </Field>
          <Field label={L('آخر 4 أرقام من الحساب', 'Last 4 digits')} hint={L('لا يُخزَّن رقم الحساب الكامل', 'The full account number is never stored')}>
            <Input value={accountForm.account_last4} maxLength={4} inputMode="numeric" onChange={(event) => setAccountForm({ ...accountForm, account_last4: event.target.value })} dir="ltr" />
          </Field>
        </div>
      </Modal>

      <Modal
        open={transferOpen}
        title={L('تحويل جديد', 'New transfer')}
        onClose={() => setTransferOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setTransferOpen(false)}>{t('cancel')}</Button>
            <Button onClick={() => createTransfer.mutate()} disabled={createTransfer.isPending}>{t('save')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('من حساب', 'From account')} required>
            <SearchableSelect value={transferForm.from} onChange={(value) => setTransferForm({ ...transferForm, from: value })} options={options} placeholder={L('اختر', 'Select')} />
          </Field>
          <Field label={L('إلى حساب', 'To account')} required>
            <SearchableSelect value={transferForm.to} onChange={(value) => setTransferForm({ ...transferForm, to: value })} options={options} placeholder={L('اختر', 'Select')} />
          </Field>
          <Field label={t('date')} required>
            <Input type="date" value={transferForm.date} onChange={(event) => setTransferForm({ ...transferForm, date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={`${t('amount')}${fromAccount ? ` (${fromAccount.currency})` : ''}`} required>
            <Input value={transferForm.amount} onChange={(event) => setTransferForm({ ...transferForm, amount: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          {crossCurrency && toAccount ? (
            <>
              <Field label={`${L('المبلغ المستلم', 'Amount received')} (${toAccount.currency})`} required>
                <Input value={transferForm.received} onChange={(event) => setTransferForm({ ...transferForm, received: event.target.value })} inputMode="decimal" dir="ltr" />
              </Field>
              <Field label={L('سعر الصرف (اختياري)', 'Exchange rate (optional)')} hint={L('يُحتسب فرق العملة في أرباح/خسائر الصرف', 'The difference is booked to FX gain/loss')}>
                <Input value={transferForm.fromRate} onChange={(event) => setTransferForm({ ...transferForm, fromRate: event.target.value })} inputMode="decimal" dir="ltr" />
              </Field>
            </>
          ) : null}
          <Field label={L('الرسوم', 'Fee')}>
            <Input value={transferForm.fee} onChange={(event) => setTransferForm({ ...transferForm, fee: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('ملاحظات', 'Memo')}>
            <Input value={transferForm.memo} onChange={(event) => setTransferForm({ ...transferForm, memo: event.target.value })} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={reversing !== null}
        title={L('عكس التحويل', 'Reverse transfer')}
        onClose={() => setReversing(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReversing(null)}>{t('cancel')}</Button>
            <Button variant="danger" onClick={() => reverse.mutate()} disabled={reverse.isPending || reason.trim() === ''}>{L('عكس', 'Reverse')}</Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <p className="mb-3 text-medium text-text-subtle">{reversing?.number}</p>
        <Field label={L('السبب', 'Reason')} required>
          <Input value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
    </div>
  )
}
