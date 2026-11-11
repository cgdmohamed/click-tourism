import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useI18n } from '../i18n'
import { api, errorMessage, idempotencyKey } from '../lib/api'
import { formatDate, formatDateTime, toMinor, todayISO } from '../lib/format'
import { allocateMatch } from '../lib/reconciliationAllocation'
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
  Select,
  Table,
  Td,
  Th,
  statusTone,
} from '../components/ui'

type BankAccount = {
  id: string
  cash_account_id?: string
  code: string
  name_ar: string
  name_en: string
  kind?: string
  currency: string
  is_active?: boolean
  registered: boolean
}

type Statement = {
  id: string
  cash_account_id: string | null
  filename: string
  format: string
  period_from: string | null
  period_to: string | null
  opening_balance_minor: number | null
  closing_balance_minor: number | null
  currency: string
  line_count: number
  imported_at: string
}

type StatementLine = {
  id: string
  line_date: string | null
  value_date: string | null
  amount_minor: number
  balance_after_minor: number | null
  bank_reference: string | null
  description: string | null
  party_name: string | null
}

type StatementDetail = Statement & { lines: StatementLine[] }

type Session = {
  id: string
  cash_account_id: string | null
  cash_account: BankAccount | null
  period_from: string
  period_to: string
  currency: string
  status: 'open' | 'approved'
  opening_book_balance_minor: number
  opening_bank_balance_minor: number
  closing_bank_balance_minor: number
  closing_book_balance_minor: number
  notes: string | null
  adjustment_document_ids: string[]
  approved_at: string | null
}

type Summary = {
  opening_book_minor: number
  book_movement_minor: number
  book_closing_minor: number
  opening_bank_minor: number
  bank_movement_minor: number
  bank_closing_minor: number
  statement_balanced: boolean
  matched_bank_minor: number
  matched_book_minor: number
  deposits_in_transit_minor: number
  outstanding_payments_minor: number
  unrecorded_bank_credits_minor: number
  unrecorded_bank_debits_minor: number
  tolerance_difference_minor: number
  adjusted_bank_minor: number
  adjusted_book_minor: number
  difference_minor: number
  tolerance_minor: number
  window_days: number
  reconciled: boolean
  bank_lines: number
  book_lines: number
  excluded_non_native_lines?: number
}

type BankLine = {
  id: string
  line_date: string | null
  value_date: string | null
  amount_minor: number
  balance_after_minor: number | null
  bank_reference: string | null
  description: string | null
  party_name: string | null
  direction: 'in' | 'out'
  matched_minor: number
  remaining_minor: number
}

type BookLine = {
  id: string
  journal_entry_id: string | null
  entry_number: string | null
  entry_date: string | null
  description: string | null
  source_type: string | null
  debit_minor: number
  credit_minor: number
  amount_minor: number
  direction: 'in' | 'out'
  matched_minor: number
  remaining_minor: number
  reversed?: boolean
}

type MatchRow = {
  id: string
  group: string
  bank_line_id: string | null
  bank_line_date: string | null
  bank_line_description: string | null
  bank_line_amount_minor: number | null
  journal_line_id: string | null
  journal_entry_number: string | null
  journal_entry_date: string | null
  journal_line_description: string | null
  amount_minor: number
  kind: 'auto' | 'manual'
  note: string | null
}

type Candidate = {
  bank_line_id: string
  journal_line_id: string
  line_date: string
  entry_date: string
  bank_amount_minor: number
  book_amount_minor: number
  amount_minor: number
  difference_minor: number
  days_apart: number
  ambiguous: boolean
}

type Difference = {
  side: 'bank' | 'book'
  category: 'outstanding_payment' | 'deposit_in_transit' | 'unrecorded_bank_debit' | 'unrecorded_bank_credit' | 'tolerance_difference'
  line_id: string
  date: string | null
  description: string | null
  amount_minor: number
  action: 'post_adjustment' | 'explain' | 'carry_forward'
}

type Detail = Session & {
  summary: Summary
  bank_lines: BankLine[]
  book_lines: BookLine[]
  matches: MatchRow[]
  candidates: Candidate[]
  differences: Difference[]
}

type Tab = 'sessions' | 'statements'

const emptyImport = {
  cash_account_id: '',
  delimiter: ',',
  period_from: '',
  period_to: '',
  opening_balance_minor: '',
  closing_balance_minor: '',
  date: 'Date',
  description: 'Description',
  reference: 'Reference',
  amount: 'Amount',
  money_in: '',
  money_out: '',
  date_format: 'Y-m-d',
}

const emptySession = {
  cash_account_id: '',
  statement_id: '',
  period_from: '',
  period_to: '',
  opening_bank_balance_minor: '',
  closing_bank_balance_minor: '',
  notes: '',
}

const categoryLabels: Record<Difference['category'], { ar: string; en: string }> = {
  outstanding_payment: { ar: 'شيك لم يُصرف', en: 'Unpresented payment' },
  deposit_in_transit: { ar: 'إيداع في الطريق', en: 'Deposit in transit' },
  unrecorded_bank_debit: { ar: 'مدين بنكي غير مسجل', en: 'Unrecorded bank debit' },
  unrecorded_bank_credit: { ar: 'دائن بنكي غير مسجل', en: 'Unrecorded bank credit' },
  tolerance_difference: { ar: 'فرق في حدود السماحية', en: 'Tolerance difference' },
}

export default function Reconciliation() {
  const { t, locale } = useI18n()
  const { activeBranch, can } = useAuth()
  const queryClient = useQueryClient()
  const L = (ar: string, en: string) => (locale === 'ar' ? ar : en)

  const [tab, setTab] = useState<Tab>('sessions')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [previewId, setPreviewId] = useState<string | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [importForm, setImportForm] = useState(emptyImport)
  const [importFile, setImportFile] = useState<File | null>(null)
  const [sessionOpen, setSessionOpen] = useState(false)
  const [sessionForm, setSessionForm] = useState(emptySession)
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [adjustForm, setAdjustForm] = useState({ memo: '', document_date: '', charges_account_code: '', interest_account_code: '', line_ids: [] as string[] })
  const [bankSel, setBankSel] = useState<string[]>([])
  const [bookSel, setBookSel] = useState<string[]>([])
  const [approveOpen, setApproveOpen] = useState(false)
  const [reopenOpen, setReopenOpen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')

  const manage = can('reconciliation.manage')
  const approve = can('reconciliation.approve')

  const accountsQuery = useQuery({
    queryKey: ['cash-accounts', 'reconciliation', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: BankAccount[] }>('/cash-accounts', { params: { include_inactive: 1 } })
      return data.data
    },
  })

  const bankAccounts = useMemo(
    () => (accountsQuery.data ?? []).filter((account) => account.registered && account.kind === 'bank' && account.is_active !== false),
    [accountsQuery.data],
  )

  const sessionsQuery = useQuery({
    queryKey: ['reconciliations', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Session[] }>('/reconciliations')
      return data.data
    },
  })

  const statementsQuery = useQuery({
    queryKey: ['bank-statements', activeBranch?.id],
    queryFn: async () => {
      const { data } = await api.get<{ data: Statement[] }>('/bank-statements')
      return data.data
    },
  })

  const statementQuery = useQuery({
    queryKey: ['bank-statement', previewId],
    queryFn: async () => {
      const { data } = await api.get<{ data: StatementDetail }>(`/bank-statements/${previewId}`)
      return data.data
    },
    enabled: previewId !== null,
  })

  const detailQuery = useQuery({
    queryKey: ['reconciliation', selectedId],
    queryFn: async () => {
      const { data } = await api.get<{ data: Detail }>(`/reconciliations/${selectedId}`)
      return data.data
    },
    enabled: selectedId !== null,
  })

  const detail = detailQuery.data

  useEffect(() => {
    setBankSel([])
    setBookSel([])
    setError('')
    setNotice('')
  }, [selectedId])

  const name = (account?: BankAccount | null) => (account ? (locale === 'ar' ? account.name_ar : account.name_en) : '—')

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['reconciliations'] })
    await queryClient.invalidateQueries({ queryKey: ['bank-statements'] })
    if (selectedId) await queryClient.invalidateQueries({ queryKey: ['reconciliation', selectedId] })
  }

  const importStatement = useMutation({
    mutationFn: async () => {
      if (!importFile) throw new Error(L('اختر ملف كشف الحساب', 'Choose a statement file'))
      if (!importForm.cash_account_id) throw new Error(L('اختر الحساب البنكي', 'Choose the bank account'))
      if (importFile.size > 20 * 1024 * 1024) throw new Error(L('الملف أكبر من 20 ميجابايت؛ قسّمه إلى ملفات أصغر.', 'The file is larger than 20 MB; split it into smaller files.'))
      if (!importForm.amount && !importForm.money_in && !importForm.money_out) {
        throw new Error(L('حدد عمود المبلغ أو عمودي الإيداع/السحب', 'Map an amount column, or money-in/money-out columns'))
      }

      const body = new FormData()
      body.append('file', importFile)
      body.append('cash_account_id', importForm.cash_account_id)
      if (importForm.delimiter) body.append('delimiter', importForm.delimiter)
      if (importForm.period_from) body.append('period_from', importForm.period_from)
      if (importForm.period_to) body.append('period_to', importForm.period_to)
      if (importForm.opening_balance_minor) body.append('opening_balance_minor', String(toMinor(importForm.opening_balance_minor, currencyOf(importForm.cash_account_id))))
      if (importForm.closing_balance_minor) body.append('closing_balance_minor', String(toMinor(importForm.closing_balance_minor, currencyOf(importForm.cash_account_id))))
      const map: Array<[string, string]> = [
        ['date', importForm.date],
        ['description', importForm.description],
        ['reference', importForm.reference],
        ['amount', importForm.amount],
        ['money_in', importForm.money_in],
        ['money_out', importForm.money_out],
        ['date_format', importForm.date_format],
      ]
      map.filter(([, value]) => value.trim() !== '').forEach(([key, value]) => body.append(`mapping[${key}]`, value))

      const { data } = await api.post<{ data: { imported_lines: number; skipped_duplicates: number } }>('/bank-statements/import', body)
      return data.data
    },
    onSuccess: async (data) => {
      setImportOpen(false)
      setImportFile(null)
      setImportForm(emptyImport)
      setError('')
      setNotice(
        L(
          `تم استيراد ${data.imported_lines} سطرًا وتخطي ${data.skipped_duplicates} سطرًا مكررًا.`,
          `Imported ${data.imported_lines} lines; skipped ${data.skipped_duplicates} duplicates.`,
        ),
      )
      setTab('statements')
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const createSession = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Detail }>('/reconciliations', {
        cash_account_id: sessionForm.cash_account_id,
        statement_id: sessionForm.statement_id || undefined,
        period_from: sessionForm.period_from || undefined,
        period_to: sessionForm.period_to || undefined,
        opening_bank_balance_minor: sessionForm.opening_bank_balance_minor
          ? toMinor(sessionForm.opening_bank_balance_minor, currencyOf(sessionForm.cash_account_id))
          : undefined,
        closing_bank_balance_minor: sessionForm.closing_bank_balance_minor
          ? toMinor(sessionForm.closing_bank_balance_minor, currencyOf(sessionForm.cash_account_id))
          : undefined,
        notes: sessionForm.notes || undefined,
      })
      return data.data
    },
    onSuccess: async (data) => {
      setSessionOpen(false)
      setSessionForm(emptySession)
      setError('')
      await refresh()
      setSelectedId(data.id)
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const autoMatch = useMutation({
    mutationFn: async () => {
      if (!selectedId) return null
      const { data } = await api.post<{ data: { created: number; ambiguous: number; remaining: number } }>(
        `/reconciliations/${selectedId}/auto-match`,
      )
      return data.data
    },
    onSuccess: async (data) => {
      setError('')
      if (data) {
        setNotice(
          L(
            `تمت مطابقة ${data.created} حركة؛ ${data.ambiguous} حركة تحتاج مراجعة بشرية.`,
            `Matched ${data.created} items; ${data.ambiguous} need human review.`,
          ),
        )
      }
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const manualMatch = useMutation({
    mutationFn: async (items: Array<{ bank_line_id: string; journal_line_id: string; amount_minor: number }>) => {
      if (!selectedId) return
      await api.post(`/reconciliations/${selectedId}/matches`, { items })
    },
    onSuccess: async () => {
      setBankSel([])
      setBookSel([])
      setError('')
      setNotice(L('تمت المطابقة.', 'Matched.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const unmatch = useMutation({
    mutationFn: async (group: string) => {
      if (!selectedId) return
      await api.delete(`/reconciliations/${selectedId}/matches/${group}`)
    },
    onSuccess: async () => {
      setError('')
      setNotice(L('تم إلغاء المطابقة.', 'Match removed.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const proposeAdjustment = useMutation({
    mutationFn: async () => {
      if (!selectedId) return null
      const { data } = await api.post<{ data: { document: { id: string; number: string } } }>(
        `/reconciliations/${selectedId}/adjustment-voucher`,
        {
          bank_line_ids: adjustForm.line_ids,
          document_date: adjustForm.document_date || undefined,
          memo: adjustForm.memo || undefined,
          charges_account_code: adjustForm.charges_account_code.trim() || undefined,
          interest_account_code: adjustForm.interest_account_code.trim() || undefined,
        },
      )
      return data.data.document
    },
    onSuccess: async (document) => {
      setAdjustOpen(false)
      setAdjustForm({ memo: '', document_date: '', charges_account_code: '', interest_account_code: '', line_ids: [] })
      setError('')
      if (document) {
        setNotice(
          L(
            `أُنشئ سند تسوية مسودة ${document.number}. أرسله واعتمده ورحّله ثم أعد المطابقة.`,
            `Draft adjustment voucher ${document.number} created. Submit, approve and post it, then match again.`,
          ),
        )
      }
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const approveSession = useMutation({
    mutationFn: async () => {
      if (!selectedId) return
      await api.post(`/reconciliations/${selectedId}/approve`, undefined, {
        headers: { 'Idempotency-Key': idempotencyKey() },
      })
    },
    onSuccess: async () => {
      setApproveOpen(false)
      setError('')
      setNotice(L('تم اعتماد المطابقة وقفلها.', 'Reconciliation approved and locked.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const currencyOf = (cashAccountId: string) =>
    bankAccounts.find((account) => (account.cash_account_id ?? account.id) === cashAccountId)?.currency ?? 'EGP'

  const reopenSession = useMutation({
    mutationFn: async () => {
      if (!selectedId) return
      if (reopenReason.trim().length < 3) throw new Error(L('اكتب سبب إعادة الفتح', 'Enter a reason for reopening'))
      await api.post(`/reconciliations/${selectedId}/reopen`, { reason: reopenReason.trim() }, {
        headers: { 'Idempotency-Key': idempotencyKey() },
      })
    },
    onSuccess: async () => {
      setReopenOpen(false)
      setReopenReason('')
      setError('')
      setNotice(L('أُعيد فتح المطابقة.', 'Reconciliation reopened.'))
      await refresh()
    },
    onError: (cause) => setError(errorMessage(cause)),
  })

  const bankOptions = bankAccounts.map((account) => ({
    value: account.cash_account_id ?? account.id,
    label: `${name(account)} (${account.currency})`,
  }))

  const statementOptions = (statementsQuery.data ?? [])
    .filter((statement) => !sessionForm.cash_account_id || statement.cash_account_id === sessionForm.cash_account_id)
    .map((statement) => ({
      value: statement.id,
      label: `${statement.filename} · ${statement.line_count} ${L('سطر', 'lines')}`,
    }))

  const selectedBankLines = (detail?.bank_lines ?? []).filter((line) => bankSel.includes(line.id))
  const selectedBookLines = (detail?.book_lines ?? []).filter((line) => bookSel.includes(line.id))
  const bankSelTotal = selectedBankLines.reduce((sum, line) => sum + line.remaining_minor, 0)
  const bookSelTotal = selectedBookLines.reduce((sum, line) => sum + line.remaining_minor, 0)
  const selectionDifference = bankSelTotal - bookSelTotal
  const tolerance = detail?.summary.tolerance_minor ?? 0
  const selectionBalanced =
    selectedBankLines.length > 0 && selectedBookLines.length > 0 && Math.abs(selectionDifference) <= tolerance

  const matchItems = selectionBalanced ? allocateMatch(selectedBankLines, selectedBookLines) : []

  const unrecordedBankLines = (detail?.bank_lines ?? []).filter((line) => line.remaining_minor > 0)
  const adjustedBook = detail?.summary.adjusted_book_minor ?? 0
  const adjustedBank = detail?.summary.adjusted_bank_minor ?? 0

  const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((id) => id !== value) : [...list, value])

  const openAdjustment = () => {
    setError('')
    setAdjustForm({
      memo: '',
      document_date: '',
      charges_account_code: '',
      interest_account_code: '',
      line_ids: unrecordedBankLines.map((line) => line.id),
    })
    setAdjustOpen(true)
  }

  const tabButton = (key: Tab, label: string) => (
    <button
      className={`rounded-12 border px-4 py-2 text-sm font-medium transition ${
        tab === key
          ? 'border-border-selected bg-surface-selected text-text-default'
          : 'border-border-subtle bg-surface-default text-text-subtle hover:bg-surface-hovered'
      }`}
      onClick={() => setTab(key)}
    >
      {label}
    </button>
  )

  const summaryValue = (label: string, value: number) => (
    <div>
      <p className="text-small text-text-subtle">{label}</p>
      <p className="tabular text-medium">
        <Money amountMinor={value} currency={detail?.currency ?? 'EGP'} />
      </p>
    </div>
  )

  // ------------------------------------------------------------------
  // Session detail
  // ------------------------------------------------------------------
  if (selectedId !== null) {
    const groups = Array.from(new Set((detail?.matches ?? []).map((match) => match.group)))

    return (
      <div>
        <PageHeader
          title={L('مطابقة البنك', 'Bank reconciliation')}
          subtitle={
            detail
              ? `${name(detail.cash_account)} · ${formatDate(detail.period_from, locale)} → ${formatDate(detail.period_to, locale)}`
              : undefined
          }
          icon="listChecks"
          actions={
            <>
              <Button variant="secondary" className="no-print" onClick={() => setSelectedId(null)}>
                {L('رجوع', 'Back')}
              </Button>
              <Button variant="secondary" className="no-print" icon="printer" onClick={() => window.print()}>
                {L('طباعة', 'Print')}
              </Button>
            </>
          }
        />

        {error ? (
          <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
        ) : null}
        {notice ? (
          <p className="mb-4 rounded-12 border border-border-subtle bg-surface-subtle px-3 py-2 text-medium text-text-default">{notice}</p>
        ) : null}

        {detailQuery.isLoading ? <Loading /> : null}
        {detailQuery.isError ? <ErrorState message={errorMessage(detailQuery.error)} onRetry={() => void detailQuery.refetch()} /> : null}

        {detail ? (
          <div className="space-y-4">
            <Card>
              <div className="flex flex-wrap items-center gap-2 border-b border-border-subtle px-5 py-4">
                <Badge tone={statusTone(detail.status)}>{detail.status === 'approved' ? L('معتمدة ومقفلة', 'Approved & locked') : L('مفتوحة', 'Open')}</Badge>
                <Badge tone={detail.summary.reconciled ? 'positive' : 'warning'}>
                  {detail.summary.reconciled ? L('مطابقة', 'Reconciled') : L('غير مطابقة', 'Not reconciled')}
                </Badge>
                {detail.status === 'open' && manage ? (
                  <Button variant="secondary" onClick={() => autoMatch.mutate()} disabled={autoMatch.isPending}>
                    {L('مطابقة تلقائية', 'Auto-match')}
                  </Button>
                ) : null}
                {detail.status === 'open' && manage && unrecordedBankLines.length > 0 ? (
                  <Button variant="secondary" onClick={openAdjustment}>
                    {L('سند تسوية', 'Adjustment voucher')}
                  </Button>
                ) : null}
                {detail.status === 'open' && approve ? (
                  <Button onClick={() => { setError(''); setApproveOpen(true) }} disabled={!detail.summary.reconciled}>
                    {L('اعتماد وقفل', 'Approve & lock')}
                  </Button>
                ) : null}
                {detail.status === 'approved' && approve ? (
                  <Button variant="secondary" onClick={() => { setError(''); setReopenReason(''); setReopenOpen(true) }}>
                    {L('إعادة فتح', 'Reopen')}
                  </Button>
                ) : null}
                {detail.notes ? <span className="text-small text-text-subtle">{detail.notes}</span> : null}
              </div>

              <div className="grid gap-4 px-5 py-4 sm:grid-cols-3 lg:grid-cols-4">
                {summaryValue(L('رصيد البنك الافتتاحي', 'Opening bank'), detail.summary.opening_bank_minor)}
                {summaryValue(L('حركة البنك', 'Bank movement'), detail.summary.bank_movement_minor)}
                {summaryValue(L('رصيد البنك الختامي', 'Closing bank'), detail.summary.bank_closing_minor)}
                {summaryValue(L('رصيد الدفاتر الختامي', 'Closing book'), detail.summary.book_closing_minor)}
                {summaryValue(L('إيداعات في الطريق', 'Deposits in transit'), detail.summary.deposits_in_transit_minor)}
                {summaryValue(L('شيكات لم تُصرف', 'Unpresented payments'), detail.summary.outstanding_payments_minor)}
                {summaryValue(L('مدين بنكي غير مسجل', 'Unrecorded bank debits'), detail.summary.unrecorded_bank_debits_minor)}
                {summaryValue(L('دائن بنكي غير مسجل', 'Unrecorded bank credits'), detail.summary.unrecorded_bank_credits_minor)}
                {summaryValue(L('البنك المعدّل', 'Adjusted bank'), adjustedBank)}
                {summaryValue(L('الدفاتر المعدّلة', 'Adjusted book'), adjustedBook)}
                {summaryValue(L('الفرق', 'Difference'), detail.summary.difference_minor)}
                <div>
                  <p className="text-small text-text-subtle">{L('السماحية / نافذة التاريخ', 'Tolerance / date window')}</p>
                  <p className="tabular text-medium">
                    <Money amountMinor={detail.summary.tolerance_minor} currency={detail.currency} /> · {detail.summary.window_days}{' '}
                    {L('يوم', 'days')}
                  </p>
                </div>
              </div>
              {(detail.summary.excluded_non_native_lines ?? 0) > 0 ? (
                <p className="text-small border-t border-border-subtle px-5 py-3 text-text-subtle">
                  {L(
                    `${detail.summary.excluded_non_native_lines} قيدًا على هذا الحساب بلا مبلغ بعملته (إعادة تقييم أو قيود قديمة بالعملة الأساسية) ولا تدخل في المطابقة.`,
                    `${detail.summary.excluded_non_native_lines} ledger lines on this account carry no amount in its own currency (revaluation or legacy base-currency lines) and are not reconciled.`,
                  )}
                </p>
              ) : null}
              {!detail.summary.statement_balanced ? (
                <p className="text-small border-t border-border-subtle px-5 py-3 text-warning-text">
                  {L(
                    'رصيد الكشف لا يطابق حركته: راجع الرصيد الافتتاحي والختامي المدخلين.',
                    'The statement totals do not agree with its movement: check the entered opening and closing balances.',
                  )}
                </p>
              ) : null}
            </Card>

            {detail.differences.length > 0 ? (
              <Card>
                <div className="border-b border-border-subtle px-5 py-4">
                  <h3 className="text-medium">{L('الفروق', 'Differences')}</h3>
                </div>
                <Table>
                  <thead>
                    <tr>
                      <Th>{L('الجانب', 'Side')}</Th>
                      <Th>{L('النوع', 'Category')}</Th>
                      <Th>{t('date')}</Th>
                      <Th>{t('description')}</Th>
                      <Th className="text-end">{t('amount')}</Th>
                      <Th>{L('الإجراء', 'Action')}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {detail.differences.map((difference, index) => (
                      <tr key={`${difference.line_id}-${index}`}>
                        <Td>{difference.side === 'bank' ? L('كشف البنك', 'Bank statement') : L('الدفاتر', 'Books')}</Td>
                        <Td>{L(categoryLabels[difference.category].ar, categoryLabels[difference.category].en)}</Td>
                        <Td className="tabular">{formatDate(difference.date, locale)}</Td>
                        <Td>{difference.description ?? '—'}</Td>
                        <Td className="text-end">
                          <Money amountMinor={difference.amount_minor} currency={detail.currency} />
                        </Td>
                        <Td>
                          <Badge
                            tone={
                              difference.action === 'post_adjustment'
                                ? 'warning'
                                : difference.action === 'carry_forward'
                                  ? 'brand'
                                  : 'neutral'
                            }
                          >
                            {difference.action === 'post_adjustment'
                              ? L('تسوية', 'Post adjustment')
                              : difference.action === 'carry_forward'
                                ? L('ترحيل للفترة القادمة', 'Carry forward')
                                : L('تفسير', 'Explain')}
                          </Badge>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </Card>
            ) : null}

            {detail.status === 'open' && detail.candidates.length > 0 ? (
              <Card>
                <div className="border-b border-border-subtle px-5 py-4">
                  <h3 className="text-medium">{L('مرشحات المطابقة', 'Suggested matches')}</h3>
                  <p className="text-small text-text-subtle">
                    {L('تُطابق الحركات غير المبهمة فقط تلقائيًا؛ المبهمة تحتاج اختيارًا بشريًا.', 'Only unambiguous candidates are auto-matched; ambiguous ones need a human choice.')}
                  </p>
                </div>
                <Table>
                  <thead>
                    <tr>
                      <Th>{t('date')}</Th>
                      <Th>{L('حركة البنك', 'Bank line')}</Th>
                      <Th>{L('قيد الدفاتر', 'Book line')}</Th>
                      <Th className="text-end">{t('amount')}</Th>
                      <Th className="text-end">{L('فرق / أيام', 'Diff / days')}</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    {detail.candidates.map((candidate) => (
                      <tr key={`${candidate.bank_line_id}-${candidate.journal_line_id}`}>
                        <Td className="tabular">{formatDate(candidate.line_date, locale)}</Td>
                        <Td>{detail.bank_lines.find((line) => line.id === candidate.bank_line_id)?.description ?? '—'}</Td>
                        <Td>{detail.book_lines.find((line) => line.id === candidate.journal_line_id)?.description ?? '—'}</Td>
                        <Td className="text-end">
                          <Money amountMinor={candidate.amount_minor} currency={detail.currency} />
                        </Td>
                        <Td className="text-end tabular">
                          {candidate.difference_minor !== 0 ? (
                            <span className="text-warning-text">
                              <Money amountMinor={candidate.difference_minor} currency={detail.currency} />
                            </span>
                          ) : (
                            '0.00'
                          )}{' '}
                          · {candidate.days_apart}
                        </Td>
                        <Td className="text-end">
                          {candidate.ambiguous ? (
                            <Badge tone="warning">{L('مراجعة', 'Review')}</Badge>
                          ) : manage ? (
                            <Button
                              variant="ghost"
                              disabled={manualMatch.isPending}
                              onClick={() =>
                                manualMatch.mutate([
                                  {
                                    bank_line_id: candidate.bank_line_id,
                                    journal_line_id: candidate.journal_line_id,
                                    amount_minor: candidate.amount_minor,
                                  },
                                ])
                              }
                            >
                              {L('مطابقة', 'Match')}
                            </Button>
                          ) : null}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </Card>
            ) : null}

            <Card>
              <div className="flex flex-wrap items-center gap-2 border-b border-border-subtle px-5 py-4">
                <h3 className="text-medium">{L('حركات كشف البنك', 'Bank statement lines')}</h3>
                <span className="text-small text-text-subtle">
                  {L('حدد سطر بنك وسطر دفاتر لعمل مطابقة يدوية (المبالغ يجب أن تتساوى بالكامل).', 'Select bank and book lines to match manually (amounts must be fully consumed).')}
                </span>
              </div>
              <Table>
                <thead>
                  <tr>
                    <Th />
                    <Th>{t('date')}</Th>
                    <Th>{t('description')}</Th>
                    <Th>{L('المرجع', 'Reference')}</Th>
                    <Th className="text-end">{t('amount')}</Th>
                    <Th className="text-end">{L('مطابق', 'Matched')}</Th>
                    <Th className="text-end">{L('متبقي', 'Remaining')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {detail.bank_lines.map((line) => (
                    <tr key={line.id}>
                      <Td>
                        <input
                          type="checkbox"
                          aria-label={line.description ?? line.id}
                          checked={bankSel.includes(line.id)}
                          disabled={line.remaining_minor <= 0 || detail.status === 'approved'}
                          onChange={() => setBankSel((current) => toggle(current, line.id))}
                        />
                      </Td>
                      <Td className="tabular">{formatDate(line.line_date, locale)}</Td>
                      <Td>
                        {line.description ?? '—'}
                        {line.party_name ? <div className="text-small text-text-subtle">{line.party_name}</div> : null}
                      </Td>
                      <Td className="tabular">{line.bank_reference ?? '—'}</Td>
                      <Td className="text-end"><Money amountMinor={line.amount_minor} currency={detail.currency} /></Td>
                      <Td className="text-end"><Money amountMinor={line.matched_minor} currency={detail.currency} /></Td>
                      <Td className={`text-end ${line.remaining_minor > 0 ? 'text-warning-text' : ''}`}>
                        <Money amountMinor={line.remaining_minor} currency={detail.currency} />
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>

            <Card>
              <div className="flex flex-wrap items-center gap-2 border-b border-border-subtle px-5 py-4">
                <h3 className="text-medium">{L('قيود الدفاتر', 'Ledger lines')}</h3>
                <span className="text-small text-text-subtle">
                  {L('حركات الحساب البنكي المرحّلة حتى نهاية الفترة (تشمل البنود المعلقة من فترات سابقة).', 'Posted bank-account lines up to the period end (including open items from earlier periods).')}
                </span>
              </div>
              <Table>
                <thead>
                  <tr>
                    <Th />
                    <Th>{t('date')}</Th>
                    <Th>{L('القيد', 'Entry')}</Th>
                    <Th>{t('description')}</Th>
                    <Th className="text-end">{t('amount')}</Th>
                    <Th className="text-end">{L('متبقي', 'Remaining')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {detail.book_lines.map((line) => (
                    <tr key={line.id}>
                      <Td>
                        <input
                          type="checkbox"
                          aria-label={line.description ?? line.id}
                          checked={bookSel.includes(line.id)}
                          disabled={line.remaining_minor <= 0 || detail.status === 'approved' || line.reversed === true}
                          onChange={() => setBookSel((current) => toggle(current, line.id))}
                        />
                      </Td>
                      <Td className="tabular">{formatDate(line.entry_date, locale)}</Td>
                      <Td className="tabular">{line.entry_number ?? '—'}</Td>
                      <Td>
                        {line.description ?? '—'}
                        {line.source_type ? <div className="text-small text-text-subtle">{line.source_type}</div> : null}
                        {line.reversed ? <Badge tone="neutral">{L('معكوس ومُقاصّ', 'Reversed & paired out')}</Badge> : null}
                      </Td>
                      <Td className="text-end"><Money amountMinor={line.amount_minor} currency={detail.currency} /></Td>
                      <Td className={`text-end ${line.remaining_minor > 0 ? 'text-warning-text' : ''}`}>
                        <Money amountMinor={line.remaining_minor} currency={detail.currency} />
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
              {detail.status === 'open' && manage ? (
                <div className="flex flex-wrap items-center gap-3 border-t border-border-subtle px-5 py-4">
                  <span className="text-small text-text-subtle">
                    {L('المحدد', 'Selected')}: <Money amountMinor={bankSelTotal} currency={detail.currency} /> /{' '}
                    <Money amountMinor={bookSelTotal} currency={detail.currency} />
                    {selectionDifference !== 0 ? (
                      <>
                        {' · '}
                        {L('الفرق', 'Difference')}: <Money amountMinor={selectionDifference} currency={detail.currency} />
                      </>
                    ) : null}
                  </span>
                  <Button
                    className="ms-auto"
                    disabled={matchItems.length === 0 || manualMatch.isPending}
                    onClick={() => manualMatch.mutate(matchItems)}
                  >
                    {L('مطابقة المحدد', 'Match selected')}
                  </Button>
                </div>
              ) : null}
            </Card>

            <Card>
              <div className="border-b border-border-subtle px-5 py-4">
                <h3 className="text-medium">{L('المطابقات', 'Matches')}</h3>
              </div>
              {detail.matches.length === 0 ? (
                <EmptyState title={L('لا توجد مطابقات بعد', 'No matches yet')} />
              ) : (
                <Table>
                  <thead>
                    <tr>
                      <Th>{L('حركة البنك', 'Bank line')}</Th>
                      <Th>{L('قيد الدفاتر', 'Book line')}</Th>
                      <Th className="text-end">{t('amount')}</Th>
                      <Th>{L('الطريقة', 'Method')}</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    {detail.matches.map((match) => (
                      <tr key={match.id}>
                        <Td>
                          <span className="tabular">{formatDate(match.bank_line_date, locale)}</span> · {match.bank_line_description ?? '—'}
                        </Td>
                        <Td>
                          <span className="tabular">{match.journal_entry_number ?? '—'}</span> · {match.journal_line_description ?? '—'}
                        </Td>
                        <Td className="text-end"><Money amountMinor={match.amount_minor} currency={detail.currency} /></Td>
                        <Td>
                          <Badge tone={match.kind === 'auto' ? 'brand' : 'neutral'}>
                            {match.kind === 'auto' ? L('تلقائي', 'Auto') : L('يدوي', 'Manual')}
                          </Badge>
                        </Td>
                        <Td className="text-end">
                          {detail.status === 'open' && manage ? (
                            <Button variant="ghost" onClick={() => unmatch.mutate(match.group)} disabled={unmatch.isPending}>
                              {L('إلغاء', 'Unmatch')}
                            </Button>
                          ) : null}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
              {groups.length > 1 ? (
                <p className="text-small px-5 py-3 text-text-subtle">
                  {L(`${groups.length} مجموعة مطابقة (مطابقات مجمعة تشمل عدة سطور).`, `${groups.length} match groups (grouped matches span multiple lines).`)}
                </p>
              ) : null}
            </Card>

            {detail.adjustment_document_ids.length > 0 ? (
              <Card>
                <div className="border-b border-border-subtle px-5 py-4">
                  <h3 className="text-medium">{L('سندات التسوية', 'Adjustment vouchers')}</h3>
                  <p className="text-small text-text-subtle">
                    {L('تُرحّل من صفحة السندات ثم تُطابق كأي حركة أخرى.', 'Post them from the vouchers page, then match them like any other line.')}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2 px-5 py-4">
                  {detail.adjustment_document_ids.map((id) => (
                    <Link key={id} className="text-small text-brand-1-accent underline underline-offset-4" to="/documents">
                      {id}
                    </Link>
                  ))}
                </div>
              </Card>
            ) : null}

            {detail.approved_at ? (
              <p className="text-small text-text-subtle">
                {L('اعتُمدت في', 'Approved at')}: {formatDateTime(detail.approved_at, locale)}
              </p>
            ) : null}
          </div>
        ) : null}

        <Modal
          open={adjustOpen}
          title={L('سند تسوية للحركات غير المسجلة', 'Adjustment voucher for unrecorded items')}
          onClose={() => setAdjustOpen(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setAdjustOpen(false)}>{t('cancel')}</Button>
              <Button
                onClick={() => proposeAdjustment.mutate()}
                disabled={proposeAdjustment.isPending || adjustForm.line_ids.length === 0}
              >
                {L('إنشاء مسودة', 'Create draft')}
              </Button>
            </>
          }
        >
          {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
          <p className="text-small mb-3 text-text-subtle">
            {L(
              'يُنشأ سند مسودة: لا يؤثر على الدفاتر حتى يُرسل ويُعتمد ويُرحّل. الرسوم إلى حساب مصاريف البنك، والفوائد إلى حساب الفوائد.',
              'A draft voucher is created: it does not affect the ledger until submitted, approved and posted. Charges go to the bank charges account; interest to the interest account.',
            )}
          </p>
          <div className="mb-3 space-y-2">
            {unrecordedBankLines.map((line) => (
              <label key={line.id} className="flex items-center gap-2 text-medium">
                <input
                  type="checkbox"
                  checked={adjustForm.line_ids.includes(line.id)}
                  onChange={() => setAdjustForm({ ...adjustForm, line_ids: toggle(adjustForm.line_ids, line.id) })}
                />
                <span className="tabular">{formatDate(line.line_date, locale)}</span>
                <span>{line.description ?? '—'}</span>
                <span className="ms-auto tabular"><Money amountMinor={line.remaining_minor} currency={detail?.currency ?? 'EGP'} /></span>
              </label>
            ))}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={L('تاريخ السند', 'Voucher date')} hint={L('افتراضيًا أقدم تاريخ حركة', 'Defaults to the oldest bank line date')}>
              <Input type="date" value={adjustForm.document_date} onChange={(event) => setAdjustForm({ ...adjustForm, document_date: event.target.value })} dir="ltr" />
            </Field>
            <Field label={t('memo')}>
              <Input value={adjustForm.memo} onChange={(event) => setAdjustForm({ ...adjustForm, memo: event.target.value })} />
            </Field>
            <Field label={L('حساب الرسوم', 'Charges account')} hint={L('اتركه فارغًا لاستخدام الإعداد الافتراضي', 'Leave blank for the configured default')}>
              <Input value={adjustForm.charges_account_code} onChange={(event) => setAdjustForm({ ...adjustForm, charges_account_code: event.target.value })} dir="ltr" />
            </Field>
            <Field label={L('حساب الفوائد', 'Interest account')} hint={L('اتركه فارغًا لاستخدام الإعداد الافتراضي', 'Leave blank for the configured default')}>
              <Input value={adjustForm.interest_account_code} onChange={(event) => setAdjustForm({ ...adjustForm, interest_account_code: event.target.value })} dir="ltr" />
            </Field>
          </div>
        </Modal>

        <Modal
          open={approveOpen}
          title={L('اعتماد وقفل المطابقة', 'Approve and lock reconciliation')}
          onClose={() => setApproveOpen(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setApproveOpen(false)}>{t('cancel')}</Button>
              <Button onClick={() => approveSession.mutate()} disabled={approveSession.isPending}>
                {L('اعتماد', 'Approve')}
              </Button>
            </>
          }
        >
          {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
          <p className="text-medium">
            {L(
              'سيتم قفل المطابقة وحفظ نسخة من الأرصدة والمطابقات. يمكن لمن يملك صلاحية الاعتماد إعادة فتحها بسبب مكتوب (يُسجَّل في سجل التدقيق).',
              'The reconciliation will be locked with a snapshot of balances and matches. A user with approval rights can reopen it with a written reason (audited).',
            )}
          </p>
        </Modal>

        <Modal
          open={reopenOpen}
          title={L('إعادة فتح المطابقة', 'Reopen reconciliation')}
          onClose={() => setReopenOpen(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setReopenOpen(false)}>{t('cancel')}</Button>
              <Button onClick={() => reopenSession.mutate()} disabled={reopenSession.isPending || reopenReason.trim().length < 3}>
                {L('إعادة فتح', 'Reopen')}
              </Button>
            </>
          }
        >
          {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
          <p className="text-small mb-3 text-text-subtle">
            {L(
              'لا يتغير شيء في الدفاتر. يمكن إعادة فتح أحدث مطابقة معتمدة للحساب فقط.',
              'Nothing changes in the ledger. Only the newest approved reconciliation of the account can be reopened.',
            )}
          </p>
          <Field label={L('السبب', 'Reason')} required>
            <Input value={reopenReason} maxLength={255} onChange={(event) => setReopenReason(event.target.value)} />
          </Field>
        </Modal>
      </div>
    )
  }

  // ------------------------------------------------------------------
  // List + statements
  // ------------------------------------------------------------------
  return (
    <div>
      <PageHeader
        title={L('مطابقة البنك', 'Bank reconciliation')}
        subtitle={L('استيراد كشوف البنك ومطابقة الحركات مع الدفاتر', 'Import bank statements and match them with the ledger')}
        icon="listChecks"
        actions={
          <>
            {manage ? (
              <Button variant="secondary" icon="upload" onClick={() => { setError(''); setNotice(''); setImportOpen(true) }}>
                {L('استيراد كشف', 'Import statement')}
              </Button>
            ) : null}
            {manage ? (
              <Button icon="plus" onClick={() => { setError(''); setNotice(''); setSessionOpen(true) }}>{L('مطابقة جديدة', 'New reconciliation')}</Button>
            ) : null}
          </>
        }
      />

      {error && !importOpen && !sessionOpen ? (
        <p className="mb-4 rounded-12 border border-danger-border bg-danger-background px-3 py-2 text-medium text-danger-text">{error}</p>
      ) : null}
      {notice ? (
        <p className="mb-4 rounded-12 border border-border-subtle bg-surface-subtle px-3 py-2 text-medium text-text-default">{notice}</p>
      ) : null}

      <div className="mb-4 flex gap-2">
        {tabButton('sessions', L('الجلسات', 'Sessions'))}
        {tabButton('statements', L('كشوف البنك', 'Statements'))}
      </div>

      {tab === 'sessions' ? (
        <Card>
          {sessionsQuery.isLoading ? <Loading /> : null}
          {sessionsQuery.isError ? <ErrorState message={errorMessage(sessionsQuery.error)} onRetry={() => void sessionsQuery.refetch()} /> : null}
          {sessionsQuery.data && sessionsQuery.data.length === 0 ? <EmptyState /> : null}
          {sessionsQuery.data && sessionsQuery.data.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الفترة', 'Period')}</Th>
                  <Th>{L('الحساب البنكي', 'Bank account')}</Th>
                  <Th className="text-end">{L('رصيد البنك', 'Bank closing')}</Th>
                  <Th className="text-end">{L('رصيد الدفاتر', 'Book closing')}</Th>
                  <Th>{t('status')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {sessionsQuery.data.map((session) => (
                  <tr key={session.id}>
                    <Td className="tabular">
                      {formatDate(session.period_from, locale)} → {formatDate(session.period_to, locale)}
                    </Td>
                    <Td>{name(session.cash_account)}</Td>
                    <Td className="text-end"><Money amountMinor={session.closing_bank_balance_minor} currency={session.currency} /></Td>
                    <Td className="text-end"><Money amountMinor={session.closing_book_balance_minor} currency={session.currency} /></Td>
                    <Td><Badge tone={statusTone(session.status)}>{session.status === 'approved' ? L('معتمدة', 'Approved') : L('مفتوحة', 'Open')}</Badge></Td>
                    <Td className="text-end">
                      <Button variant="ghost" onClick={() => setSelectedId(session.id)}>{L('فتح', 'Open')}</Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      ) : (
        <Card>
          {statementsQuery.isLoading ? <Loading /> : null}
          {statementsQuery.isError ? <ErrorState message={errorMessage(statementsQuery.error)} onRetry={() => void statementsQuery.refetch()} /> : null}
          {statementsQuery.data && statementsQuery.data.length === 0 ? <EmptyState /> : null}
          {statementsQuery.data && statementsQuery.data.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <Th>{L('الملف', 'File')}</Th>
                  <Th>{L('الفترة', 'Period')}</Th>
                  <Th className="text-end">{L('عدد السطور', 'Lines')}</Th>
                  <Th className="text-end">{L('افتتاحي', 'Opening')}</Th>
                  <Th className="text-end">{L('ختامي', 'Closing')}</Th>
                  <Th>{L('استُورد في', 'Imported')}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {statementsQuery.data.map((statement) => (
                  <tr key={statement.id}>
                    <Td>
                      {statement.filename}
                      <div className="text-small text-text-subtle">{statement.format.toUpperCase()}</div>
                    </Td>
                    <Td className="tabular">
                      {statement.period_from ? formatDate(statement.period_from, locale) : '—'} →{' '}
                      {statement.period_to ? formatDate(statement.period_to, locale) : '—'}
                    </Td>
                    <Td className="text-end tabular">{statement.line_count}</Td>
                    <Td className="text-end">
                      {statement.opening_balance_minor !== null ? (
                        <Money amountMinor={statement.opening_balance_minor} currency={statement.currency} />
                      ) : (
                        '—'
                      )}
                    </Td>
                    <Td className="text-end">
                      {statement.closing_balance_minor !== null ? (
                        <Money amountMinor={statement.closing_balance_minor} currency={statement.currency} />
                      ) : (
                        '—'
                      )}
                    </Td>
                    <Td className="tabular">{formatDateTime(statement.imported_at, locale)}</Td>
                    <Td className="text-end">
                      <Button variant="ghost" onClick={() => setPreviewId(statement.id)}>
                        {L('معاينة', 'Preview')}
                      </Button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : null}
        </Card>
      )}

      <Modal
        open={previewId !== null}
        title={L('معاينة كشف البنك', 'Bank statement preview')}
        onClose={() => setPreviewId(null)}
        footer={
          <Button variant="secondary" onClick={() => setPreviewId(null)}>{L('إغلاق', 'Close')}</Button>
        }
      >
        {statementQuery.isLoading ? <Loading /> : null}
        {statementQuery.isError ? <ErrorState message={errorMessage(statementQuery.error)} /> : null}
        {statementQuery.data ? (
          <div>
            <p className="text-small mb-3 text-text-subtle">
              {statementQuery.data.filename} · {statementQuery.data.line_count} {L('سطر', 'lines')} · {statementQuery.data.currency}
            </p>
            <Table>
              <thead>
                <tr>
                  <Th>{t('date')}</Th>
                  <Th>{t('description')}</Th>
                  <Th>{L('المرجع', 'Reference')}</Th>
                  <Th className="text-end">{t('amount')}</Th>
                  <Th className="text-end">{t('balance')}</Th>
                </tr>
              </thead>
              <tbody>
                {statementQuery.data.lines.map((line) => (
                  <tr key={line.id}>
                    <Td className="tabular">{formatDate(line.line_date, locale)}</Td>
                    <Td>{line.description ?? '—'}</Td>
                    <Td className="tabular">{line.bank_reference ?? '—'}</Td>
                    <Td className="text-end"><Money amountMinor={line.amount_minor} currency={statementQuery.data.currency} /></Td>
                    <Td className="text-end">
                      {line.balance_after_minor !== null ? (
                        <Money amountMinor={line.balance_after_minor} currency={statementQuery.data.currency} />
                      ) : (
                        '—'
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
        ) : null}
      </Modal>

      <Modal
        open={importOpen}
        title={L('استيراد كشف بنكي', 'Import bank statement')}
        onClose={() => setImportOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setImportOpen(false)}>{t('cancel')}</Button>
            <Button onClick={() => importStatement.mutate()} disabled={importStatement.isPending || !importFile}>
              {L('استيراد', 'Import')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('الحساب البنكي', 'Bank account')} required>
            <Select value={importForm.cash_account_id} onChange={(event) => setImportForm({ ...importForm, cash_account_id: event.target.value })}>
              <option value="">—</option>
              {bankOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('الملف (CSV أو XLSX)', 'File (CSV or XLSX)')} required>
            <input
              type="file"
              accept=".csv,.txt,.xlsx"
              className="text-medium w-full rounded-12 border border-border-default bg-surface-default px-3 py-1.5"
              onChange={(event) => setImportFile(event.target.files?.[0] ?? null)}
            />
          </Field>
          <Field label={L('عمود التاريخ', 'Date column')} required>
            <Input value={importForm.date} onChange={(event) => setImportForm({ ...importForm, date: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('صيغة التاريخ', 'Date format')}>
            <Input value={importForm.date_format} onChange={(event) => setImportForm({ ...importForm, date_format: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('عمود الوصف', 'Description column')}>
            <Input value={importForm.description} onChange={(event) => setImportForm({ ...importForm, description: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('عمود المرجع', 'Reference column')}>
            <Input value={importForm.reference} onChange={(event) => setImportForm({ ...importForm, reference: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('عمود المبلغ (موجب = إيداع)', 'Amount column (positive = in)')} hint={L('اتركه فارغًا إذا كان الملف بعمودي إيداع/سحب', 'Leave blank when the file has separate money-in/money-out columns')}>
            <Input value={importForm.amount} onChange={(event) => setImportForm({ ...importForm, amount: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('فاصل الأعمدة', 'Delimiter')}>
            <Input value={importForm.delimiter} maxLength={1} onChange={(event) => setImportForm({ ...importForm, delimiter: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('عمود الإيداع', 'Money in column')}>
            <Input value={importForm.money_in} onChange={(event) => setImportForm({ ...importForm, money_in: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('عمود السحب', 'Money out column')}>
            <Input value={importForm.money_out} onChange={(event) => setImportForm({ ...importForm, money_out: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('الرصيد الافتتاحي', 'Opening balance')}>
            <Input value={importForm.opening_balance_minor} onChange={(event) => setImportForm({ ...importForm, opening_balance_minor: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('الرصيد الختامي', 'Closing balance')}>
            <Input value={importForm.closing_balance_minor} onChange={(event) => setImportForm({ ...importForm, closing_balance_minor: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('من تاريخ', 'Period from')}>
            <Input type="date" value={importForm.period_from} onChange={(event) => setImportForm({ ...importForm, period_from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('إلى تاريخ', 'Period to')}>
            <Input type="date" value={importForm.period_to} onChange={(event) => setImportForm({ ...importForm, period_to: event.target.value })} dir="ltr" />
          </Field>
        </div>
      </Modal>

      <Modal
        open={sessionOpen}
        title={L('مطابقة بنكية جديدة', 'New bank reconciliation')}
        onClose={() => setSessionOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSessionOpen(false)}>{t('cancel')}</Button>
            <Button
              onClick={() => createSession.mutate()}
              disabled={createSession.isPending || !sessionForm.cash_account_id || !sessionForm.period_from || !sessionForm.period_to}
            >
              {L('فتح المطابقة', 'Open reconciliation')}
            </Button>
          </>
        }
      >
        {error ? <p className="mb-3 text-medium text-danger-text">{error}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={L('الحساب البنكي', 'Bank account')} required>
            <Select
              value={sessionForm.cash_account_id}
              onChange={(event) => setSessionForm({ ...sessionForm, cash_account_id: event.target.value, statement_id: '' })}
            >
              <option value="">—</option>
              {bankOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('كشف البنك (اختياري)', 'Statement (optional)')}>
            <Select value={sessionForm.statement_id} onChange={(event) => setSessionForm({ ...sessionForm, statement_id: event.target.value })}>
              <option value="">—</option>
              {statementOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
          </Field>
          <Field label={L('من تاريخ', 'Period from')} required>
            <Input type="date" value={sessionForm.period_from} onChange={(event) => setSessionForm({ ...sessionForm, period_from: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('إلى تاريخ', 'Period to')} required>
            <Input type="date" value={sessionForm.period_to} onChange={(event) => setSessionForm({ ...sessionForm, period_to: event.target.value })} dir="ltr" />
          </Field>
          <Field label={L('رصيد البنك الافتتاحي', 'Opening bank balance')} hint={L('اتركه فارغًا لاستخدام رصيد الكشف', 'Leave blank to use the statement balance')}>
            <Input value={sessionForm.opening_bank_balance_minor} onChange={(event) => setSessionForm({ ...sessionForm, opening_bank_balance_minor: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('رصيد البنك الختامي', 'Closing bank balance')}>
            <Input value={sessionForm.closing_bank_balance_minor} onChange={(event) => setSessionForm({ ...sessionForm, closing_bank_balance_minor: event.target.value })} inputMode="decimal" dir="ltr" />
          </Field>
          <Field label={L('ملاحظات', 'Notes')}>
            <Input value={sessionForm.notes} onChange={(event) => setSessionForm({ ...sessionForm, notes: event.target.value })} />
          </Field>
        </div>
        <p className="text-small mt-3 text-text-subtle">
          {L(
            `الرصيد الافتتاحي للدفاتر يُحسب من القيود المرحّلة قبل بداية الفترة تلقائيًا. التاريخ الافتراضي اليوم ${todayISO()}.`,
            `The book opening balance is derived from posted entries before the period start. Today is ${todayISO()}.`,
          )}
        </p>
      </Modal>
    </div>
  )
}
